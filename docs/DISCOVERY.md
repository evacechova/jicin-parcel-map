# Discovery — historický záznam

> Tento dokument uchovává historii uzavřené discovery fáze, nikoli finální architekturu.
> Obsahuje tehdejší ověřená fakta, rozhodnutí, preferované varianty i otevřené otázky.
>
> Pro současná rozhodnutí jsou závazné finální dokumenty v tomto adresáři, zejména
> [ARCHITECTURE.md](ARCHITECTURE.md) a [DECISIONS.md](DECISIONS.md).
> Starší sekce níže zůstávají discovery historií: varianty PostGIS/GiST/GDAL,
> odložené indexy, širší API payload a starší UI nejsou současným implementačním
> kontraktem. Cesty uvedené v historickém textu jsou vztažené ke kořeni projektu.

---

# 1. Zadání a scope

Cílem je vytvořit webovou aplikaci zobrazující katastrální parcely v okrese Jičín na mapě.

Minimální požadovaný rozsah:

- katastrální území Jičín,
- alespoň 3 další katastrální území v okrese Jičín.

Celý okres Jičín je podle zadání bonus.

Aplikace musí:

- zobrazovat parcely na mapě,
- umožnit kliknutí na parcelu a zobrazení jejích informací,
- zůstat plynulá při zobrazení celého zvoleného rozsahu.

Technické požadavky:

- backend musí být PHP,
- frontendová technologie je volná,
- lokální spuštění je dostačující,
- README musí obsahovat setup/prerequisites,
- zadání pracuje se zdroji ČÚZK a zmiňuje WFS/WMS, RÚIAN a INSPIRE.

---

# 2. Co jsme už rozhodli

## 2.1 Projekt zatím zůstává ve fázi discovery

Architekturu nechceme předčasně zamknout.

Nejdříve:

1. zjistit fakta,
2. prozkoumat dostupné zdroje a možnosti,
3. změřit reálná data,
4. následně udělat technická rozhodnutí.

Finální `AGENTS.md` a architektonickou dokumentaci vytvoříme až po této fázi.

Pro průběžná zjištění používáme tento `docs/DISCOVERY.md`.

---

## 2.2 Lokální databáze je legitimní varianta

Zadání nevyžaduje, aby aplikace při každém požadavku četla data přímo z ČÚZK.

Je tedy možné data z ČÚZK stáhnout/importovat do vlastní databáze a následně obsluhovat aplikaci z lokální DB.

Preferovaný koncept je:

```text
ČÚZK
  ↓
import / synchronizace
  ↓
lokální DB
  ↓
PHP API
  ↓
frontend + mapa
```

Důvod:

- runtime aplikace není závislý na dostupnosti ČÚZK,
- lze optimalizovat DB pro naše dotazy,
- lze lépe kontrolovat výkon,
- lokální demo je reprodukovatelnější.

---

## 2.3 Docker není požadavek

Docker ani Docker Compose nejsou v zadání povinné.

Nebudeme je přidávat pouze kvůli tomu, že jde o běžnou produkční praxi.

Pokud se později ukáže konkrétní technický důvod pro Docker, můžeme ho zvážit, ale není to výchozí požadavek.

---

## 2.4 Architektura nesmí být pevně navázaná pouze na 4 katastrální území

Přidání dalších katastrálních území by mělo být možné bez změny celé architektury.

ČÚZK data jsou přirozeně dostupná po jednotlivých katastrálních územích, takže je vhodné mít katastrální území jako samostatnou datovou jednotku.

Například konceptuálně:

```text
cadastral_territory
    ↓
parcels
```

Přesný model DB zatím není schválený.

---

## 2.5 Detail parcely nebude kopírovat kompletní detail reálné nabídky Viagem

Reálné nabídky Viagem mohou obsahovat například:

- cenu,
- cenu za m²,
- podíl,
- LV,
- popis,
- typ pozemku,
- souřadnice,
- další informace o nemovitosti.

Tyto informace ale nejsou automaticky součástí tohoto assignmentu.

Detail parcely se má řídit především zadáním a dostupnými INSPIRE/ČÚZK daty.

Vlastnické údaje jsou mimo scope.

Nejdříve je potřeba přesně zmapovat:

- které požadované atributy poskytuje ČÚZK přímo,
- které lze odvodit,
- které nejsou dostupné.

---

# 3. ČÚZK – co jsme zjistili

## 3.1 WFS

WFS je online služba poskytující vektorová geografická data.

U parcel lze použít prostorové dotazy, například podle BBOX.

Koncept:

```text
frontend
   ↓
PHP API
   ↓
ČÚZK WFS
   ↓
data
```

WFS je tedy vhodný například pro live získávání dat.

---

## 3.2 Live proxy

Jednou z možností je, že frontend nebude komunikovat s ČÚZK přímo.

Místo toho:

```text
browser
   ↓
PHP API
   ↓
ČÚZK WFS
```

PHP funguje jako proxy.

To znamená, že:

1. přijme požadavek od frontendu,
2. zvaliduje jeho parametry,
3. vytvoří odpovídající WFS požadavek,
4. získá data z ČÚZK,
5. předá je frontendu.

Výhoda:

- frontend není přímo navázaný na API ČÚZK,
- vlastní API lze později změnit tak, aby místo WFS četlo lokální DB.

Nevýhoda:

- každá operace je stále závislá na ČÚZK,
- je potřeba respektovat limity WFS,
- při větším rozsahu může být live varianta pomalejší nebo méně stabilní.

Live proxy zatím není vybraná architektura.

---

## 3.3 Atom

ČÚZK nabízí také předpřipravenou distribuci dat prostřednictvím Atom.

Zjistili jsme:

- data jsou dostupná po jednotlivých katastrálních územích,
- distribuce je ZIP + GML 3.2.1,
- data se generují denně, pokud v daném katastrálním území došlo ke změně,
- existují varianty v S-JTSK a ETRS89,
- jde o veřejnou a bezúplatnou distribuci.

Atom se proto jeví jako potenciálně vhodnější zdroj pro **lokální import** než získávání kompletního datasetu přes live WFS.

To ale ještě není definitivní rozhodnutí.

Je potřeba ověřit praktický import a vhodnost pro konkrétní rozsah projektu.

---

# 4. WFS vs. Atom – důležitá diferenciace

Nejde nutně o rozhodnutí:

> „WFS nebo Atom a jedno z toho zahodíme.“

Mohou mít v architektuře různé role.

Například:

```text
Atom
 ↓
počáteční / aktualizační import
 ↓
lokální DB
 ↓
PHP API
 ↓
frontend
```

zatímco WFS může být využit například:

- pro discovery,
- ověření dat,
- experimenty,
- případně jiný runtime scénář.

Protože zadání explicitně zmiňuje WFS/WMS, je potřeba ověřit, jak přesně jsou tyto služby myšlené v kontextu očekávaného řešení.

Nechceme WFS ignorovat pouze proto, že Atom může být pro import praktičtější.

---

# 5. Aktualizace lokální databáze

Pokud bude vybrána lokální DB, potřebujeme mechanismus importu.

Pro take-home assignment není nutné okamžitě stavět produkční scheduler.

Například může existovat:

```text
php bin/import.php
```

který:

- načte aktuální zdrojová data,
- zpracuje je,
- nahraje je do DB.

Později lze řešit automatizaci.

---

## 5.1 Konzistence během importu

Nechceme aktualizovat produkční dataset řádek po řádku způsobem, který by mohl vést k dočasně nekonzistentnímu zobrazení.

Bezpečnější koncept:

```text
aktivní dataset A
       ↓
import nových dat
       ↓
nový dataset B
       ↓
validace + indexy
       ↓
atomické přepnutí
       ↓
aktivní dataset B
```

Uživatel tak během importu stále pracuje s jednou kompletní verzí dat.

Přesný mechanismus zatím není rozhodnutý.

---

# 6. Rozsah dat

Zvažujeme:

### Varianta A – minimum

Jičín + 3 další katastrální území.

### Varianta B – celý okres Jičín

Celý okres je bonus.

**Nechceme ale rozhodnout pouze podle odhadu.**

Nejdříve potřebujeme zjistit:

- počet katastrálních území,
- počet parcel,
- velikost dat,
- velikost geometrií,
- čas importu,
- náročnost dotazů,
- výkon frontendu.

Teprve podle toho rozhodneme, zda je celý okres realistický.

## 6.1 Naměřeno 14. 9. 2026 – celý okres Jičín

Tato část nahrazuje původní odhad konkrétními čísly z aktuální distribuce
ČÚZK INSPIRE CP v EPSG:5514.

- Oficiální číselník ČÚZK pro okres Jičín (`OKRES_KOD 3604`) obsahuje **240
  katastrálních území**.
- Stáhli jsme všech 240 odpovídajících ZIPů z
  `https://services.cuzk.gov.cz/gml/inspire/cp/epsg-5514/{KU_KOD}.zip` a
  ověřili velikost každého souboru proti HTTP `Content-Length`.
- Celý okres: **117,76 MiB ZIP**, po rozbalení **3 021,12 MiB XML**,
  **272 861 parcel**.
- Největší jedno k. ú. je Jičín (`659541`): **5,31 MiB ZIP**, **138,26 MiB
  XML**, **12 286 parcel**.

Referenční minimum (Jičín + Popovice u Jičína + Moravčice + Hubálov) má:

- **4 katastrální území**,
- **16 486 parcel**,
- **7,02 MiB ZIP** a **181,78 MiB XML**.

### Rozhodnutí vyplývající z měření

Pro **lokální, streamovaný import do spatial DB** je celý okres realistický:
118 MiB ke stažení a 273 tisíc parcel není velký dataset pro PostGIS ani pro
jednorázový import. XML se nesmí rozbalovat a držet celé v paměti; importer
musí každý ZIP zpracovat postupně/streamovaně a do DB ukládat jen potřebné
atributy a geometrii.

Celý okres však neznamená posílat celý dataset do browseru. Na celookresním
zoomu se zobrazují pouze hranice/agregace k. ú.; klikatelné parcelní polygony
se vracejí až na detailním zoomu a výhradně pro aktuální viewport.

---

# 7. Databáze

MySQL jsme předběžně zvažovali jako přirozeného kandidáta, ale není definitivně vybraná pouze proto, že ji používá Viagem.

Je potřeba porovnat:

- MySQL,
- PostgreSQL/PostGIS,

z hlediska konkrétních potřeb tohoto projektu:

- prostorové dotazy,
- spatial indexy,
- výkon BBOX/intersection dotazů,
- práce s geometriemi,
- složitost lokálního setupu.

Pokud PostgreSQL/PostGIS přinese významnou výhodu, má smysl ho použít.

Pokud bude MySQL pro dataset dostačující, jednodušší řešení může být vhodnější.

---

# 8. Mapový podklad

Zadání uvádí například:

