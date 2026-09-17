# Zápisník z realizace

## Poznámka k procesu

Na vývoji jsem strávila přibližně devět hodin čistého času. Nejvíc času
zabral úvodní průzkum: chtěla jsem dobře pochopit, co je u tohoto typu dat
potřeba ohlídat v databázi a na serverové straně. S PHP jsem pracovala poprvé
a MySQL jsem do té doby nepoužívala v produkčním provozu, proto pro mě bylo
důležité nevycházet jen z prvního technického návrhu, ale jednotlivá rozhodnutí
si ověřit.

Návrhy AI jsem nechávala nezávisle posoudit dvěma agenty. Kdykoliv jsem narazila
na něco neznámého, nejasného nebo podezřelého, nechala jsem si princip vysvětlit
do hloubky, řešení připomínkovala a doplňovala další fakta a požadavky, které
bylo potřeba zohlednit — například možnost budoucího rozšíření na další území.

Také samotná implementace zabrala významnou část času, protože jsem Codex vedla
k průběžnému a systematickému testování jednotlivých kroků a k ověřování
teoretických předpokladů na menších vzorcích dat. Nejdřív jsem chtěla postavit
pevný základ: spolehlivý import, databázi a dotazy API, na kterých se pak dá
bezpečně stavět zbytek aplikace.

## Rozsah a přístup

Povinné minimum bylo KÚ Jičín a alespoň tři další katastrální území. Nejdřív
jsem proto řešila, zda by rozšíření ze čtyř KÚ na celý okres vyžadovalo jinou
architekturu. Oddělení lehké přehledové vrstvy KÚ od parcelní vrstvy a načítání
parcel podle přiblížení a aktuálního výřezu ukázalo, že základní způsob fungování
může zůstat stejný pro 4 i 240 KÚ. Proto dávalo smysl zpracovat celý okres.
Při posledním ověření celé cesty na skutečných datech (E2E) obsahovala datová
sada 240 KÚ a 272 768 parcel.

Větší rozsah ale nebyl zdarma. Přidal práci kolem průzkumu dat, 240 samostatně
publikovaných ČÚZK archivů, importního procesu, průběžných záznamů pro jednotlivá
KÚ, validace kompletní datové sady, měření výkonu a ověření celé cesty na
skutečných datech.

Místo živých požadavků WFS při každém pohybu mapy jsem zvolila předběžné stažení,
import a lokální verzovanou datovou sadu. Aplikace tak při běhu není závislá na
dostupnosti ČÚZK, ukázka je reprodukovatelná a prostorové dotazy mohu řídit
vlastními indexy a limity. Cenou je náročnější počáteční import a data aktuální
k okamžiku stažení, nikoli v reálném čase. ČÚZK WFS proto používám jako
nezávislou autoritu pro kontrolu správnosti, ne jako zdroj mapy při jejím běhu.

Při návrhu jsem nechtěla mapu, API a dotazy nad aktuálním výřezem pevně svázat
s jedním okresem. Základní tok by mohl posloužit i většímu geografickému
rozsahu, potenciálně včetně sousedních zemí. Současný importní nástroj a datová
pravidla jsou ale postavené na českých datech ČÚZK. Jiná země by potřebovala
vlastní napojení na zdroj a normalizaci dat; teprve nad nimi by mohla znovu
použít stejný princip dotazů nad výřezem, API a mapy. Aplikace dnes zahraniční
katastry nepodporuje.

## Klíčová rozhodnutí

- **MySQL Spatial místo automatického přechodu na PostGIS.** Během úvodního
  průzkumu jsem zvažovala obě varianty. PostGIS nabízí pro některé GIS operace,
  import a práci s CRS bohatší a přirozenější nástroje. MySQL 8.4 Spatial ale
  pokrývá operace, které tato aplikace skutečně potřebuje, a zároveň odpovídá
  technologickému prostředí Viagem, takže nebylo nutné zavádět druhý
  databázový ekosystém. Pozdější problém s vestavěnou transformační cestou
  EPSG:5514 jsem diagnostikovala a opravila při zachování MySQL; netvrdím, že by
  mu jiná databáze automaticky zabránila.
