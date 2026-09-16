# Implementation log

## Phase 01 — PHP foundation (2026-09-15)

- Převzata existující GitHub historie `b079c5a` na `main`, nastaven schválený
  origin. Před implementací prošel push dry-run; lokální historie neexistovala.
- Přidán Composer, PSR-4 mapování `App` na `app/`, bootstrap s phpdotenv,
  minimální GET `/api`, `.env.example`, ignore pravidla a startup dokumentace.
  Schválená dokumentace zařazena do verzování; discovery poznámky jsou lokální.
- Target: PHP 8.5 / Node 24 LTS. Instalován Homebrew Node 24.20.0 (npm 11.19.0)
  a Composer 2.10.3, který používá původní PHP 8.5.10. Shell konfigurace nezměněna.
- Environment problém: Homebrew aktualizoval závislosti c-ares, libuv, simdjson
  a přidal hdrhistogram_c. Node 25.2.1 zůstal nainstalovaný, ale jeho spuštění
  nyní selže na chybějící `libsimdjson.29.dylib` v aktivním simdjson prefixu.
  Původní simdjson 4.2.4 je zachovaný. Oprava zatím neprovedena.
- Verification: `composer install`, `composer validate --strict`, `composer lint`;
  PHP server spuštěn přes `composer dev`. GET `/api` = 200, neznámá cesta
  a `/.env` = 404, POST `/api` = 405. Ověřeno načtení `.env`, výchozí hodnota
  bez `.env` i přednost existující proměnné prostředí.
- Po dohodě je problém Node 25 mimo scope assignmentu a neblokuje projekt.

## Phase 01 — Vite foundation (2026-09-15)

- Přidán Vite 8.3.0, vanilla JS/CSS/HTML, dev `/api` proxy na PHP,
  oddělený frontend root a build do ignorovaného `dist/`.
- Frontend zobrazí úspěch/chybu spojení s foundation odpovědí. Žádná mapa,
  doménové API, DB, import ani benchmark nepřidány. Leaflet patří do Phase 05.
- README doplněno o spuštění obou serverů, build a roli hlavních souborů.
  Node target 24.x je v manifestu a npm jej vynucuje přes `engine-strict`.
- Verification pod Node 24.20.0 / npm 11.19.0: instalace Vite, `npm ci`,
  `npm run build`, syntaxe JS; dev server i build preview spuštěny.
  HTTP kontrola obou: HTML a odkazované assety = 200, proxy `/api` = 200
  se správným JSON, `/api/missing` = 404. Soukromé cesty neposkytují obsah
  souborů (Vite může vrátit HTML fallback). npm audit při instalaci: 0 nálezů.
- Composer instalace z lockfilu, strict validace a PHP lint prošly. Sandbox
  omezuje síť/localhost; síťové instalace a serverové kontroly vyžadovaly
  povolené spuštění mimo sandbox. Při opakované Composer instalaci v sandboxu
  kontrola vzdálených filtrů nebyla dostupná; existující lock se nainstaloval.
- Vizuální browser kontrola neprovedena: nástroj nemá dostupný prohlížeč.
  HTTP/dev/build ověření dokončeno; nevykazujeme browser E2E test.
- Na explicitní pokyn opraven globální Git e-mail a nastaven repository-local
  GitHub noreply e-mail; jméno nezměněno. PHP krok `fddc0ef` pushnut na main.
- Phase 01 dokončena. Bez změny architektury; Phase 02 nezahájena.

## Phase 01 — Discovery documentation cleanup

- Původní lokální discovery poznámky přesunuty do `docs/DISCOVERY.md` a nově
  zařazeny do verzování. Zdroj byl ignorovaný a nesledovaný, proto nebylo možné
  použít `git mv` ani zaznamenat rename vůči předchozímu commitu.
- Upraven pouze úvod vymezující historickou roli a relativní odkazy v něm;
  obsah za úvodním oddělovačem zachován beze změny. README odkazuje na archiv.
- Ověřena shoda historického obsahu, místní Markdown odkazy, absence referencí
  na původní název a diff. Aplikační kód nezměněn; Phase 02 nezahájena.

## Phase 02 — Database (2026-09-16)

- Implementation-time decision: databázový target změněn z původního minima
  MySQL 8.0.32+ na Oracle MySQL Community Server 8.4 LTS. MySQL 8.0 je od
  dubna 2026 v Sustaining Support a lokálně dostupný MySQL 26.7 je Innovation;
  pro nový projekt byla proto zvolena aktuálně podporovaná stabilní LTS řada.