- OpenStreetMap,
- Mapy.cz,
- Mapbox.

## Preferovaná jednoduchá varianta

Předběžně preferujeme:

```text
OpenStreetMap + Leaflet
```

Důvody:

- jednoduché použití,
- známý a rozšířený mapový stack,
- Leaflet je vhodný pro jednoduchou interaktivní mapu,
- není nutné přidávat komplexnější mapový stack bez důvodu.

Je ale potřeba ověřit:

- podmínky použití konkrétního OSM tile serveru,
- attribution,
- výkon Leafletu při požadovaném množství parcel.

Mapy.cz a Mapbox zůstávají alternativami, pokud pro ně bude konkrétní důvod.

---

# 9. GeoJSON vs. Vector Tiles

Toto zatím není definitivně rozhodnuté.

## 9.1 Viewport GeoJSON

Frontend požádá backend pouze o parcely v aktuálním viewportu:

```text
browser
   ↓
GET /api/parcels?bbox=...
   ↓
PHP
   ↓
DB
   ↓
GeoJSON
   ↓
browser
```

Pokud viewport obsahuje 600 parcel, odpověď může obsahovat všech 600 parcel v jednom GeoJSON payloadu.

GeoJSON tedy neznamená, že se vždy načítá celý dataset.

---

## 9.2 Vector Tiles

Stejný viewport může být rozdělen do několika prostorových tiles:

```text
┌───────┬───────┐
│   A   │   B   │
├───────┼───────┤
│   C   │   D   │
└───────┴───────┘
```

Browser si stáhne tiles, které potřebuje:

```text
GET /tiles/A
GET /tiles/B
GET /tiles/C
GET /tiles/D
```

Stejných 600 parcel tedy může být rozděleno například mezi několik tiles.

Výhoda se projeví například při posouvání mapy:

```text
původní:
[A][B]
[C][D]

po posunutí:
   [B][E]
   [D][F]
```

Tiles B a D lze znovu použít z cache a stáhnout pouze E a F.

---

## 9.3 Vector Tiles nejsou clustering

Clustering a vector tiles řeší různé problémy.

**Clustering:**

- vizuálně seskupuje objekty,
- například zobrazí jeden bod s počtem objektů.

**Vector tiles:**

- rozdělují geografická data do prostorových částí pro efektivní doručování a práci mapy.

Vector tile tedy není „terčík s počtem parcel“.

---

# 10. Již učiněné rozhodnutí ohledně tiles

**Nebudeme automaticky implementovat vector tiles jen proto, že jsou technicky pokročilejší.**

Nejdříve chceme benchmarkovat jednodušší viewport-based GeoJSON.

To je zvolený **první experiment**, nikoli definitivní rozhodnutí, že výsledná aplikace musí používat GeoJSON.

Důvod:

- assignment požaduje plynulost,
- nevíme zatím, jak velký bude skutečný dataset,
- vector tiles přidávají implementační složitost,
- pokud GeoJSON splní požadavky, tiles by mohly být zbytečný overkill.

---

# 11. Benchmark

Benchmark má být hlavním podkladem pro rozhodnutí o delivery dat.

Budeme měřit zejména:

- počet parcel,
- velikost datasetu,
- velikost GeoJSON response,
- čas DB dotazu,
- čas PHP/API requestu,
- přenesený objem dat,
- čas vykreslení v browseru,
- chování při zoomování,
- chování při posouvání,
- chování při zobrazení celého požadovaného rozsahu.

Pokud jednoduchý viewport GeoJSON bude dostatečně rychlý:

```text
→ GeoJSON pravděpodobně stačí.
```

Pokud bude výkon nedostatečný:

```text
→ zvážit vector tiles
→ případně další optimalizace
```

Tím se technické rozhodnutí opírá o reálná data místo předpokladů.

---

# 12. Co zatím nechceme dělat

Dokud nebude discovery dokončená:

- nechceme předčasně zamykat finální architekturu,
- nechceme automaticky zavádět Docker,
- nechceme automaticky zavádět vector tiles,
- nechceme kopírovat kompletní detail reálných Viagem nabídek,
- nechceme pevně omezit datový model pouze na čtyři katastrální území,
- nechceme vybírat databázi pouze podle toho, co používá Viagem,
- nechceme rozhodovat mezi WFS a Atom bez ověření jejich praktické vhodnosti.

---

# 13. Otevřené otázky pro discovery

## Data

- Jak velký je celý okres Jičín?
- Kolik obsahuje parcel?
- Jak velká jsou data a geometrie?
- Jak přesně funguje aktualizace Atom dat?
- Jaké jsou praktické limity WFS pro požadovaný rozsah?

## Import

- Je Atom nejvhodnější zdroj pro lokální import?
- Má smysl používat WFS i pro import?
- Lze spolehlivě detekovat změny před importem?
- Jak nejjednodušeji zajistit konzistenci datasetu během importu?

## Databáze

- Je MySQL dostatečné?
- Přináší PostgreSQL/PostGIS významnou výhodu?
- Jaký je výkon prostorových dotazů na reálném datasetu?

## Frontend / mapa

- Je Leaflet dostatečně výkonný?
- Je OpenStreetMap vhodný mapový podklad pro lokální demo?
- Jaký konkrétní tile server použít a jaké jsou jeho podmínky?

## Data delivery

- Stačí viewport-based GeoJSON?
- Pokud ne, je potřeba vector tiles?
- Je potřeba zjednodušování geometrií nebo různé úrovně detailu podle zoomu?

## Parcel detail

- Které atributy z INSPIRE skutečně potřebujeme zobrazovat?
- Které atributy jsou přímo dostupné a které je nutné odvodit?
- Jak vytvořit případný odkaz na Nahlížení do KN?

---

# 14. Další krok

Nejbližší krok je dokončit discovery a provést benchmark na skutečných datech.

Po jeho dokončení:

```text
DISCOVERY
   ↓
benchmark
   ↓
technická rozhodnutí
   ↓
ARCHITECTURE
   ↓
implementace
```

Teprve po technických rozhodnutích vytvoříme finální kontextové dokumenty a `AGENTS.md`.

## Výsledek dnešního hledání

Ověřili jsme, že ČÚZK poskytuje parcelní data prostřednictvím:

- **INSPIRE CP Atom feedu:** `https://atom.cuzk.cz/getservicefeed.ashx?service=CP`
- **GML distribuce v CRS EPSG:5514:** `https://services.cuzk.gov.cz/gml/inspire/cp/epsg-5514`
- **GML distribuce v CRS EPSG:4258:** `https://services.cuzk.gov.cz/gml/inspire/cp/epsg-4258`

Data jsou dostupná po jednotlivých katastrálních územích, jako ZIP archivy obsahující GML.

Původně chybějící objemová čísla již byla doměřena v sekci 6.1.

---

# 15. Technický podklad pro vybraný scope (14. 9. 2026)

## 15.1 Stav rozhodnutí

- **Rozhodnuto:** scope je celý okres Jičín (240 KÚ / 272 861 parcel), data budou
  lokální a parcelní geometrie se budou doručovat jen pro detailní viewport.
- **Doporučeno (po srovnání v sekci 16):** MySQL 8.0.32+ Spatial, import po
  jednotlivých KÚ do nového datasetu a PHP API s viewportovým GeoJSON.
- **Otevřená otázka:** konkrétní zoom threshold, limit parcel a případná
  simplifikace.
- **Nutné ověřit benchmarkem:** reálná latence dotazu a renderování; vector
  tiles se nyní neimplementují.

## 15.2 Spatial databáze

### MySQL Spatial

MySQL 8 umí pro tento use case `GEOMETRY NOT NULL SRID 5514`, R-tree
`SPATIAL INDEX` a `ST_Intersects`. Pro 273 tisíc polygonů tedy **technicky
stačí**, pokud se geometrii přiřadí explicitní SRID; jinak optimizer spatial
index nepoužije. Udržet zdrojové EPSG:5514 je správné, protože jde o lokální
metrický souřadnicový systém a BBOX dotazy nemusí transformovat všech 273 tisíc
řádků.

Nevýhoda není samotný BBOX dotaz, ale celková GIS pipeline: import
schema-driven GML, validace/geometrické operace, generalizace a export GeoJSON
mají v PostGIS přirozenější nástroje. Pro převod 5514 -> 4326 je také nutné
hlídat konkrétní verzi MySQL: obecná podpora EPSG transformací je až v MySQL
8.0.32+.

### PostgreSQL + PostGIS

PostGIS používá GiST (R-tree nad bounding boxy) a index-aware predikáty.
`ST_Intersects` s GiST indexem zahrnuje BBOX pre-filter; explicitní `&&`
v dotazu jej dělá čitelným a chrání plán dotazu. Nabízí přímo `ST_MakeEnvelope`,
`ST_Transform`, `ST_AsGeoJSON`, validaci a případně bezpečnější simplifikaci
geometrie. GDAL má zároveň GML/GMLAS i PostgreSQL driver; GMLAS zpracovává
arbitrárně velké schema-driven GML streamově s malou spotřebou RAM.

### Volba

- **Původní předběžné doporučení:** PostGIS byl zvolen před zohledněním stacku
  Viagem a před detailním srovnáním konkrétních nutných funkcí. Sekce 16 jej
  nahrazuje doporučením MySQL 8.0.32+ Spatial.
- **Rozhodnuto:** zatím žádná databáze fyzicky neinstalujeme ani nekonfigurujeme.
- **Nutné ověřit benchmarkem:** finální query plan (`EXPLAIN ANALYZE`) a čas
  importu na cílovém lokálním stroji.

