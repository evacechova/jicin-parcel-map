# Mapa parcel Jičín

Read-only mapa katastrálních parcel okresu Jičín. Projekt má připravený PHP/Vite
základ, verzované databázové schéma a reprodukovatelný streamovaný full import
ČÚZK dat s atomickou aktivací snapshotu. Read-only HTTP API poskytuje hranice
KÚ a viewportové parcelní GeoJSON pouze z atomicky aktivovaného snapshotu;
Leaflet frontend načítá úplnou KÚ fallback vrstvu, podle zoomu bezpečně mění
parcelní viewporty a zobrazuje zdrojově podložený detail vybrané parcely.

## Lokální prostředí

- PHP **8.5** a Composer 2.
- Node.js **24 LTS** a npm.
- Oracle MySQL Community Server **8.4 LTS** se Spatial podporou. Docker se nepoužívá.

Pokud používáš Homebrew `node@24`, v každém projektovém terminálu nastav:

```sh
export PATH="/opt/homebrew/opt/node@24/bin:$PATH"
node --version
```

Nastavení platí pouze pro daný terminál; nemění globální konfiguraci shellu.

## Databáze

Migrace jsou verzované změny databázového schématu. Migrátor eviduje použité
verze v tabulce `schema_migration`, opakované spuštění je bezpečný no-op a
poslední krok lze vrátit pro lokální ověření.

V MySQL vytvoř oddělenou aplikační a testovací databázi i uživatele. Hesla níže
nahraď vlastními a neukládej je do Gitu:

```sql
CREATE DATABASE viagem CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'viagem'@'127.0.0.1' IDENTIFIED BY 'replace-me';
GRANT ALL PRIVILEGES ON viagem.* TO 'viagem'@'127.0.0.1';

CREATE DATABASE viagem_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'viagem_test'@'127.0.0.1' IDENTIFIED BY 'replace-me-too';
GRANT ALL PRIVILEGES ON viagem_test.* TO 'viagem_test'@'127.0.0.1';
```

Zkopíruj `.env.example` do ignorovaného `.env` a nastav `DB_*`. Pro testovací
databázi zkopíruj `.env.test.example` do ignorovaného `.env.test` a nastav
výhradně `TEST_DB_*`. Testové příkazy odmítnou databázi, jejíž název nekončí
`_test`, i konfiguraci shodnou s `DB_NAME`.

```sh
php bin/database.php status
php bin/database.php migrate
php bin/database.php rollback

php bin/database.php migrate --test
php bin/database.php status --test
composer verify:database
```

`rollback` vrací pouze poslední migraci a je určený pro vývoj/testování. V
běžném sdíleném prostředí se již použité migrace neupravují; přidává se další.

## PHP server

Z kořene projektu:

```sh
composer install
cp .env.example .env
composer dev
```

`http://127.0.0.1:8000/api` vrací jednoduchou health odpověď s názvem aplikace.
Server je určený pouze pro lokální vývoj. Ukončení: Ctrl+C.

`.env` obsahuje lokální konfiguraci a nepatří do Gitu. Bez něj fungují výchozí
hodnoty. Existující proměnné prostředí mají přednost. Do frontendových `VITE_*`
proměnných nikdy nepatří secrets, protože se dostávají do prohlížeče.
`APP_CORS_ORIGIN` je jediný povolený development origin (výchozí Vite
`http://127.0.0.1:5173`); wildcard ani credentials se nepoužívají.

## Read-only HTTP API

API používá prefix `/api/v1`, standardní GeoJSON v EPSG:4326 a čte výhradně
dataset vybraný `active_dataset.slot = 1`, pokud má současně stav `ready`.
Geometrie v databázi zůstávají v EPSG:5514.

```sh
curl --get 'http://127.0.0.1:8000/api/v1/cadastral-territories' \
  --data-urlencode 'bbox=14.80,50.15,15.95,50.85'

curl --get 'http://127.0.0.1:8000/api/v1/parcels' \
  --data-urlencode 'bbox=15.30,50.40,15.31,50.41' \
  --data-urlencode 'zoom=17'

curl 'http://127.0.0.1:8000/api/v1/parcels/CP.99632534010'
```