- Vedle existujícího Homebrew MySQL 26.7 byl nainstalován keg-only
  `mysql@8.4` 8.4.11_4. Globální link `mysql` zůstal na 26.7, nebyla zapnuta
  žádná MySQL service a existující `/opt/homebrew/var/mysql` nebyl použit.
  Verification běžel na izolovaném MySQL Community Server 8.4.11 nad
  `/private/tmp/viagem-mysql84-phase02` na localhost portu 33308; klientská i
  serverová verze byly před testy ověřeny jako 8.4.11.
- Přidán verzovaný SQL migrátor s checksumy, status/migrate/rollback příkazy a
  dvojicí vratných migrací. První vytváří `dataset`, jediný ukazatel
  `active_dataset` a per-dataset/KÚ checkpoint `import_territory`; druhá vytváří
  `cadastral_territory` a `parcel` s native EPSG:5514 geometriemi, relačními,
  B-tree a spatial indexy. Opakované `migrate` je no-op; úplný rollback obou
  migrací a čisté znovunasazení obou migrací prošly.
- Test konfigurace používá pouze `TEST_DB_*`. Destruktivní integration runner
  odmítl název bez suffixu `_test` i `TEST_DB_NAME` shodné s `DB_NAME`; testy
  běžely výhradně nad izolovanou `viagem_test` a fixture DML byl vrácen
  transakcí.
- MySQL 8.4 preflight potvrdil registry EPSG:5514 a EPSG:4326. Kontrolní bod
  `(-671984.1403374915, -1013081.1797817094)` v 5514 se transformoval na
  `(15.3516000081, 50.4372000051)` v 4326; opačný směr prošel s tolerancí 1 m.
  `ST_AsGeoJSON()` vrátil pořadí `[15.3516000081, 50.4372000051]`, tedy
  `[longitude, latitude]`.
- Metadata schema assertions potvrdily `BIGINT UNSIGNED`/`NOT NULL`/auto
  increment klíče, shodné `CHAR(6) ASCII COLLATE ascii_bin`, SRID-restricted
  `MULTIPOLYGON`/`POINT` sloupce a požadované spatial/B-tree indexy. BBOX
  fixture odlišil MBR false positive od přesného `ST_Intersects`; JSON EXPLAIN
  po realisticky větším malém fixture skutečně použil
  `sp_parcel_geom_native`, ne pouze `possible_keys`.
- S3 invarianty byly ověřeny přímým DDL metadata checkem i odmítnutými zápisy:
  duplicitní checkpoint/KÚ/INSPIRE identity, orphan dataset reference, NULL
  relationship klíče, cizí SRID, druhý active slot a delete referencovaných
  rodičů. Composite FK `(dataset_id, territory_id)` odmítl parcelu datasetu B
  odkazující na KÚ datasetu A. Active-pointer query zveřejnila jen aktivní
  `ready` dataset a skryla aktivní dataset ve stavu `importing`.
- První verification běh odhalil opakovaný named PDO parametr při native
  prepared statements; dotaz nyní binduje hrubý a přesný BBOX parametr zvlášť.
  První kontrola EXPLAIN byla zpřísněna z výskytu v `possible_keys` na skutečný
  použitý `key`. Nedošlo k další odchylce od schváleného databázového modelu.
- Phase 02 neimplementuje importer, GML parser, API, S2 viewport transform,
  frontend ani performance tuning.

## Phase 03 — Parser/download foundation checkpoint (2026-09-16)

- Z oficiálního číselníku `SC_SEZNAMKUKRA_DOTAZ` byl ověřen a verzován pevný,
  seřazený scope `jicin`: přesně 240 unikátních šestimístných kódů pro okres
  `3604`, bez chybějícího, přebývajícího nebo odlišně pojmenovaného KÚ.
- Deterministický zdroj jednoho KÚ je
  `https://services.cuzk.gov.cz/gml/inspire/cp/epsg-5514/{KU_KOD}.zip`.
  Downloader zapisuje každý pokus do samostatného `.part`, používá konečné
  timeouty, publikuje až po ZIP kontrole atomickým rename a retryuje pouze
  transportní chyby a HTTP 408/429/5xx v režimu 0/1/3 s; `Retry-After` je
  omezen na 30 s. HTTP 404 ani poškozený ZIP se neopakují.
- ZIP vrstva přijímá právě jeden bezpečně pojmenovaný `{KU_KOD}.xml`, kontroluje
  konzistenci, velikost, kompresní poměr a celý entry stream. XML se nerozbaluje
  na disk; parser otevírá `zip://...#entry` přímo přes `XMLReader` s vypnutými
  substitucemi entit a zakázanou sítí.
- Parser pracuje dopředně po jednom `CadastralZoning` nebo `CadastralParcel`,
  bez DOM/SimpleXML a bez dokumentového bufferu. `Polygon` i `MultiSurface`
  normalizuje na WKT `MULTIPOLYGON`, zachovává exterior/interior rings a odmítá
  jiné CRS, dimenzi, neuzavřený ring, nečíselnou souřadnici a překročení
  feature limitů. `areaValue` zůstává zdrojová hodnota v `m2`.