- **Nativní geometrie a indexovatelný dotaz.** Geometrie zůstávají v EPSG:5514
  ve sloupcích omezených SRID a s prostorovým indexem. `MBRIntersects` nejdřív
  rychle předvybere kandidáty podle ohraničujícího obdélníku a `ST_Intersects`
  potom ověří skutečný geometrický průnik. Dražší přesnou kontrolu tak není
  potřeba dělat nad celou datovou sadou. Transformuje se veřejný vstup a pouze
  vybrané výsledky, ne indexovaný sloupec v podmínce dotazu.
- **Bezpečná publikace dat.** Import má průběžný záznam pro každé KÚ a
  samostatnou transakci pro každé území. Nová neaktivní datová sada se zveřejní
  jediným atomickým přepnutím až po validaci celé sady; neúspěšný import
  nepoškodí aktivní datovou verzi.
- **GeoJSON jako první řešení.** GeoJSON pro aktuální výřez zůstal jednodušší
  cestou, protože dosavadní měření neodůvodnila vektorové dlaždice,
  zjednodušování geometrií ani mezipaměť.
- **OpenStreetMap jako podklad.** V úvodním průzkumu jsem zvažovala i Mapy.cz.
  Pro lokální ukázku jsem zvolila OSM v kombinaci s Leafletem: reprodukovatelné
  spuštění bez dalšího klíče k API nebo přihlašovacích údajů; podmínky alternativ
  jsem neměla ověřené. OSM poskytuje jen kontext, autoritativním zdrojem parcel
  zůstává ČÚZK.
- **Malá serverová část bez frameworku.** PHP vrstva zůstává explicitní a
  zaměřená na několik koncových bodů API pouze pro čtení; uživatelskou část
  tvoří čistý JavaScript a Leaflet.
- **Záměrně základní detail parcely.** Bez konkrétního účelu produktu jsem
  nechtěla svévolně rozhodovat, která další katastrální data jsou pro uživatele
  důležitá. Detail proto ukazuje jen parcelní číslo, výměru, KÚ a katastrální
  referenci potřebné pro demonstraci. Pokud by byl známý účel mapy a potřeby
  uživatelů, import, API a detail by šlo rozšířit o další relevantní atributy,
  které zvolený zdroj skutečně poskytuje.

## Strategie výkonu

Datová sada pokrývající celý okres neznamená současně vykreslit všech 272 768
parcel. Při vzdáleném pohledu mapa pracuje s úplnou a omezenou vrstvou 240 KÚ.

Parcelní vrstvu chrání dvě nezávislá pravidla. Pod úrovní přiblížení 17 se
jednotlivé parcely vůbec nenačítají, protože při tak vzdáleném pohledu stačí
přehled KÚ. Od úrovně přiblížení 17 se parcely načítají jen pro aktuální výřez,
ale server stále používá pevný limit: SQL čte `2 000 + 1` řádků a při jeho
překročení vrátí `too_dense` místo neúplné vrstvy. Omezení podle přiblížení brání
zbytečnému detailnímu načítání z dálky, zatímco ochrana podle hustoty chrání
server i prohlížeč před příliš hustým výřezem i při dostatečném přiblížení.

Prostorový index společně s dvoustupňovým `MBRIntersects` → `ST_Intersects`
filtrováním omezuje práci databáze na relevantní kandidáty. Syntetické měření
v Chromu po opravě vykreslilo 1 500 parcel s p95 doby vykreslení v Leafletu
29,1 ms, aniž některá úloha překročila 50 ms. Je to reprodukovatelný doklad
chování uživatelské části a ochranných mechanismů, ne měření složitosti všech
skutečných parcel. Ověření celé cesty na 240 KÚ a 272 768 skutečných parcelách
naopak prověřilo celý tok dat a správnost transformace CRS. Podrobná metodika
a omezení jsou v dokumentu [Výkon](PERFORMANCE.md).

## Chyba, která změnila implementaci

Syntetické a integrační testy byly úspěšné, ale při ručním ověření celé cesty
na skutečných datech jsem si všimla, že parcelní hranice proti mapovému podkladu
vypadají systematicky posunuté. OSM byl užitečný signál, ne autoritativní důkaz.
Proto jsem výsledek porovnala přímo s ČÚZK WFS: 145 kontrolních bodů z pěti
parcel v různých částech okresu.

Nativní uložené souřadnice EPSG:5514 se s ČÚZK shodovaly. Problém tedy nebyl ve
stahování, zpracování, importu ani uložených datech, ale v transformační cestě
MySQL mezi EPSG:5514 a EPSG:4326. Původní cesta vytvářela přibližně
sedmimetrový systematický severovýchodní posun.

