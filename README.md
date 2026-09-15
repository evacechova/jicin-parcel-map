# Mapa parcel Jičín

Read-only mapa katastrálních parcel okresu Jičín. Projekt má připravený PHP/Vite
základ a verzované databázové schéma; import dat a mapa zatím nejsou implementované.

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

`http://127.0.0.1:8000/api` vrací jednoduchou foundation odpověď s názvem aplikace.
Server je určený pouze pro lokální vývoj. Ukončení: Ctrl+C.

`.env` obsahuje lokální konfiguraci a nepatří do Gitu. Bez něj fungují výchozí
hodnoty. Existující proměnné prostředí mají přednost. Do frontendových `VITE_*`
proměnných nikdy nepatří secrets, protože se dostávají do prohlížeče.

## Frontend

Po nastavení Node 24 nainstaluj závislosti z lockfilu:

```sh
npm ci
```

Nech běžet `composer dev` v prvním terminálu. Ve druhém, také s Node 24, spusť:

```sh
npm run dev
```

Otevři **http://127.0.0.1:5173**. Stránka zobrazí stav spojení s PHP.
Vite předává `/api` na PHP port 8000, takže prohlížeč používá jednu adresu
bez potřeby CORS nastavení. Oba servery ukončíš pomocí Ctrl+C.

```sh
npm run build
```

Build vytvoří frontendové soubory v ignorovaném `dist/`. `npm run preview`
umí lokálně zobrazit tento build a při běžícím PHP používá stejnou proxy.
Produkční nasazení a webserver routing zatím nejsou součástí foundation.

## Struktura

- `public/index.php`: jediný HTTP vstup PHP, zatím pouze foundation odpověď.
- `app/bootstrap.php`: Composer autoload a lokální konfigurace.
- `app/Database/`: DB konfigurace, PDO připojení, migrátor a ochrana testovací DB.
- `database/migrations/`: vzestupné a vratné SQL migrace.
- `bin/database.php`: stav, aplikace a vrácení migrací.
- `frontend/index.html`, `main.js`, `style.css`: stránka, kontrola spojení a styl.
- `vite.config.js`: frontendový root, dev proxy a výstup buildu.
- `composer.lock`, `package-lock.json`: přesné verze závislostí pro instalaci.
- `.env.example`: veřejný vzor konfigurace; vlastní `.env` se necommituje.
- `docs/`: schválený návrh, fáze implementace a stručný log.

Leaflet bude zapojen s mapou v Phase 05. Projekt zatím nemá doménové API,
import ani testovací/benchmarkovou infrastrukturu dalších fází.

## Ověření

```sh
composer validate --strict
composer lint
composer verify:database
npm run build
curl --fail http://127.0.0.1:8000/api
```

Schválený plán je v [docs/IMPLEMENTATION-PLAN.md](docs/IMPLEMENTATION-PLAN.md).
Skutečný postup zachycuje [implementation log](docs/IMPLEMENTATION-LOG.md).

Historii uzavřené discovery fáze uchovává [docs/DISCOVERY.md](docs/DISCOVERY.md).
Jde o historický záznam; finální architekturu a rozhodnutí určují příslušné dokumenty v `docs/`.