- INSPIRE identita pochází explicitně z
  `cp:inspireId/base:Identifier/base:localId`; `gml:id` se čte odděleně a parser
  jejich rovnost nepředpokládá. Fixture to ověřuje rozdílnými hodnotami.
- Deterministické fixture testy pokrývají KÚ MultiSurface, parcelní Polygon s
  dírou, parcelní MultiSurface, `posList`/`pos`, nullable metadata, chybějící
  povinné pole, neuzavřený ring, malformed XML, ZIP entry ochranu, retry s
  `Retry-After` a fail-fast HTTP 404.
- Live smoke stáhl novým samostatným runem KÚ `601101`: ZIP 587 038 B,
  SHA-256 `d7c3ba2398bb6a4437de1153f461d4cf748fcdc3374a875956390f230af5ad02`,
  XML 15 402 232 B, jedno `CadastralZoning` a 1 466 parcel. KÚ je reálný
  `MultiSurface` s jedním polygonem; parcely byly Polygon, 43 interior rings,
  žádný parcelní MultiSurface. Všech 1 466 reálných `localId` se rovnalo
  `gml:id`, ale ukládá se stále explicitní `localId`.
- Během dvou kompletních XMLReader průchodů zůstal PHP alokovaný peak na
  2 MiB (naměřený delta 0 B proti stavu před parsováním), hluboko pod 15,4 MB
  XML. Jeden objekt je po iteraci uvolněn; žádný seznam parcel se nehromadí.
- Tento checkpoint záměrně nevytváří dataset, checkpointy ani DB řádky,
  neaktivuje snapshot a nespouští full district import. Historická discovery
  sada ZIPů z 14. 9. nebyla použita ani smíchána s aktuálním smoke downloadem.

## Phase 03 — Database import pipeline checkpoint (2026-09-16)

- Přidán `ImportRunRepository` pro atomické vytvoření nového `importing`
  datasetu a všech 240 `pending` checkpointů, záznam každého download pokusu,
  metadata ověřeného ZIPu a bezpečný přechod checkpointu/datasetu do `failed`.
  Retry nastavuje `attempt_count` na existujícím unikátním řádku; nevkládá nový
  checkpoint.
- `CadastralWriteRepository` používá opakovaně připravené statementy a
  `ST_GeomFromText(..., 5514, 'axis-order=srid-defined')` pro normalizované
  MULTIPOLYGON i nullable POINT. Mapuje explicitní INSPIRE `localId`, label,
  národní referenci, zdrojové `areaValue`, `validFrom` a
  `beginLifespanVersion`; source date-times se převádějí do UTC `DATETIME(6)`.
- `CadastralImportService` řídí download a dvě parser pass. Po uložení download
  diagnostiky otevře jednu per-KÚ transakci: zamkne processing checkpoint,
  ověří KÚ vůči scope, vloží territory, postupně po jednom vkládá parcely,
  aktualizuje checkpoint na `imported` a inkrementuje dataset counts. Jakákoli
  parser/DB chyba rollbackne territory, všechny parcely, counts i `imported`
  status; až potom samostatná krátká transakce uloží `failed` stav. Služba se
  ukazatele `active_dataset` vůbec nedotýká.
- Deterministické DB integration fixtures běžely na izolovaném Oracle MySQL
  Community Server 8.4.11 (`viagem_test`, localhost port 33309) a prošly.
  Úspěšná větev vytvořila 240 checkpointů, po HTTP 500 + 200 měla stále 240
  řádků, `attempt_count=2`, jeden uložený KÚ a dvě parcely. Ověřeny byly všechny
  metadata fields, SRID 5514, validita geometrií, dvoupolygonový territory
  MultiSurface, parcelní hole a dvoupolygonový parcelní MultiSurface.
- Failure fixture měl duplicitní INSPIRE parcel `localId` ve druhé parcele.
  Databázový unique constraint vyvolal chybu uprostřed KÚ; per-KÚ rollback
  zanechal pro failed dataset nula territory, nula parcel a nulové counts.
  Checkpoint zachoval download checksum, přešel na `failed` s bezpečným
  `database_error` a současný aktivní ready dataset i jeho pointer byly před a
  po chybě beze změny. Znovu byl ověřen composite FK proti cross-dataset vazbě.