- `GET /api/v1/cadastral-territories?bbox=minLng,minLat,maxLng,maxLat` vrací
  `FeatureCollection` s `ku_code`, názvem a importovaným počtem parcel.
- `GET /api/v1/parcels?bbox=...&zoom=17` vrací nejvýše 2 000 kompletních
  parcelních features (`id` je INSPIRE ID, jediná property je `label`).
- `GET /api/v1/parcels/{inspireId}` vrací metadata zvolené parcely bez geometrie.

BBOX musí být jedna skalární čtveřice v pořadí longitude/latitude, v platném
WGS84 rozsahu a se vzestupnými mezemi. Parcelní geometrie je dostupná od zoomu
17. Příliš velký span vrací `422`; viewport s více než 2 000 parcelami vrací
`409 too_dense`, nikdy oříznutou parcelní vrstvu. Validní prázdný viewport je
`200` s prázdným `FeatureCollection`. Chyby mají jednotný JSON envelope s
bezpečným `code`, zprávou a request ID; databázové detaily se neposílají.

Deterministická validace nevyžaduje DB. Kompletní API/spatial suite používá
chráněnou MySQL 8.4 `*_test` databázi z `TEST_DB_*`:

```sh
composer verify:api-validation
composer verify:api
composer benchmark:api
```

Benchmark dočasně seeduje 20 000 jednoduchých parcel do testovací databáze a
po skončení je odstraní; není součástí běžné correctness suite.

## Frontend

Po nastavení Node 24 nainstaluj závislosti z lockfilu:

```sh
npm ci
```

Nech běžet `composer dev` v prvním terminálu. Ve druhém, také s Node 24, spusť:

```sh
npm run dev
```

Otevři **http://127.0.0.1:5173**. Mapa nejprve načte a ověří všech 240 KÚ přes
fixní okresní BBOX. Pod zoomem 17 je ponechá jako smysluplnou vrstvu; od zoomu
17 načítá pouze parcely aktuálního viewportu. Kliknutí na KÚ mapu přiblíží,
kliknutí na parcelu načte její výměru, KÚ a katastrální referenci. `too_dense`
tiše obnoví KÚ fallback; síťová/serverová chyba zachová poslední použitelná
data a nabídne retry.

Vite předává pouze `/api` a `/api/...` na PHP port 8000, takže prohlížeč používá
jednu adresu bez potřeby CORS nastavení. Oba servery ukončíš pomocí Ctrl+C.

```sh
npm run test:frontend
npm run build
```

Build vytvoří frontendové soubory v ignorovaném `dist/`. `npm run preview`
umí lokálně zobrazit tento build a při běžícím PHP používá stejnou proxy.
Produkční nasazení a webserver routing zatím nejsou součástí projektu.

Reprodukovatelný browser benchmark používá výhradně chráněnou `*_test`
databázi, seed 240 KÚ / 1 500 syntetických parcel, běžící PHP/Vite servery a
lokálně nainstalovaný Google Chrome. `DB_*` PHP serveru musí ukazovat na stejnou
fixture databázi jako `TEST_DB_*` seederu:

```sh
composer seed:frontend-benchmark
npm run benchmark:frontend
composer clean:frontend-benchmark
```

Benchmark stubuje OSM tiles, aby neměřil externí službu. Jeho omezení a
naměřené Phase 05 výsledky jsou v `docs/PERFORMANCE.md`.

## ČÚZK import

Phase 03 obsahuje pevný scope 240 katastrálních území okresu Jičín, download s
omezenými retry, streamovaný ZIP/GML parser, per-KÚ databázové transakce,
completeness validaci a atomickou aktivaci snapshotu.

Deterministické fixture testy nevyžadují internet:

```sh
composer verify:importer
```

Omezený live smoke test stáhne pouze aktuální KÚ `601101` do nového dočasného
run adresáře, zkontroluje ZIP, dvakrát jej projde přímo přes `XMLReader` a po
úspěchu artefakt odstraní:

```sh
composer smoke:cuzk
```