Relevantní dokumentace: [PostGIS spatial indexes](https://postgis.net/documentation/faq/spatial-indexes/),
[PostGIS ST_Intersects](https://postgis.net/docs/en/ST_Intersects.html),
[MySQL spatial index optimization](https://dev.mysql.com/doc/refman/8.0/en/spatial-index-optimization.html),
[MySQL ST_Transform](https://dev.mysql.com/doc/refman/8.0/en/spatial-operator-functions.html).

## 15.3 Minimální model

Model obsahuje verzovaný snapshot, aby API vždy četlo jeden úplný import.
Názvy jsou návrh, nikoli již implementované schema.

```text
dataset
  id, source_name, source_crs, imported_at, source_date, status

cadastral_territory
  dataset_id, ku_code, name, inspire_local_id,
  geom_5514, reference_point_5514, begin_lifespan_version

parcel
  dataset_id, inspire_local_id, ku_code, label, national_reference,
  area_m2, geom_5514, reference_point_5514,
  valid_from, begin_lifespan_version

active_dataset
  singleton: dataset_id
```

`parcel` má primární klíč například `(dataset_id, inspire_local_id)`, běžný
B-tree index na `(dataset_id, ku_code)` a GiST index na `geom_5514`.
`cadastral_territory.geom_5514` také dostane GiST index. V PostGIS je vhodné
ukládat normalizovanou `geometry(MultiPolygon, 5514)`; importer převede případný
Polygon na MultiPolygon.

| Atribut | Původ |
| --- | --- |
| Kód a název KÚ, hranice KÚ | přímo `CP.CadastralZoning` (`nationalCadastalZoningReference`, `label`/`name`, `geometry`) |
| Stabilní identifikátor parcely | přímo `CP.CadastralParcel.inspireId.localId` |
| Parcelní číslo pro UI | přímo `label`; `nationalCadastralReference` je také přímo a obsahuje kód KÚ + označení |
| Geometrie parcely | přímo `geometry` v EPSG:5514 |
| Výměra | přímo `areaValue` v m²; není třeba počítat z geometrie |
| Referenční bod | přímo `referencePoint`; lze i odvodit z geometrie, ale zdrojový bod preferujeme |
| Platnost / verze zdroje | přímo `validFrom`, `beginLifespanVersion`, `endLifespanVersion`; některé hodnoty mohou být prázdné |
| Druh pozemku, způsob využití, LV, vlastník, cena | v základním INSPIRE CP **nejsou dostupné**; ownership je mimo scope |

Na reálném souboru Jičín je ověřeno, že ZIP obsahuje jak `CP.CadastralParcel`,
tak `CP.CadastralZoning`; hranici KÚ proto nebudeme skládat z parcel.

## 15.4 Návrh importu

1. Stáhnout manifest 240 KÚ a založit dataset ve stavu `importing`.
2. Každý ZIP zpracovat **samostatně**, s checkpointem `KU_KOD`, počtem přečtených
   parcel, checksumem/velikostí, stavem a chybou. Chybu jednoho KÚ nelze potichu
   ignorovat; dataset se nesmí aktivovat, dokud není kompletní.
3. ZIP číst jako stream a GML parsovat iterativně (SAX/XMLReader nebo ověřeným
   GDAL GMLAS). Z každého `CadastralParcel` uložit jen pole z modelu, nikoli
   celý XML strom; geometrii převést na WKB/parametr pro PostGIS.
4. Importovat dávkově do stagingu nového `dataset_id`. Nezapisovat do aktivních
   tabulek ani nemazat aktivní snapshot. Nejdříve provést strukturální a
   doménové kontroly (240 KÚ, očekávané počty, SRID 5514, neprázdná geometrie,
   unikátnost identifikátorů).
5. Bulk-load provést bez GiST indexu na rozpracované parcelní sadě a index
   vytvořit až po importu; průběžná údržba indexu by import zbytečně brzdila.
   Poté `ANALYZE` a ověřit plán typického viewportového dotazu.
6. Po úspěšné validaci v krátké DB transakci přepnout jediný odkaz
   `active_dataset.dataset_id`. Starý dataset ponechat pro rollback a odstranit
   až explicitním, odděleným úklidem.

- **Doporučeno:** GMLAS/GDAL jako první kandidát na importní nástroj; jeho
  dokumentace výslovně uvádí streaming s malou pamětí. PHP XMLReader je
  přijatelná alternativa, pokud chceme závislost na GDAL vynechat, ale znamená
  vlastní převod GML geometrií a více rizik.
- **Otevřená otázka:** zda finální importér bude PHP CLI + XMLReader, nebo
  samostatný GDAL krok spouštěný dokumentovaným skriptem.
- **Rozhodnuto:** žádný importér nyní neimplementujeme.

Dokumentace: [GDAL GML](https://gdal.org/en/stable/drivers/vector/gml.html),
[GDAL GMLAS](https://gdal.org/en/stable/drivers/vector/gmlas.html),
[GDAL PostgreSQL driver](https://gdal.org/en/stable/drivers/vector/pg.html).

## 15.5 PHP API a viewport

Navržený kontrakt:

```text
GET /api/parcels?bbox=minLng,minLat,maxLng,maxLat&zoom=z
GET /api/parcels/{inspire_local_id}
GET /api/cadastral-territories?bbox=...&zoom=z
```

Frontend posílá BBOX v EPSG:4326, protože tak jej poskytuje mapa. PHP přísně
validuje čtyři konečná čísla, jejich pořadí, povolený rozsah, zoom a maximální
plochu viewportu. V DB se BBOX jako konstanta převede do EPSG:5514; parcelní
dotaz pak ve zjednodušené podobě vypadá takto:

```sql
WHERE p.dataset_id = :active_dataset
  AND p.geom_5514 && :bbox_5514
  AND ST_Intersects(p.geom_5514, :bbox_5514)
ORDER BY p.inspire_local_id
LIMIT :hard_limit_plus_one
```

`&&` používá GiST index jako levný BBOX pre-filter; `ST_Intersects` dává přesný
výsledek na kandidátech. Vybrané řádky se až poté transformují do 4326 a vrátí
jako GeoJSON FeatureCollection (`id`, parcelní číslo, KÚ, výměra, geometry).
Transformace tedy neblokuje indexový výběr.

Při malém zoomu endpoint parcel vůbec nespouštět: zobrazit `cadastral_territory`
hranice. Při detailu zavést tvrdý limit (počáteční experiment 2 000 features),
ale při překročení vrátit `409 too_dense` jako API guardrail bez částečné
odpovědi. Normální mapa tiše zachová/obnoví KÚ fallback; frontend se řídí
stabilním `error.code`, ne instrukcí v textu chyby. Nikdy nevracet oříznutý,
tiše neúplný seznam parcel jako platnou úplnou odpověď.

- **Doporučeno:** výchozí design byl později zpřesněn na zoom 17 jako levný
  filtr; 2 000 zůstává pouze neověřený hard safety ceiling.
- **Nutné ověřit benchmarkem:** oba prahy; správná hodnota se určí podle hustoty
  Jičína a maximální velikosti odpovědi, ne pocitem.

## 15.6 Viewport GeoJSON vs. vector tiles

Vstupních 3,02 GiB XML je velikost **importu**, ne síťová odpověď mapy. Při
viewportovém GeoJSON browser dostane jen vybrané parcely v aktuálním BBOX a
výstup bude limitovaný. GeoJSON je jednoduchý na PHP API, debugging i kliknutí
na feature.

Vector tiles by přidaly předgenerování/servírování tiles, zoom-dependentní
generalizaci, cache invalidaci a vlastní cestu pro detail parcely. Jsou vhodné,
pokud benchmark ukáže, že ani limitovaný GeoJSON nezvládá pan/zoom nebo pokud
je vyžadováno plynulé parcelní zobrazení na velké ploše. Tyto podmínky zatím
nejsou prokázané.

- **Rozhodnuto:** nyní začít návrhem viewportového GeoJSON; vector tiles
  neimplementovat.
- **Doporučeno:** navrhnout API tak, aby šlo později přidat `/tiles/{z}/{x}/{y}`
  vedle detail endpointu bez změny datového modelu.
- **Nutné ověřit benchmarkem:** zda GeoJSON limit, cache a případná
  zoom-dependentní simplifikace stačí.

## 15.7 Benchmark po implementaci

Měřit pro několik reprezentativních BBOX (centrum Jičína, řídká krajina,
hranice několika KÚ) a pro pan/zoom scénář v Chrome i Firefoxu:

| Metrika | Proč |
| --- | --- |
| Počet kandidátů a vrácených parcel; aktivoval se limit? | určí limit a zoom práh |
| `EXPLAIN ANALYZE`: plán, GiST využití, DB latency p50/p95 | ověří spatial query a index |
| PHP čas zvlášť pro validaci, DB, serializaci a transformaci | najde API bottleneck |
| HTTP velikost odpovědi (raw i gzip), TTFB a celkový transfer | určí rozumný payload |
| Čas parsování GeoJSON a čas renderu/mapové interakce | ověří browser, ne jen DB |
| Počet souběžných/zrušených requestů při pan/zoom | ověří debounce a AbortController |
| Paměť browseru a FPS/long tasks při opakovaném posunu | odhalí kumulování vrstev |
| Čas plného importu, max RAM, velikost DB a indexů | ověří reprodukovatelnost setupu |
| Čas validace a atomického přepnutí datasetu | ověří bezpečnost aktualizace |

**Finální architekturu uzavřeme až nad těmito měřeními.**

---

# 16. Rozhodovací srovnání: MySQL 8 Spatial vs. PostGIS pro tento assignment

## 16.1 Rozsah, podle kterého hodnotíme

Neřešíme obecný GIS produkt. Nutná cesta tohoto assignmentu je jen:

```text
ČÚZK ZIP/GML -> stream import -> 273k parcel v EPSG:5514
-> spatial viewport query -> transformace vybraných feature do 4326
-> limitovaný GeoJSON -> PHP/Leaflet
```

Nejsou požadovány overlay analýzy, routování, prostorové joiny nad cizími
vrstvami, editace parcel ani předgenerované vector tiles. To zásadně snižuje
hodnotu širší GIS funkcionality PostGIS.

### Závazné předpoklady pro MySQL variantu

1. Použijeme **MySQL 8.0.32 nebo novější** a InnoDB. Starší MySQL 8 neumí
   obecně transformovat projektované EPSG systémy; pro 5514 -> 4326 by tak
   vznikla zbytečná externí závislost.
2. Před importem ověříme, že registr SRS dané instalace obsahuje EPSG:5514 a
   že `ST_Transform(geom_5514, 4326)` nad kontrolní geometrií funguje.
3. Sloupec bude `GEOMETRY NOT NULL SRID 5514` (v praxi preferovaně
   `MULTIPOLYGON ... SRID 5514`) se `SPATIAL INDEX`. Bez SRID restriction
   MySQL optimizer spatial index nepoužije.

Tyto tři kontroly patří do README/import preflightu. Jsou malou, explicitní
verzní podmínkou, nikoli důvodem přidávat PostGIS.

## 16.2 Krok za krokem

| Krok | MySQL 8 Spatial | Dopad pro tento assignment |
| --- | --- | --- |
| 1. ČÚZK INSPIRE CP GML ze ZIPů | **Možné, ale složitější.** GDAL má MySQL driver pro čtení i zápis spatial dat, stejně jako PostGIS driver. Přímý GDAL MySQL driver ale není transakční a zapisuje geometrie jako WKT; pro náš snapshot proto nepoužít jako jedinou garanci konzistence. | GML parsing je problém zdroje, ne databáze. Importovat po KÚ do stagingu přes vlastní stream/PHP nebo řízený GDAL krok; atomické přepnutí zůstává běžná DB operace. PostGIS je zde pohodlnější, nikoli nutný. |
| 2. Streamování 3,02 GiB XML | **Bez problému v MySQL.** XMLReader/SAX nebo GMLAS pracují mimo DB; RAM určuje parser a velikost dávky. | Nutné. Volba DB prakticky nemění řešení. |
| 3. Uložení 273k parcel v EPSG:5514 | **Bez problému v MySQL.** InnoDB umí SRID-restricted spatial sloupec pro kartézský SRS. | Nutné. 273k řádků není pro relační DB velký objem. Vyžaduje jen preflight EPSG:5514. |
| 4. Spatial index | **Bez problému v MySQL.** `SPATIAL INDEX` je R-tree nad MBR a funguje pro `NOT NULL` sloupec s explicitním SRID. | Nutné. Ekvivalent GiST/R-tree v PostGIS pro náš dotaz. |
| 5. BBOX + přesný viewport `ST_Intersects` | **Bez problému v MySQL.** Použít dvoufázově `MBRIntersects(geom, :bbox_5514)` jako indexový pre-filter a `ST_Intersects(geom, :bbox_5514)` jako přesnou podmínku. | Nutné. Je to přímý ekvivalent postgisového `&&` + `ST_Intersects`; syntaxe je jiná, implementační složitost zanedbatelná. Ověřit `EXPLAIN` benchmarkem. |
| 6. EPSG:5514 -> EPSG:4326 | **Bez problému v MySQL**, pokud platí verze 8.0.32+. `ST_Transform` pak podporuje EPSG SRS obecně. | Nutné pro standardní GeoJSON/Leaflet. Toto je jediný reálný verzní háček MySQL varianty; nelze ho ignorovat. Transformovat pouze již vybrané parcely, nikdy sloupec v podmínce. |
| 7. GeoJSON export | **Bez problému v MySQL.** `ST_AsGeoJSON(ST_Transform(geom, 4326))` vrátí geometrii; PHP doplní `Feature` a `FeatureCollection` i atributy. | Nutné. PostGIS umí vytvořit celý Feature record jedním voláním, ale rozdíl je pár řádků serializace v PHP, ne riziko. |
| 8. Validace geometrií | **Bez problému v MySQL.** `ST_Validate`/`ST_IsValid` umí ověřit kartézskou geometrii; nevalidní feature je důvod selhat staging import, ne ji potichu opravit. | Doporučená ochrana importu, ne feature UI. PostGIS nabízí bohatší diagnostiku a `ST_MakeValid`, což zde nepotřebujeme: zdroj je autoritativní ČÚZK a automatická oprava by mohla změnit data. |
| 9. Simplifikace/generalizace | **Významné riziko jen tehdy, když ji budeme potřebovat.** MySQL `ST_Simplify` může vytvořit nevalidní geometrii; nemá ekvivalent PostGIS `ST_SimplifyPreserveTopology`. | **Nice-to-have, ne nutnost.** První verze místo ní používá zoom threshold + limit výsledků. Kdyby benchmark dokázal potřebu trvalé topologicky bezpečné generalizace, je to dobrý důvod přehodnotit PostGIS nebo předgenerovat samostatnou vrstvu. |
| 10. PHP API | **Bez problému v MySQL.** PHP PDO MySQL, prepared statements, validace BBOX, limit a GeoJSON odpověď jsou standardní. | Nutné. Zároveň přímo ukáže schopnost práce se stackem Viagem. |
| 11. Lokální setup | **Bez problému v MySQL; pravděpodobně jednodušší v kontextu zadání.** Nepřidáváme druhý databázový ekosystém a využíváme technologii firmy. GDAL je případná importní závislost v obou variantách. | Nutné. Přesná verze MySQL musí být v prerequisites, aby byl CRS převod reprodukovatelný. |

Podklady: [MySQL SRID a spatial index](https://dev.mysql.com/doc/refman/8.0/en/spatial-type-overview.html),
[MySQL optimalizace spatial indexu](https://dev.mysql.com/doc/refman/8.0/en/spatial-index-optimization.html),
[MySQL transformace](https://dev.mysql.com/doc/refman/8.0/en/spatial-operator-functions.html),
[MySQL validace a simplifikace](https://dev.mysql.com/doc/refman/8.0/en/spatial-convenience-functions.html),
[GDAL MySQL driver](https://gdal.org/en/stable/drivers/vector/mysql.html).

## 16.3 Co skutečně ztrácíme bez PostGIS

| Funkce PostGIS | Potřeba nyní? | Rozhodnutí |
| --- | --- | --- |
| GiST/R-tree BBOX pre-filter a přesný průnik | Ano | MySQL poskytuje funkční ekvivalent (`SPATIAL INDEX` + `MBRIntersects` + `ST_Intersects`). |
| Uložení EPSG:5514 a transformace vybraných parcel | Ano | MySQL 8.0.32+ poskytuje ekvivalent. |
| GeoJSON geometrie | Ano | MySQL poskytuje ekvivalent; FeatureCollection složí PHP. |
| Detailní diagnostika a opravy nevalidních geometrií | Ne | Validovat a při chybě zastavit import; data neopravovat. |
| Topology-preserving simplifikace / coverage generalizace | Ne v první verzi | Vyloučit z první implementace, rozhodnout až benchmarkem. |
| Pokročilé prostorové analýzy a joiny | Ne | Mimo zadání. |
| Přímý, velmi komfortní GMLAS -> PostGIS bulk-load | Ne jako požadavek | Pohodlí pro implementátora, ne uživatelská vlastnost; MySQL import potřebuje disciplinovanější staging cestu. |

## 16.4 Reálné riziko a jeho mitigace

Použití MySQL nezvyšuje významně riziko runtime mapy. Největší rozdíl je
**importní ergonomie**, nikoli BBOX výkon či API: PostGIS je pro GDAL/GIS
nástroje přirozenější. To je u jednorázového, dokumentovaného take-home importu
přijatelný trade-off.

Riziko držíme nízké těmito pravidly:

- nevybírat „libovolné MySQL 8“, ale pevně MySQL 8.0.32+;
- v preflightu ověřit EPSG:5514, `ST_Transform`, SRID sloupce a plán indexového
  BBOX dotazu;
- importovat do staging datasetu a aktivní dataset přepnout jedinou transakcí;
- nepoužívat v první verzi `ST_Simplify`; na nízkém zoomu vracet KÚ hranice,
  ne parcely;
- použít limit a explicitní `too_dense`, aby jedna odpověď nikdy neobsahovala
  desítky tisíc polygonů.

**Nutné ověřit benchmarkem:** zda MySQL skutečně použije `SPATIAL INDEX` pro
zvolený `MBRIntersects` + `ST_Intersects` plán a zda payload/render limitu
vyhoví Chrome i Firefoxu. To je stejné ověření, které bychom dělali v PostGIS.

## 16.5 Doporučení

**Pro tento assignment bych zvolil MySQL 8.0.32+ Spatial, protože pro všechny nutné kroky poskytuje dostatečné a indexované prostorové funkce, přitom věrohodně navazuje na stack Viagem; jediná výrazná výhoda PostGIS — topologicky bezpečná generalizace a pohodlnější GIS import — není v první verzi zadání nutná.**

### Jak to obhájit na pohovoru

„Nezvolila jsem MySQL jen proto, že ho používáte. Nejdřív jsem změřila dataset
(273 tisíc parcel), ověřila podporu SRID-restricted R-tree indexu, přesného
viewportového průniku, transformace 5514 -> 4326 a GeoJSON v konkrétní verzi
MySQL. PostGIS by mi dal širší GIS toolbox, ale pro tento produkt by přidal
druhý databázový ekosystém hlavně kvůli funkcím, které nevyužiji. Rizika MySQL
jsem nezamlčela: verzi 8.0.32+ vyžaduji, CRS a index ověřuji preflightem a
simplifikaci odkládám, dokud ji benchmark neprokáže. Pokud by benchmark ukázal
potřebu topologicky bezpečné generalizace nebo složitějších GIS analýz, umím
odůvodnit přechod na PostGIS.“

---

# 17. Předimplementační rozhodnutí: UX, CRS, Slovensko a aktualizace

## 17.1 UI/UX mapové aplikace

### Navržené rozložení

```text
┌───────────────────────────────────────────────────────────────────────┐
│ Viagem Parcely · Okres Jičín       data: 14. 9. 2026 · [Hranice KÚ]   │
├───────────────────────────────────────────────────────┬───────────────┤
│                                                       │ Detail parcely│
│                       MAPA                            │               │
│   [−]                                                  │ číslo parcely │
│   [+]          hranice / vybraná parcela               │ výměra        │
│                                                       │ KÚ            │
│   „Přibližte mapu pro zobrazení parcel.“               │ zdroj / stav  │
│                                                       │ [Zavřít]      │
├───────────────────────────────────────────────────────┴───────────────┤
│ © OpenStreetMap · ČÚZK INSPIRE CP · souřadnice / zoom                 │
└───────────────────────────────────────────────────────────────────────┘
```

Desktop je mapový canvas s pevnou, ale sbalitelnou pravou detailní lištou
(přibližně 320–380 px). Horní lišta je krátká: název, aktivní území/datum
snapshotu a jediný přepínač vrstev („hranice KÚ“). Základní zoom a attribution
ponecháme standardní mapové komponentě. Nezavádíme dashboard, seznam parcel,
marketingové hero ani filtry, které zadání nepožaduje.

Panel detailu se otevře až po kliknutí na parcelu a obsahuje jen údaje, které
opravdu máme: parcelní číslo, KÚ, výměru, národní referenci a datum/snapshot
zdroje. Nemá předstírat cenu, druh pozemku ani vlastnictví. Vybraná parcela má
zřetelné, ale střídmé zvýraznění; ostatní parcely zůstávají čitelné.

Na mobilu zůstane mapa přes celou obrazovku. Detail se otevře jako spodní sheet
s tažením/uzavřením; v zavřeném stavu zabírá jen jeden řádek s parcelním číslem.
Tím mapa nepřijde o hlavní plochu a ovládací prvky se nebudou překrývat.

### Stavy

| Stav | Chování rozhraní |
| --- | --- |
| První načítání | mapa a hranice KÚ se zobrazí co nejdřív; v mapě je nenápadný loading indikátor, panel detailu je zavřený |
| Pan/zoom fetch | předchozí parcely zůstávají vidět do příchodu nové odpovědi; probíhající request lze zrušit při dalším pohybu |
| Zoom příliš malý | parcely se vůbec nevyžadují; jsou vidět hranice KÚ a případně nenápadná nápověda k detailnímu zobrazení |
| `too_dense` / příliš velký viewport | žádná neúplná parcelní vrstva; tiše ponechat/obnovit KÚ reprezentaci a další viewport zkusí parcely znovu |
| Prázdný detailní viewport | zachovat mapu a zobrazit stručné „V tomto výřezu nejsou parcely k zobrazení“ |
| Chyba datového requestu | zachovat poslední platná data, zobrazit text chyby a jedno tlačítko „Zkusit znovu“ |
| Chyba mapového podkladu | nezaměňovat ji s chybou parcel; parcelní vrstva i panel mohou dál fungovat nad neutrálním pozadím |

- **DECIDED:** mapa je dominantní plocha; detail je kontextový panel, nikoli
  samostatná stránka. Nízký zoom zobrazuje KÚ hranice, ne parcely.
- **RECOMMENDED:** desktop right sidebar + mobile bottom sheet, krátká horní
  lišta a ponechání posledních úspěšně načtených parcel při dalším fetchi.
- **OPEN:** zda přidat vyhledání parcelního čísla. Není nutné pro zadání a bez
  ověření očekávání ho nyní nepřidáváme.
- **TO BENCHMARK:** čitelnost hranic a vybrané parcely nad konkrétním OSM
  podkladem; chování Leafletu při opakovaném přepisu vrstvy.

## 17.2 CRS a runtime výkon

Tok zůstává:

```text
BBOX z mapy (EPSG:4326)
  -> jednorázově převést BBOX do EPSG:5514
  -> MBRIntersects + ST_Intersects nad indexovaným geom v 5514
  -> LIMIT / too_dense
  -> převést jen vybrané geometrie 5514 -> 4326
  -> ST_AsGeoJSON -> browser
```

Transformace není prováděna nad 272 861 řádky a nesmí být uvnitř podmínky
`WHERE` nad indexovaným sloupcem. Její cena roste s počtem vrácených vertexů,
ne s velikostí celého okresu. Proto je pro limitovaný detailní viewport
rozumná; bez běžící cílové DB ale nelze poctivě tvrdit konkrétní milisekundy.
Nejpravděpodobnějším limitem bude velikost GeoJSON a browser rendering, nikoli
samotná transformace několika stovek až nízkých tisíců vybraných polygonů.

Benchmark provede pro stejné BBOX alespoň tři varianty: (A) indexový výběr bez
serializace, (B) výběr + `ST_Transform`, (C) výběr + transformace +
`ST_AsGeoJSON`. Každou měřit cold/warm a p50/p95; uložit počet parcel, počet
vertexů, DB čas, PHP serializační čas, gzip/raw velikost response a render čas.
Právě rozdíl A→B izoluje cenu transformace.

- **DECIDED:** primární uložená geometrie zůstává v zdrojovém EPSG:5514,
  frontend dostává standardní GeoJSON v EPSG:4326.
- **RECOMMENDED:** runtime transformace pouze po indexovém výběru je pro tento
  assignment správný a jednodušší než duplikovat 273 tisíc geometrií.
- **OPEN:** zda cacheovat již transformovaný GeoJSON pro časté viewporty; bez
  naměřeného bottlenecku jej nezavádět.
- **TO BENCHMARK:** A/B/C rozdíl, zejména hustý střed Jičína a největší povolený
  payload. MySQL 8.0.32+ je závazná podmínka transformace.

## 17.3 Budoucí Slovensko a CRS

Současný český zdroj je v EPSG:5514 nebo EPSG:4258. Slovenský GKÚ uvádí pro
hranice katastru také **S-JTSK / Křovák East North (EPSG:5514)**. Slovenská
správa současně používá novější **S-JTSK[JTSK03] / Křovák East North
(EPSG:8353)** a ETRS89 (EPSG:4258); pro web je relevantní také WGS84/4326 a
Web Mercator/3857. Slovensko tedy může často používat stejný 5514, ale nesmí se
na to spoléhat jako na univerzální pravidlo.

Logický model proto od teď označuje geometrii jako `geom_native` a nese na
datasetu/zdroji `country_code`, `provider`, `native_srid`, `display_srid=4326`
a transform policy. Stávající fyzická česká tabulka může zůstat
SRID-restricted na 5514; to je žádoucí pro MySQL spatial index.

Do jediné MySQL tabulky ale nemícháme různé native SRID: pro index musí být
sloupec SRID-restricted. Při reálném rozšíření bude každý stát/CRS vlastní
fyzický dataset/table (např. `parcel_cz_5514`, `parcel_sk_8353`) se stejným
logickým kontraktem. PHP podle země vybere povolený dataset a provede BBOX query
v jeho `native_srid`; na API hraně vždy vrátí 4326. Alternativou je při importu
vše normalizovat do jednoho CRS, ale to nyní nepřináší výhodu a zhoršilo by
lokální metrickou přesnost/transparentnost zdroje.

- **DECIDED:** nebudeme ukládat různé CRS do jednoho neomezeného geometry
  sloupce; tím bychom přišli o MySQL spatial-index optimalizaci.
- **RECOMMENDED:** odlišit v návrhu `native_srid` od API `display_srid` a
  držet fyzické datasety po státu/CRS. Současný Jičín tím není slepá ulička.
- **OPEN:** konkrétní slovenský poskytovatel, licenční podmínky a datový model;
  nejsou součástí tohoto assignmentu.
- **TO BENCHMARK:** až při skutečném SK scope ověřit přesnost a dostupnost
  transformace 8353 -> 4326 v požadované MySQL/PROJ konfiguraci.

Zdroje: [GKÚ: katastrální data v EPSG:5514](https://www.skgeodesy.sk/gku/produkty-sluzby/na-stiahnutie/kataster-nehnutelnosti.html),
[GKÚ: používané CRS v SR](https://www.skgeodesy.sk/files/gku/produkty-sluzby/na-stiahnutie/s-jtsk_jtsk03_v_qgis.pdf),
[ČÚZK CP CRS](https://services.cuzk.gov.cz/doc/inspire-view-download-eng.pdf).

## 17.4 Aktualizace dat ČÚZK

ČÚZK uvádí, že předpřipravené CP Atom datové sady vznikají **denně, ale jen
pokud došlo ke změně v konkrétním KÚ**. Kontrola nových verzí jednou denně tedy
dává v produkci smysl; plná denní reimportace všech 240 KÚ nikoli.

### Take-home: co skutečně vytvoříme

- jeden úplný, reprodukovatelný import celého okresu a `dataset` metadata
  (datum, zdroj, CRS, počty KÚ/parcel);
- manuálně spustitelný, dokumentovaný full-refresh příkaz: postaví nový staging
  snapshot, provede validaci a atomicky přepne `active_dataset`;
- žádný scheduler, background worker, automatický polling ani public endpoint
  pro aktualizace.

To pro lokální demo stačí: data jsou verzovaná a proces lze zopakovat, ale
nepředstíráme provozní synchronizaci.

### Production rozšíření: denní inkrementální sync

1. Naplánovaný job stáhne CP Atom feed/manifest a pro každý KÚ porovná `updated`
   hodnotu a/nebo ETag/Last-Modified/obsahový checksum s tabulkou
   `territory_source_state`.
2. Jen změněné ZIPy stáhne do dočasného úložiště, po jednom streamově rozparsuje
   do staging tabulek a ověří (celý soubor, SRID, unikátnost, nenulový počet a
   očekávaný KÚ kód). Selhání ponechá aktivní data beze změny a vyvolá alert.
3. V jedné krátké DB transakci pro každý úspěšný balík změn nahradí v aktivním
   snapshotu pouze parcely a hranici dotčených KÚ: delete staré řádky dotčených
   KÚ, insert z validovaného stagingu, aktualizace jejich source-state a datum
   datasetu. API v defaultní transakční izolaci pak vidí buď starou, nebo novou
   kompletní sadu dotčených KÚ, nikdy mezistav.
4. Při změně parseru/schématu, příliš mnoha změnách nebo plánovaném rebase se
   použije bezpečnější full-refresh: nový dataset/tables, indexy a validace,
   potom atomické přepnutí `active_dataset` (případně atomický `RENAME TABLE`).
   Starý snapshot se drží pro rollback.

`beginLifespanVersion` v samotných CP features je vhodná auditní informace, ale
není náhradou za manifest/ETag: job musí zjistit změněný ZIP dřív, než jej
stáhne a rozparsuje.

- **DECIDED:** staging + validace + atomické přepnutí zůstává invariant; API
  nesmí číst napůl importovaná data.
- **RECOMMENDED:** pro produkci denní manifest check a inkrementální náhrada jen
  změněných KÚ; full rebuild jako fallback.
- **OPEN:** přesný Atom field/HTTP metadata, které použijeme jako change token;
  vybereme při implementaci po ověření konkrétního feedu a jeho HTTP odpovědí.
- **TO BENCHMARK:** čas full refresh, čas výměny několika KÚ, vliv na spatial
  index a chování souběžných API dotazů.

Zdroje: [ČÚZK Atom CP](https://atom2.cuzk.gov.cz/),
[ČÚZK metadata CP](https://geoportal.cuzk.gov.cz/%28S%28jhrfndawiypx1uyacjgudtbv%29%29/Default.aspx?metadataID=CZ-00025712-CUZK_SERIES-MD_CP&metadataXSL=full&mode=TextMeta&side=katastr).

## 18. Technická validace databázového modelu před implementací

### Co se skutečně potvrdilo

Model jednoho logického snapshotu `dataset`, sdílených tabulek a ukazatele
`active_dataset` je pro 240 KÚ / 272 861 parcel validní. InnoDB umí složený
FK `parcel(dataset_id, territory_id)` na
`cadastral_territory(dataset_id, id)`, pokud mají oba páry shodné unsigned
datové typy a rodič má index začínající právě `(dataset_id, id)`. Globální
`AUTO_INCREMENT` identifikátory nevadí: identita řádku je technický klíč,
zatímco identita parcel v dané verzi zdroje je `UNIQUE(dataset_id, inspire_id)`.

V aktuálním jičínském snapshotu je přesně 240 `CadastralZoning` a 272 861
`CadastralParcel` feature začátků; obě sady `gml:id` jsou unikátní. Důležitá
nuance: ČÚZK neposílá parcelu již jako `MultiPolygon`, ale jako `gml:Polygon`,
zatímco KÚ je `gml:MultiSurface`. Parser proto obě hodnoty normalizuje do
`MULTIPOLYGON SRID 5514`; díky tomu zůstává databázový typ konzistentní a
zachová i případné díry polygonu.

Z parcelního GML jsou pro model reálně dostupné přesně `areaValue` (s
`uom="m2"`), `beginLifespanVersion`, `geometry`, `inspireId/localId`, `label`,
`nationalCadastralReference`, `referencePoint` a `validFrom`. `validFrom` může
být `xsi:nil`, takže je nullable. `beginLifespanVersion` je verze prvku ve
zdroji, ne čas našeho importu. U KÚ je obdoba `inspireId`, `label`, hranice a
zdrojový identifikátor; zdrojový tag je skutečně historicky napsán
`nationalCadastalZoningReference` (s „Cadastal“), proto si náš DB název
volíme srozumitelně, ale parser mapuje přesný tag zdroje.

### Spatial SQL jednoduše

MySQL spatial index má smysl jen pro `NOT NULL` geometry sloupec s explicitním
SRID; `MULTIPOLYGON NOT NULL SRID 5514` + `SPATIAL INDEX` tedy odpovídá tomuto
požadavku. BBOX z Leafletu nejdříve striktně validujeme (4 konečná čísla,
pořadí, rozsah lon/lat, maximální rozsah, žádné překročení antimeridiánu), pak
jej vytvoříme jako WKT s explicitním `axis-order=long-lat` a jednou převedeme
do 5514.

`MBRIntersects(parcel.geom_native, viewport)` je rychlý kandidátní filtr přes
obdélníky, pro který může optimizer použít R-tree. `ST_Intersects` pak provede
přesnou topologickou kontrolu a odstraní kandidáty, jejichž bounding box se
sice dotýká, ale skutečný polygon ne. `LIMIT + 1` nevrací potají neúplná data:
nad limitem API vrátí `409 too_dense` a mapa bez user-facing chyby ponechá KÚ
jako smysluplnou reprezentaci.

`ST_MakeEnvelope` zde nepoužijeme pro konstrukci geografického 4326 polygonu,
protože MySQL má pro geografické SRS omezení. Výstupní transformace
`ST_Transform(geom_native, 4326)` je dostupná od MySQL 8.0.32 pro náš Křovák
projekt, ale před plným importem ji ověříme na cílové DB spolu s osovým pořadím
výsledného `ST_AsGeoJSON` (`[longitude, latitude]`). To je malý povinný
preflight, nikoli důvod ukládat duplicitní 4326 geometrii.

### Verdikt po bodech

| Část návrhu | Verdikt | Co to znamená |
| --- | --- | --- |
| MySQL datové typy, SRID a constrainty | ✅ ponechat | `MULTIPOLYGON/POINT ... SRID 5514`, InnoDB FK i `CHECK` jsou v cílové řadě platné. |
| Atributy a unikátnost ČÚZK | ✅ ponechat | Potvrzeno nad celým aktuálním snapshotem; `validFrom` ponechat nullable. |
| `dataset` + `active_dataset` + rollback | ✅ ponechat | Krátká transakce publikace a výběr jen `ready` datasetu chrání API před mezistavem. |
| Složený FK | ✅ ponechat | Vyžaduje explicitní rodičovský index `(dataset_id, id)` a shodné typy. |
| BBOX: MBR + exact predicate | ✅ ponechat | Je to vhodná indexová a přesná dvojice; konkrétní plán musí potvrdit `EXPLAIN ANALYZE`. |
| Transformace a GeoJSON | ⚠️ upravit o preflight | Provést transformaci až po výběru, ale otestovat 5514→4326 a výstupní pořadí os na skutečném serveru. |
| Index při importu | ⚠️ upřesnit | Ve sdílené tabulce jej během importu neshazovat, protože slouží aktivnímu snapshotu; změřit cenu udržování indexu. |
| Fyzicky oddělené active/staging tabulky | ❌ nyní nepoužívat | Pro tento rozsah jen zdvojují schéma a komplikují změny. `dataset_id` poskytuje stejnou publikační izolaci. |

- **DECIDED:** datový model, native EPSG:5514, spatial index i atomická
  publikace zůstávají beze změny.
- **RECOMMENDED:** držet aktivní snapshot a jeden rollback; starší retired
  snapshoty řízeně čistit až po ověření retenčního pravidla.
- **OPEN:** žádný funkční blokér. Konkrétní hard limit a zoom threshold jsou UX
  parametry, nikoli součást integrity modelu.
- **TO BENCHMARK:** `EXPLAIN ANALYZE` indexového dotazu; odděleně selection,
  `ST_Transform` a `ST_AsGeoJSON`; cold/warm p50/p95, kandidáty, počet vertexů,
  payload, PHP response a render mapy. Varovné je full scan pro běžný detailní
  viewport, vysoký p95 při malém limitovaném výsledku nebo viditelný lag mapy;
  teprve pak měníme SQL/index/payload a až následně zvažujeme vector tiles.

## 19. Návrh importu ČÚZK: proč přesně takto

### Základní princip

Import není „aktualizace tabulky parcel“. Je to sestavení **nového úplného
snapshotu**, který zatím není veřejný: `dataset = importing`. Až když máme
všech 240 KÚ, ověřená data a žádnou chybu, jednou krátkou transakcí přepneme
`active_dataset`. Aplikace tedy buď čte předchozí hotový okres, nebo nový hotový
okres — nikdy KÚ 1–136 z nové verze a zbytek ze staré.

Pro assignment nebude importer při každém spuštění vyhledávat okres ve velkém
adresáři ČÚZK. Máme pevnou, verzovanou konfiguraci scope `jicin` s 240 kódy KÚ
a stabilní URL šablonou `.../epsg-5514/<ku-code>.zip`. Uživatel spouští pouze
`php bin/import-cadastral.php --scope=jicin`; nemůže omylem změnit CRS, URL ani
importovat náhodný výběr KÚ. To je jednodušší a lépe reprodukovatelné než malá
napodobenina produkčního scheduleru.

### Proč XMLReader a dva průchody

Celý okres má po rozbalení asi 3,02 GiB XML, proto DOM/SimpleXML nad celým
souborem nepřipadá v úvahu. `XMLReader` prochází tokeny postupně; v paměti je
jen rozpracovaný prvek a jeho geometrie, nikoli předchozí parcely ani celý GML.
ZIP se čte streamově přes `ZipArchive`, takže ani celý okres nerozbalujeme na
disk.

V každém ZIPu čteme GML dvakrát. První průchod najde a vloží jediný
`CadastralZoning` (KÚ), druhý vloží jeho parcely. Alternativa „spoléhat, že KÚ
je v XML před parcelami“ by byla kratší, ale křehká. Alternativa „parcely si
odložit, dokud nepřijde KÚ“ by porušila paměťový limit. Dvojnásobné čtení
jednoho ZIPu je zde levnější než složitost a riziko obou alternativ.

### Geometrie a inserty

`gml:Polygon` parcely zabalíme do jednoprvkového WKT `MULTIPOLYGON`; hranici
KÚ z `gml:MultiSurface` převedeme na stejný typ. Zachováme exterior i interior
rings, souřadnice validujeme jako EPSG:5514 a do MySQL je předáme přes bound
parametr `ST_GeomFromText(..., 5514)`. WKT je zde praktičtější než ručně vyrábět
WKB; není to API formát ani dlouhodobá duplicitní reprezentace.

Jedno KÚ zpracujeme v jedné DB transakci: vložit KÚ, pak malé dávky parcel a
commit. Selže-li cokoli, rollback smaže jen rozpracované KÚ. Indexy aktivních
tabulek během toho **neshazujeme**, protože je využívá právě běžící API.

### Selhání, retry a co schválně neděláme

Dočasný síťový download dostane nejvýše dvě opakování. Poškozený ZIP, špatné
GML, chybějící povinný atribut, nepodporovaná geometrie nebo DB chyba jsou
tvrdé selhání: KÚ se rollbackne, dataset dostane `failed`, import skončí a
aktivní data zůstanou beze změny. Pád procesu v KÚ 137 je stejný bezpečný stav:
nový dataset zůstane `importing`, ale API na něj neukazuje.

Resume napůl importovaného datasetu pro assignment nepřidáváme. Vyžadovalo by
to rozhodnout, zda znovu stažený KÚ smí mít novější verzi než KÚ importované
před pádem. Nový čistý full refresh je levnější a srozumitelnější. Produkce by
mohla mít manifest token, download cache, lock, scheduler a resume; nyní je to
vědomě mimo scope.

### Kontrola před publikací

Musí existovat přesně 240 úspěšných KÚ, jeden uložený `CadastralZoning` pro
každý z nich, součet počtů parcel ze checkpointů se musí rovnat počtu řádků,
nesmí chybět geometrie ani FK vazba a všechny geometrie musí být neprázdné,
validní a v SRID 5514. Číslo 272 861 si uložíme jako výsledek dosavadního
snapshotu, ale není to hard gate — ČÚZK data se mohou mezi dny změnit.

- **DECIDED:** full refresh se statickým Jičín scope, XMLReader, dvěma průchody
  na KÚ, transakcí na KÚ, fail-fast a atomickou publikací.
- **RECOMMENDED:** artefakty držet jen po dobu běhu; pro debug je lze ponechat
  přepínačem. Uchovat vždy URL, checksum, čas a počty v DB.
- **OPEN:** konkrétní rozumná velikost insert batch (začneme malá) a přesný limit
  velikosti jednoho geometry feature.
- **TO BENCHMARK:** čas dvou průchodů, rychlost single-row versus malé batch
  inserty, importní RAM, velikost indexů a největší skutečná geometrie.

## 20. Upřesnění: typ parcelní geometrie a retry importu

### Proč parcela zůstává `MULTIPOLYGON`

Aktuální jičínské GML skutečně posílá každou `CadastralParcel` jako
`gml:Polygon`. To samo o sobě ale není garance zdrojového kontraktu. Závazná
INSPIRE specifikace pro `CadastralParcel` připouští `GM_Surface` **nebo**
`GM_MultiSurface`; naopak `CadastralZoning` je `GM_MultiSurface`. V GML je
jednoduchý surface běžně `gml:Polygon` a multi-surface `gml:MultiSurface`.

Proto `POLYGON SRID 5514` u parcel nepoužijeme: aktuální data by fungovala,
ale legitimní multi-surface parcela by způsobila selhání importu. Ponecháme
`MULTIPOLYGON SRID 5514` pro obě tabulky. Zabalení polygonu do
jednoprvkového multipolygonu nemění souřadnice, plochu, díry ani topologii;
jen dává DB jeden stabilní typ. `GEOMETRY` by sice přijal oba tvary bez převodu,
ale zbytečně oslabuje datový kontrakt — aplikace ví, že pracuje s plošnými
objekty, a přesně to má DB chránit.

### Retry není resume

Retry řeší krátký výpadek **v jednom právě běžícím importu**, ne pokračování
starého snapshotu. Pro download jednoho ZIPu budou nejvýše tři pokusy:
okamžitě, za 1 s a za 3 s. Retryable jsou timeout/connection reset/TLS síťová
chyba a HTTP `408`, `429`, `5xx`; `Retry-After` se respektuje nejvýše do 30 s.

Neretryujeme `404` a jiné běžné `4xx`, poškozený ZIP, nesprávný obsah ZIPu,
nevalidní GML, chybějící povinné atributy, nepodporovanou či nevalidní geometrii
ani jakoukoli DB/constraint chybu. Tyto chyby jsou v kontextu stejného vstupu
deterministické; další pokus by pouze zakryl první příčinu.

Každý pokus zapisuje do vlastního dočasného `*.part` souboru. Neúplný soubor se
před dalším pokusem smaže a nikdy se neparsuje; plně stažený ZIP se ověří a
teprve potom atomicky přejmenuje. `import_territory` si pamatuje URL, počet
pokusů, čas posledního pokusu, případný HTTP status, stabilní error code,
krátkou bezpečnou zprávu, a při úspěchu checksum a počet parcel.

Po posledním transientním nebo prvním permanentním selhání se KÚ označí
`failed`, nový `dataset` také `failed` a import skončí. Další ruční spuštění
vždy vytvoří nový čistý dataset — starý failed dataset se nepokračuje a
`active_dataset` zůstává nedotčený.

- **DECIDED:** parcelní `MULTIPOLYGON` a retry pouze pro transientní download
  (max. 3 pokusy, 0/1/3 s).
- **RECOMMENDED:** ukládat do `import_territory` strojově čitelný `error_code`
  vedle lidsky čitelné bezpečné zprávy.
- **OPEN:** žádný blokér; velikost případného `Retry-After` je záměrně omezena
  na 30 s, aby lokální příkaz nevisel bez vysvětlení.
- **TO BENCHMARK:** nic funkčního — retry cesta se ověří malým testem proti
  simulovanému timeoutu, nikoli nad celým okresem.

Zdroje: [INSPIRE Cadastral Parcels Technical Guidelines (2024)](https://knowledge-base.inspire.ec.europa.eu/publications/inspire-data-specification-cadastral-parcels-technical-guidelines_en),
[závazný geometrický constraint v nařízení EU](https://eur-lex.europa.eu/eli/reg/2010/1089/2014-12-31/eng),
[ČÚZK popis národního CP rozšíření](https://services.cuzk.gov.cz/doc/inspire-cpx-data.pdf).

## 21. API design a request lifecycle mapy

### Malé API, které odpovídá skutečné mapě

Potřebujeme jen tři read-only endpointy:

| Endpoint | Proč existuje |
| --- | --- |
| `GET /api/v1/cadastral-territories?bbox=…` | Lehká, smysluplná vrstva KÚ pro přehledovou mapu. |
| `GET /api/v1/parcels?bbox=…&zoom=…` | Jen aktuální detailní viewport s kompletními polygonovými geometriemi. |
| `GET /api/v1/parcels/{inspireId}` | Zdrojová metadata kliknuté parcely; geometrii už klient má. |

Není zde search, write API, export, účet ani endpoint pro import. Každý další
endpoint by v assignmentu zvětšil testovací a bezpečnostní plochu bez přínosu
pro mapový scénář.

`/meta` nepřidáváme. Aktivní snapshot mapa nepotřebuje znát a minimální zoom je
malá build-time frontend konstanta, kterou API nezávisle vynucuje. Endpoint by
byl oprávněný až tehdy, kdyby UI opravdu zobrazovalo datum importu nebo se
konfigurace musela měnit bez nasazení frontendu.

### BBOX: stejný jazyk mezi Leafletem, PHP a MySQL

Leaflet pracuje pro web v EPSG:4326, a proto posílá čtyři hodnoty v pořadí
`minLng,minLat,maxLng,maxLat`. PHP nejdřív ověří, že jde o čtyři konečná čísla
ve správných rozsazích, se správným pořadím a rozumnou maximální velikostí.
Teprve z ověřených čísel složí WKT polygon s explicitním `axis-order=long-lat`.

Tento **jeden** viewport polygon se v MySQL převede na 5514. `MBRIntersects`
nad sloupcem se spatial indexem rychle najde obdélníkové kandidáty;
`ST_Intersects` následně zkontroluje skutečný tvar polygonu. Až poté se vybrané
parcely převedou zpět na 4326 a serializují do GeoJSON. Index tedy nepoškodíme
transformací `parcel.geom_native` uvnitř `WHERE` a netransformujeme celý okres.

### Level of detail: KÚ, ne clustery parcel

Počáteční hranice je Leaflet zoom 17:

- pod 17 jsou hranice KÚ, případně tooltip se jménem a počtem parcel;
- od 17 frontend **zkusí** parcelní polygony jen v aktuálním viewportu;
- když response nepřesáhne limit, polygonová vrstva se aktivuje;
- `409 too_dense` nechá či obnoví KÚ bez chybové hlášky a další mapový pohyb
  request zkusí znovu. Je to poslední bezpečnostní síť, ne stav pro uživatele.

KÚ je zdrojová, administrativně srozumitelná a omezená vrstva (240 prvků).
Clustering reprezentativních bodů parcel by vyžadoval novou agregaci 272 tisíc
bodů a uživatele by vedl ke clusteru, přestože jeho cílem je vybrat polygon.
Grid/hex agregace má podobný problém. Zoom 17 a limit 2 000 jsou zatím
konfigurovatelné hypotézy; změříme 16/17/18 v hustém i venkovském výřezu.

### Pohyb mapy bez závodů odpovědí

Request neposíláme na každou pixelovou událost `move`, ale po dokončení pohybu
na `moveend`; krátký debounce 150 ms spojí například inicializační události.
Při nové změně frontend zruší předchozí fetch přes `AbortController` a zvýší
čítač generace requestu. I kdyby síť odpověděla po zrušení, response se smí
vykreslit jen tehdy, když její generace odpovídá právě aktuálnímu viewportu.

Během načítání zůstane poslední platná vrstva viditelná, jen utlumená; nová
vrstva ji nahradí najednou. Po úspěšném parcelním response se KÚ fill skryje,
ale hranice zůstane jako kontext. Při `too_dense` se parcelní overlay odstraní
a KÚ fill se vrátí bez toastu. Abort je normální stav bez chybové hlášky.
Prázdná odpověď je validní empty state. Síťová nebo serverová chyba ponechá
poslední použitelnou reprezentaci, ukáže krátké „Zkusit znovu“ a nikdy
neukazuje interní detail chyby. KÚ vrstvu držíme po celou relaci jako užitečný
fallback; cache parcelních BBOX zatím nepřidáváme.

### Detail parcely a chyby

Viewport feature nese jen GeoJSON `id` (= `inspire_id`) a `label`, tedy přesně
co mapa potřebuje pro kreslení a kliknutí. Po kliknutí se samostatně dotáhnou
metadata z ČÚZK: INSPIRE ID, label, národní reference, výměra a KÚ. Reálný
Jičín GML skutečně obsahuje `areaValue`, `nationalCadastralReference`,
`beginLifespanVersion`, `referencePoint` a často explicitně prázdné
`validFrom`. Poslední tři jsou technická/auditní metadata bez běžné hodnoty
pro uživatele této mapy; uložíme je, ale detail je nezobrazuje. Nezobrazujeme
vlastnictví, cenu, druh pozemku ani jiné nevydané údaje.

`404 parcel_not_found` není výpadek služby: zrušíme selection a vysvětlíme, že
parcela už v aktivním datasetu není. `400` znamená chybu klienta a nere-tryuje
se; `422` vrátí mapu do low-detail režimu; `409 too_dense` tiše ponechá KÚ a
další pohyb mapy jej zkusí znovu; `503`, `500` a síťová chyba jsou retryable
uživatelským tlačítkem. Frontend rozhoduje podle stabilního `error.code`, nikdy
podle textu `message`. Všechny HTTP chyby mají stejnou obálku `error.code`,
`error.message`, `error.requestId`.

### Přiměřená ochrana veřejného API

Nutné: allowlist rout a metod, striktní validace BBOX/ID/zoom, limity,
prepared statements, serverem vlastněné SRID/SQL, JSON jako textové hodnoty
bez vkládání HTML a import pouze přes CLI. `.env` je necommitované a frontend
neobsahuje DB údaje ani pseudotajný API key.

V developmentu povolíme CORS jen přesně nakonfigurovanému Vite originu pro
`GET`/`OPTIONS`, bez credentials a bez wildcard. V produkci je API same-origin.
Rate limit nebudeme předstírat jako nespolehlivý čítač v PHP procesu; při veřejné
produkci patří do reverse proxy/gateway. Path traversal zde nevzniká, protože
route nevybírá soubory ani nepřijímá cesty.

- **DECIDED:** tři GET endpointy, GeoJSON 4326, startovací parcel zoom 17 jako
  levný filtr, limit 2 000 jako guardrail, trvalá KÚ fallback vrstva a
  `moveend`/AbortController/generation ochrana.
- **RECOMMENDED:** bez parcelního BBOX cache a bez parcelního clusteringu; KÚ
  jméno/počet parcel zobrazovat spíše jako tooltip než všudypřítomné popisky.
- **OPEN:** žádný funkční blokér. Konkrétní tolerance maximálního parcelního
  BBOX bude konfigurační konstanta zvolená s prvním benchmarkem.
- **TO BENCHMARK:** počet parcel, kandidátů a vertexů při z16/z17/z18;
  query/transform/GeoJSON/API čas, raw/gzip payload, abort/stale rate,
  browser render a plynulost pan/zoom. Pokud běžná zoom-gated navigace příliš
  často aktivuje KÚ fallback, nejdřív upravíme zoom/BBOX/limit a teprve potom
  payload, SQL nebo vector tiles.

## 22. Performance: limit feature není UX target

Počáteční API strop 2 000 parcel chrání server a browser před neomezeným
response, ale **netvrdí**, že Leaflet pohodlně vykreslí 2 000 parcel. Dva
viewporty se stejným počtem features mohou mít radikálně jiný počet vertexů,
velikost GeoJSON a čas kreslení. Proto při benchmarku vždy měříme společně:
feature count, celkový počet vertexů, raw/gzip payload, DB query,
`ST_Transform` + GeoJSON serializaci, přenos, JSON parsing, Leaflet render a
reakci při pan/zoom.

Scénáře poběží nad hustým městem, venkovem, hranicí KÚ, near/over safety
ceiling a zoomy 16/17/18. Každý ověříme pro desktop/laptop, tablet i mobile
viewport a pokud to půjde, na reprezentativně silnějším i slabším zařízení.
To **neznamená** device-specific limity: mobil obvykle žádá menší výřez, ale
zároveň může mít slabší rendering, takže správné rozhodnutí musí vyjít z dat.

Benchmark smí změnit zoom threshold, hard feature ceiling, maximální BBOX a
v případě prokázané potřeby doplnit guardrail podle geometrické/payload
složitosti. Samotný jeden špatný test není důvod pro clustering nebo vector
tiles; ty přicházejí na řadu jen tehdy, když jednoduchý GeoJSON tok měřitelně
selže i po úpravě těchto základních parametrů.

`beginLifespanVersion` přitom uchováváme pro provenance a audit: vyjadřuje
lifespan/verzi geografického objektu ve zdroji, ne datum vzniku reálné parcely
ani čas našeho importu. Proto není v API ani UI, ale patří do databázového
modelu pro budoucí porovnání verzí zdroje.

- **DECIDED:** 2 000 je jen neověřený hard safety ceiling; žádná device-specific
  pravidla ani optimalizace se zatím nepřidávají.
- **TO BENCHMARK:** celý feature/vertex/payload/DB/browser řetězec přes device
  a viewport matrix; výsledky mohou upravit limity, BBOX, threshold nebo
  případně delivery/rendering strategii.

## 23. UI/UX: mapa je produkt, detail je kontext

První verze nezačíná dashboardem ani hledáním, ale přehledovou mapou celého
okresu. KÚ hranice dávají okamžitou orientaci; kliknutí na KÚ jej označí a
bez dalšího dialogu provede `fitBounds` s paddingem 32 px a `maxZoom` navázaným
na aktuální (zatím hypotetický) parcelní LOD threshold. Teprve běžný `moveend`
tok rozhodne, zda aktuální detailní viewport bezpečně získá parcelní polygony.

KÚ není jen low-zoom nouzový stav. Zůstává jako stabilní kontext i když jsou
parcely aktivní: při úspěchu se schová jeho fill, hranice zůstávají; při
`too_dense` se fill tiše vrátí. Uživatel tak nikdy nemá pocit prázdné nebo
rozbité mapy a nemusí rozumět internímu API guardrailu.

Mentální model navigace je záměrně dvojí a nepromíchává se: ruční Leaflet
wheel/trackpad/pinch/double-click zoom a drag posun navigují mapou; tlačítko
„Celý okres“ vrátí district fit. KÚ je skutečné území, ne cluster, a klik/tap
znamená navigaci/přiblížení. Klik/tap parcely znamená selection + detail.
Následný `moveend` po obou způsobech navigace spouští stejný LOD/request tok.

Parcelní feature má jen `id` a label. Kliknutí ji hned označí, potom detail
načte výměru, KÚ a národní referenci. Detail neukazuje technické INSPIRE
metadata. Na desktopu je pravý panel, na tabletu/mobilu non-modální bottom
sheet; rozložení se řídí šířkou, ne detekcí zařízení. Viditelné tlačítko close
je vždy dostupné, drag handle by byl jen enhancement.

Minimální controls jsou zoom, „Celý okres“, scale a povinná attribution.
Nevkládáme search, filtry, login, dashboard, vlastní design system, clustering,
legendu nebo efektní animace — žádná z nich zatím neřeší uživatelský problém
assignmentu. Basemap je standardní OSM raster přes Leaflet: keyless a známý
pro demo, ale policy-bound a bez produkční SLA.

- **DECIDED:** map-first flow, KÚ click/fitBounds, kontextový detail, persistentní
  KÚ fallback, sidebar pro široký viewport a bottom sheet pro ostatní.
- **RECOMMENDED:** permanentní KÚ labels nezobrazovat; jméno a parcel count jsou
  sekundární tooltip/popup. Zachovat mapu při loadingu/chybě, neblokovat ji
  spinnerem.
- **OPEN:** pouze drobné implementační ověření — kontrast vrstev, padding/max
  zoom fitBounds, skutečná výška/focus bottom sheetu a hodnota pasivní low-zoom
  nápovědy.

Zdroje basemapy: [OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/),
[OSM attribution and licence](https://www.openstreetmap.org/copyright).

## 24. Testing strategy: malé testy na skutečná rizika

Testing pyramid není žebříček důležitosti, ale způsob, jak rychle ověřit co
nejvíce levných pravidel a jen několik dražších integračních hran. Použijeme
PHPUnit pro PHP, Vitest + jsdom pro naši frontend state logiku a Playwright pro
dva browser scénáře. Nejde o volbu velkého frameworku: každý nástroj je běžný,
odpovídá vrstvě a nepřidává runtime aplikaci.

Čisté PHPUnit testy pokryjí BBOX, zoom/ID validaci a error envelope hlavně na
hranicích, nikoli PDO/MySQL. Parser dostane malé GML/ZIP fixture odvozené z
reálné CP struktury: KÚ, Polygon/MultiSurface, díru, nullable metadata a chyby.
Živé ČÚZK se v testech nepoužije, aby běh nebyl závislý na internetu ani denní
změně zdroje.

Spatial SQL ale **nemockujeme**. Samostatné `viagem_test` MySQL schema dostane
několik deterministických polygonů a dva datasety. Ověří SRID 5514, spatial
index/schema, MBR versus přesný průnik, 5514→4326, GeoJSON `[lng,lat]` a active
snapshot izolaci. To je correctness preflight. `EXPLAIN ANALYZE`, latence,
celý okres a Leaflet capacity jsou naopak benchmark, ne test, protože závisí
na stroji a velikosti dat.

Frontend testuje naši LOD/request coordinator logiku, ne Leaflet: threshold,
KÚ fallback, AbortController, stale response, selection/detail a zachování
poslední reprezentace při chybě. Playwright ověří jen happy path a `too_dense`
fallback; tile requesty se stubnou, takže E2E nemá závislost na OSM.

Manuálně před odevzdáním projdeme responsive layout, touch, focus, 44px targety,
reduced motion, kontrast nad skutečným OSM, fitBounds malého KÚ, loading bez
flickeru a attribution. Vědomě netestujeme interní Leaflet/MySQL/OSM, live
služby, pixel-perfect screenshoty, 100% coverage ani production scheduler.

- **DECIDED:** minimální stack PHPUnit + Vitest/jsdom + Playwright; spatial
  correctness vždy nad skutečným MySQL test schema.
- **RECOMMENDED:** testovat API response shape a vybrané vlastnosti, nikoli
  megabajtové GeoJSON snapshoty; pro každou chybu mít malý deterministický seed.
- **OPEN:** při implementaci potvrdit testovatelnou route-handler/service
  hranici bez přidání frameworku a dostupnost podporovaného MySQL pro testy.

## 25. Uzavření pre-implementation review S1–S3

Tato část nahrazuje starší formulace o viewportových KÚ requestech a prosté
transformaci čtyř rohů. Závazné podrobnosti jsou v `docs/API.md`, `DATA.md`
a `UI.md`; nejde o implementaci ani nové discovery.

### S1 — zoom, BBOX a úplný KÚ kontext

- `zoom` je jediný scalar parametr s kanonickým integer zápisem `0..22`.
  Desetinná čísla, exponenty, znaménka, mezery, úvodní nuly a duplicity
  znamenají `400 invalid_zoom`. Technický rozsah není LOD threshold:
  počátečních 17 je stále benchmarkem měnitelná politika (`422 zoom_too_low`).
- Velikost původního BBOX se hodnotí dvěma nezávislými rozdíly v úhlových
  stupních: `maxLng-minLng` a `maxLat-minLat`. Překročení kteréhokoli
  endpointového ceilingu znamená `422 bbox_too_large`; rovnost projde.
  Rozsah datasetu není podmínka, že se request musí vejít do okresu.
- Validní BBOX částečně mimo okres vrací data našeho datasetu, mimo pokrytí
  vrací empty 200 (s tolerancí okrajových false positives podle S2).
  Size/zoom policy se kontrolují před empty shortcutem. Vzdálený BBOX se
  nepřevádí do lokálního CRS, pokud je disjunktní s ověřeným obalem D.
- D je konzervativní committed okresní envelope v 4326, ověřený před aktivací
  datasetu. KÚ bootstrap vždy načítá D, nikoli padded screen viewport, a ověří
  celou sadu kódů KÚ. Má vlastní controller; pan/resize jej neruší. Retry
  opakuje D, success spustí refresh nejnovějšího viewportu. Parcely čekají na
  hotový fallback. Potom se KÚ vrstva drží celou relaci bez dalších pan fetchů.
- UI použije Leaflet maxBounds `D.pad(0.10)`, viscosity 1 a minimální zoom
  odpovídající district fitu v aktuální velikosti mapy. Initial/reset fit mají
  32px padding. Široký či úzký viewport může ukazovat okolní basemap, ale nemá
  navigovat daleko od okresu. Neřešíme nepravidelný polygonový ořez. Jde o UX,
  nikoli důvěryhodnou ochranu API. Padding se ověří na skutečných viewports.

### S2 — konzervativní native query geometry

Zvolený postup: průnik requestu s ověřeným D -> zahuštění hran v 4326 ->
transformace vzorků přes MySQL -> native min/max envelope rozšířený ven o
bezpečnostní rezervu v metrech. Zachováme i boundary-only line/point kontakt.
Samotné čtyři rohy ani zahuštěný polygon bez rezervy negarantují pokrytí.

Rezerva musí pokrýt chybu mezi vzorky a numerickou chybu transformace pro
zvolený krok v jičínské oblasti. Je to correctness invariant, ne performance
tuning. Jeho konkrétní krok/rezerva a podpora MySQL patří do povinného
implementation-time preflightu; samotné husté vzorkování není důkaz meze chyby.
Hraniční fixtures ověří, že žádná zasahující parcela nevypadne. Pokud pokrytí
nelze zdůvodnit, preflight neprojde; nevracíme se potichu ke čtyřem rohům.

`MBRIntersects` vybírá indexované kandidáty. `ST_Intersects` je přesný vůči
rozšířenému native obalu Q, nikoli původnímu viewportu. Q musí obsahovat jeho
relevantní transformovaný obraz; proto se zasahující parcela nevynechá.
Přebyteční kandidáti jsou přípustní a počítají se do capu. Stored geometry
zůstává indexovaná v 5514, bez transformace ve WHERE a bez nové GIS platformy.

### S3 — relační invarianty

Povinné jsou kompatibilní `BIGINT UNSIGNED NOT NULL` PK/FK části,
`UNIQUE(import_territory.dataset_id, ku_code)`, FK checkpointu i KÚ do datasetu
a nenulový composite FK parcely `(dataset_id, territory_id)` na KÚ
`(dataset_id, id)`. Retry aktualizuje jeden checkpoint. Přímý parcel-to-dataset
FK nepřidáváme: existenci datasetu garantuje povinná vazba přes KÚ. Skutečné
DDL a odmítání osiřelých/cross-dataset vazeb ověří MySQL integration testy.

### Git/GitHub — pravidlo pro následnou implementaci

Preferované repository je `jicin-parcel-map`, čitelný název Mapa parcel Jičín.
Lokální adresář nepřejmenováváme a repository nyní nezakládáme. Při startu
implementace nejprve ověřit existenci repository, remote/owner, větev,
autentizaci a push dostupnost. Založení chybějícího repo či změna nesouladného
remote vyžaduje potvrzení uživatele.

Workflow je implementace -> relevantní verification -> diff/review -> commit
-> push po logických dokončených krocích. Žádné umělé mikrocommity ani jeden
finální omnibus commit; stručné messages a skutečná historie. Bez explicitního
požadavku žádný rebase/force push. Necommitovat secrets, data, DB ani build
artefakty. Pokud push není dostupný, pouze lokální commit a jasná informace;
autentizaci neobcházet. Úplný kontrakt je v `docs/IMPLEMENTATION-PLAN.md`.

S1–S3 jsou návrhově uzavřené: **GO**. Konkrétní spatial a UX ověření zůstávají
implementation-time verification, LOD/size/feature ceilings benchmarkem.
Implementace, repository creation ani IMPLEMENTATION-LOG.md tím nezačínají.