- Live DB smoke použil nový samostatný download KÚ `601101`, nikoli historickou
  discovery sadu. ZIP měl 587 038 B a SHA-256
  `d7c3ba2398bb6a4437de1153f461d4cf748fcdc3374a875956390f230af5ad02`.
  Za 1,089 s od download startu po DB commit vznikl jeden KÚ a 1 466 parcel;
  parser result, checkpoint, dataset counts a fyzické DB rows se shodovaly.
  Všech 1 466 parcel a KÚ byly validní v SRID 5514, zachovalo se 43 holes a
  nebyl pozorován parcelní MultiSurface. Checkpoint byl `imported`, pokus jeden,
  dataset zůstal `importing` a `active_dataset` byl před i po prázdný.
- Úspěšný DB smoke odstranil své testové řádky a run-specific ZIP. Tento
  checkpoint neobsahuje complete-dataset validation, activation, full import,
  Phase 04 API ani performance tuning.

## Phase 03 — Full orchestration, validation and activation (2026-09-16)

- Přidán veřejný `php bin/import-cadastral.php --scope=jicin`. Každý běh
  vytváří nový dataset a náhodně pojmenovaný
  `storage/imports/<run-id>/downloads/`, sekvenčně volá ověřený per-KÚ importer
  pro všech 240 kódů a vypisuje jeden stručný progress řádek na KÚ. Úspěšné
  artefakty se standardně odstraní, `--keep-artifacts` je zachová; failed run
  své artefakty ponechá a není aktivován. Resume ani historická discovery sada
  se nepoužívají.
- `DatasetValidator` před publikací explicitně kontroluje přesnou ordered scope
  sadu a 240 imported checkpointů, úspěšnou source diagnostiku, přesnou sadu
  territory rows a jmen, dataset/checkpoint/fyzické/per-KÚ parcelní součty,
  cross-dataset vztahy a unikátní identity. Všechny KÚ i parcely musí být
  non-empty validní SRID 5514 geometrie a po plné transformaci ležet v
  committed `DISTRICT_BOUNDS_4326 = [14.80, 50.15, 15.95, 50.85]`.
- `DatasetPublicationService` provede plnou validaci mimo zámky. V krátké
  transakci zamkne singleton pointer, kandidáta a případný předchozí dataset,
  znovu porovná přesnou scope sadu, checkpoint stavy a všechny persisted counts
  s právě vzniklým validním reportem, nastaví kandidáta na `ready`, přepne slot
  1 a předchozí `ready` dataset označí `retired`. První aktivace používá INSERT
  pointeru; A → B UPDATE. Chyba před pointer write rollbackne všechny tři změny.
- Deterministické MySQL 8.4.11 fixtures prošly pro celý 240-step orchestration
  loop, jednotný run directory a successful cleanup, první aktivaci, A → B,
  retirement A, pointer pouze na B, retained artifacts + `failed` dataset při
  orchestration validation failure, chybějící KÚ, každý stav
  `pending`/`processing`/`failed`, count mismatch a vynucenou chybu těsně před
  pointer write. Ve všech zamítnutých případech zůstal předchozí active dataset
  i pointer beze změny.
- Nový live full run `20260916-143513-368c9d422844` stáhl samostatně všech 240
  aktuálních ZIPů z ČÚZK a nepoužil historické discovery soubory. Dataset 1
  obsahuje 240 KÚ a 272 768 parcel, ZIPy měly dohromady 123 477 457 B
  (117,757 MiB). Všech 240 checkpointů skončilo `imported`, každý na první
  pokus; checkpoint parcel totals, dataset counts a fyzické rows byly shodné,
  orphan parcels 0, invalid/outside KÚ i parcely 0.
- Full CLI čas byl 1 001,393 s (16 min 41,393 s): podle DB timestampů 790,346 s
  import a 210,983 s validation + activation. Tento běh ještě záměrně provedl
  úplnou validaci podruhé pod aktivačními locky. Naměřená cena vedla k finální
  úpravě na jednu úplnou validaci plus krátký locked structural recheck; tento
  finální transaction path znovu prošel všemi deterministickými fixtures.
- První aktivace vytvořila `active_dataset.slot=1 -> dataset 1`; dataset 1 je
  `ready`, má uložený `validation_report.valid=true` a žádný předchozí dataset
  nebyl k retirementu. Skutečný nativní MBR KÚ je
  `(-690276.36,-1031189.84)–(-645777.82,-1001824.71)` a committed D jej s
  rezervou obsahuje. Úspěšný run-specific ZIP prostor byl odstraněn.
- Oproti discovery snapshotu 14. 9. je aktuální parcelní počet nižší o 93
  (`272 768` vs. `272 861`, −0,034 %); celkový ZIP objem odpovídá odhadu
  117,76 MiB. Jde o očekávanou změnu denního ČÚZK snapshotu, ne parser/schema
  odchylku. Phase 04 API ani frontend nebyly zahájeny.
