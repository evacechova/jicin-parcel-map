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