Řešením je aplikační SRS `1005514` založené na vhodnější EPSG operaci 5239.
Používá se shodně pro výřez 4326 → dotaz v 5514 i pro uloženou
geometrii 5514 → GeoJSON 4326. Uložené geometrie a prostorový index se
nezměnily, takže nebyla potřeba migrace ani opakovaný import.

Na tomto konkrétním 145bodovém vzorku proti ČÚZK WFS měla geometrie vrácená
veřejným HTTP API průměrnou odchylku přibližně `0.138 m`, p95 `0.204 m` a
maximum `0.222 m`. EPSG pro použitou operaci deklaruje přesnost 1 m; naměřené
hodnoty nejsou obecnou zeměměřickou garancí. Přibyl deterministický regresní
test bez připojení k síti a kontrolní test proti živému ČÚZK WFS.

Hlavní ponaučení pro mě je, že syntetické testy dobře ověřily rozhraní API,
životní cyklus dat a výkon, ale samy nemohly odhalit chybu v reálné geodetické
transformační cestě. Ověření celé cesty na skutečných datech a porovnání
s autoritativním zdrojem poskytly jiný typ důkazu správnosti.

## Co mě překvapilo / co jsem se naučila

- Celý okres znamenal 240 archivů KÚ; bylo nutné ověřit úplnost a konzistenci
  výsledné datové sady.
- Syntetická data umožnila reprodukovatelné testy, ale nemohla plně zachytit
  složitost skutečných parcel ani všechna rizika reálných dat. Problém s CRS
  odhalilo až ověření na skutečných datech.
- Celá cesta `ČÚZK → import → MySQL → API → Leaflet` ukázala problémy,
  které izolované testy nezachytily.

U uživatelského rozhraní jsem už během implementace počítala se základní
přístupností a responzivním chováním: ovládací prvky mají přístupné názvy a lze
je ovládat z klávesnice, zatímco detail se podle šířky mění z bočního panelu na
nemodální spodní panel. Mobilní zobrazení ale nepovažuji za úplně doladěné;
širší škála rozměrů a fyzických zařízení potřebuje další kontrolu.

## Co bych s více časem zlepšila

- Přidala bych vyhledávání podle názvu nebo kódu KÚ, aby uživatel nemusel
  konkrétní území mezi 240 položkami hledat ručně na mapě.
- Dále bych doladila existující omezení pohybu a přiblížení, aby uživatel méně
  snadno odjel do nerelevantního okolí okresu.
- Dopracovala bych mobilní a responzivní rozložení. V jednom mobilním zobrazení
  se tlačítko pro návrat na celý okres překrývá s nápovědou, že je potřeba více
  přiblížit mapu pro zobrazení parcel. Je to místní problém rozložení, ne
  nefunkční mobilní mapa.
- Systematicky bych otestovala tmavý vzhled nastavený systémem macOS, zejména
  kontrast panelů, textu, ovládacích prvků a stavů načítání či chyby.
- V uživatelském rozhraní bych zobrazila, kdy byla aktuální datová sada stažená
  nebo aktualizovaná, aby uživatel věděl, jak čerstvá data právě vidí. Potřebné
  údaje o stažení, dokončení a aktivaci už databáze ukládá.
- Přidala bych plánovanou aktualizaci dat z ČÚZK, například jednou denně. Nová
  neaktivní datová verze by se nejdřív stáhla, připravila, prošla validací
  a teprve potom se atomicky aktivovala; při selhání by současná aktivní datová
  verze zůstala nedotčená.
  Existující model verzovaných datových sad, validace a atomického zveřejnění je
  pro takové rozšíření vhodný základ, ale automatické plánování dnes
  implementované není.
- Syntetické měření je reprodukovatelné, skutečné parcely však mají různě
  složité tvary. Změřila bych proto několik reálných oblastí — například hustší
  městskou oblast a jednodušší venkovské či polní oblasti. Vektorové dlaždice,
  zjednodušování geometrií nebo mezipaměť bych zvažovala až tehdy, pokud by tato
  měření ukázala skutečný problém.

Před termínem odevzdání jsem upřednostnila správnost a reprodukovatelnost
zpracování dat, prostorové vrstvy a API, funkční práci s mapou a měřený výkon
před dalším dolaďováním uživatelského rozhraní.