Stažený ZIP ani GML nepatří do Gitu.

Databázový checkpoint importeru se ověřuje výhradně nad chráněnou `*_test`
databází nakonfigurovanou přes `TEST_DB_*`:

```sh
composer verify:importer-db
composer verify:importer-publication
composer smoke:importer-db
```

První příkaz používá pouze malé lokální ZIP/GML fixtures. Druhý stáhne jedno
aktuální KÚ `601101`, vytvoří neaktivní `importing` dataset, ověří zápis do
MySQL a po úspěchu testová data i dočasný ZIP odstraní. Třetí deterministicky
ověří 240-KÚ orchestration loop, completeness validation, první aktivaci,
přepnutí A → B, retirement a rollback aktivační transakce.

Po nastavení `DB_*` v `.env` a aplikaci migrací spustí kompletní aktuální
snapshot okresu veřejný příkaz:

```sh
php bin/database.php migrate
php bin/import-cadastral.php --scope=jicin
```

Každý běh vytvoří nový dataset a vlastní
`storage/imports/<run-id>/downloads/`; nikdy neupravuje aktivní snapshot na
místě. Úspěšný běh po validaci atomicky přepne `active_dataset` a své ZIPy
odstraní. Failed run se neaktivuje a artefakty ponechá pro diagnózu. Volitelný
`--keep-artifacts` zachová ZIPy i po úspěchu. Resume není podporované; nový
pokus vytvoří nový dataset a stáhne všech 240 KÚ znovu.

## Struktura

- `public/index.php`: jediný HTTP vstup PHP a allowlisted API router.
- `app/bootstrap.php`: Composer autoload a lokální konfigurace.
- `app/Database/`: DB konfigurace, PDO připojení, migrátor a ochrana testovací DB.
- `app/Http/`, `app/Api/`: request/response vrstva, validace, routing a API kontrakt.
- `app/Geo/`, `app/Read/`: konzervativní BBOX převod, active-dataset read služba a SQL repository.
- `app/Import/`: scope, bezpečný download, ZIP kontrola a streamovaný GML parser.
- `app/Import/Database/`: dataset/checkpoint lifecycle a transakční zápis jednoho KÚ.
- `config/scopes/jicin.csv`: pevný verzovaný seznam 240 KÚ okresu Jičín.
- `database/migrations/`: vzestupné a vratné SQL migrace.
- `bin/database.php`: stav, aplikace a vrácení migrací.
- `bin/import-cadastral.php`: full import, validace a atomická aktivace snapshotu.
- `frontend/`: Leaflet map view, API klient, request koordinátory, detail a styl.
- `tests/Frontend/`: rychlé API/lifecycle testy bez simulování Leaflet internals.
- `tests/Performance/frontend_benchmark_fixture.php`: chráněný browser fixture seed/cleanup.
- `tests/Performance/benchmark_frontend.mjs`: Chrome/Leaflet benchmark a `too_dense` kontrola.
- `vite.config.js`: frontendový root, dev proxy a výstup buildu.
- `composer.lock`, `package-lock.json`: přesné verze závislostí pro instalaci.
- `.env.example`: veřejný vzor konfigurace; vlastní `.env` se necommituje.
- `docs/`: schválený návrh, fáze implementace a stručný log.

Mapa záměrně nepřidává parcelní cache, clustering, vector tiles, S2 index,
background služby ani mutation endpointy.

## Ověření

```sh
composer validate --strict
composer lint
composer verify:database
composer verify:importer
composer verify:importer-db
composer verify:importer-publication
composer verify:api
npm run test:frontend
npm run build
curl --fail http://127.0.0.1:8000/api
```

Schválený plán je v [docs/IMPLEMENTATION-PLAN.md](docs/IMPLEMENTATION-PLAN.md).
Skutečný postup zachycuje [implementation log](docs/IMPLEMENTATION-LOG.md).

Historii uzavřené discovery fáze uchovává [docs/DISCOVERY.md](docs/DISCOVERY.md).
Jde o historický záznam; finální architekturu a rozhodnutí určují příslušné dokumenty v `docs/`.
