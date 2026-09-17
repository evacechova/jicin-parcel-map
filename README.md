# Mapa parcel Jičín

Read-only webová mapa katastrálních parcel pro **celý okres Jičín**. Poslední
ověřený real-data E2E snapshot obsahuje **240 katastrálních území a 272 768
parcel**. Backend je framework-free PHP, data jsou uložená v MySQL 8.4 Spatial
a frontend používá vanilla JavaScript, Leaflet a Vite.

Zdrojová data pocházejí z veřejných ČÚZK INSPIRE Cadastral Parcels ZIP/GML
souborů. Aplikace je předem stáhne a publikuje jako lokální verzovaný snapshot;
při práci s mapou neposílá live viewport requesty do ČÚZK.

## Jak funguje výkon nad celým okresem

Celý okres je dostupný v databázi, ale všech 272 768 parcel se nikdy neposílá
ani nevykresluje současně:

- vzdálený pohled používá úplnou, omezenou vrstvu 240 KÚ;
- parcelní vrstva se načítá až od zoomu 17 a pouze pro aktuální viewport;
- backend filtruje nativní EPSG:5514 geometrie přes MySQL spatial index;
- hard limit je 2 000 parcel a SQL čte `limit + 1`;
- při překročení limitu API vrátí `409 too_dense`, nikoli neúplnou parcelní
  vrstvu, a klient ponechá KÚ jako smysluplný fallback.

Benchmarky, jejich metodika a omezení jsou v
[Performance](docs/PERFORMANCE.md). Technický request flow popisuje
[Architecture](docs/ARCHITECTURE.md).

## Prerequisites

- PHP **8.5** a Composer 2;
- PHP extensions `curl`, `mbstring`, `pdo_mysql`, `xmlreader`/XML a `zip`;
- Oracle MySQL Community Server **8.4 LTS** se Spatial podporou;
- Node.js **24.x**, npm a internetové připojení pro instalaci dependencies,
  ČÚZK import a OSM mapové dlaždice;
- jednorázový MySQL admin přístup pro server-global SRS provisioning.

Docker není potřeba.

Na macOS s Homebrew `node@24` lze pro aktuální shell použít:

```sh
export PATH="/opt/homebrew/opt/node@24/bin:$PATH"
node --version
```

Jde pouze o Homebrew convenience; projekt obecně vyžaduje Node 24.x.

## Fresh clone → running application

### 1. Clone a dependencies

```sh
git clone https://github.com/evacechova/jicin-parcel-map
cd jicin-parcel-map
composer install
npm ci
```

### 2. Aplikační konfigurace

macOS/Linux/Git Bash:

```sh
cp .env.example .env
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

V ignorovaném `.env` nastav vlastní připojení:

```dotenv
APP_NAME="Mapa parcel Jičín"
APP_CORS_ORIGIN=http://127.0.0.1:5173
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=viagem
DB_USER=viagem
DB_PASSWORD=replace-with-a-local-password
```

`APP_NAME`, `APP_CORS_ORIGIN`, `DB_HOST`, `DB_PORT` a `DB_PASSWORD` mají
implementační defaults; `DB_NAME` a `DB_USER` jsou pro DB-backed příkazy a
mapové API povinné. Pro reprodukovatelný setup nastav celý blok výše v `.env`
nebo process environment. Skutečné credentials nepatří do Gitu.

### 3. Databáze a aplikační uživatel

Jako MySQL administrátor vytvoř databázi a lokálního aplikačního uživatele;
heslo musí odpovídat `.env`:

```sql
CREATE DATABASE viagem
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'viagem'@'127.0.0.1'
  IDENTIFIED BY 'replace-with-a-local-password';
GRANT ALL PRIVILEGES ON viagem.* TO 'viagem'@'127.0.0.1';
```

### 4. Provisioning aplikačního SRS

MySQL server musí mít před importem jednorázově zaregistrované
aplikační SRS `1005514`. Na fresh MySQL 8.4 ho vytvoř jako serverový
administrátor. Tento tvar funguje v POSIX shellu, Git Bash, Windows CMD i
PowerShellu:

```sh
mysql --user=root --password --execute="source database/spatial-reference/1005514.sql"
```

Provisioning je server-global admin krok. Běžný aplikační uživatel definici
pouze čte a admin oprávnění nepotřebuje. SQL záměrně nepoužívá `OR REPLACE`:
existující definice se nesmí tiše přepsat. Pokud `1005514` na serveru už
existuje, creation command znovu nespouštěj; ověř jej následujícím příkazem.
Chybějící nebo obsahově odlišná definice je blokující chyba.

```sh
composer verify:srs
```

Tato kontrola musí projít před dlouhým importem.

### 5. Migrace

```sh
php bin/database.php migrate
php bin/database.php status
```

`rollback` není součást běžného setup flow; je dostupný jen jako vývojový
příkaz pro vrácení poslední migrace.

### 6. Import celého okresu

```sh
php bin/import-cadastral.php --scope=jicin
```

Žádné samostatné stažení databáze ani datasetu není potřeba; tento příkaz
stáhne zdrojové archivy z ČÚZK a vytvoří lokální MySQL snapshot.

Full MySQL databáze ani snapshot s 272 768 parcelami nejsou commitnuté. Tento
veřejný importer stáhne všech 240 KÚ z ČÚZK, streamovaně je uloží do nového
inactive datasetu, zvaliduje úplnost a geometrie a až potom snapshot atomicky
aktivuje. Runtime mapy následně ČÚZK nepotřebuje.

V posledním ověřeném lokálním E2E běhu trval dataset lifecycle pro 240 KÚ a
272 768 parcel přibližně **168,6 s (2 min 49 s)**. Jde o konkrétní referenční
měření; čas závisí na hardware, síti a dostupnosti ČÚZK.

### 7. Spuštění backendu a frontendu

V prvním terminálu:

```sh
composer dev
```

PHP poslouchá na `http://127.0.0.1:8000`; health endpoint je
`http://127.0.0.1:8000/api`.

Ve druhém terminálu, s Node 24.x:

```sh
npm run dev
```

Otevři **http://127.0.0.1:5173**.

Mapa nejprve zobrazí hranice všech 240 KÚ. Kliknutí na KÚ přiblíží jeho rozsah;
od zoomu 17 se načítají parcely aktuálního viewportu. Kliknutí na parcelu otevře
její číslo, výměru, KÚ a katastrální referenci.

## Základní ověření

Rychlé kontroly bez full importu:

```sh
composer validate --strict
composer lint
composer verify:importer
composer verify:api-validation
npm run test:frontend
npm run build
```

Databázové integrační testy vyžadují samostatnou databázi s názvem končícím
`_test`; nesmějí mířit na aplikační ani reálný importovaný snapshot. Kompletní
test setup, DB verification, network smokes a všechny příkazy jsou v
[Testing](docs/TESTING.md).

Reprodukovatelný syntetický Leaflet benchmark a finální browser verification
proběhly v **Google Chrome 152**. Nejde o obecný cross-browser claim ani o
benchmark současného vykreslení všech reálných parcel. Podrobnosti jsou v
[Performance](docs/PERFORMANCE.md).

## High-level architektura

```text
ČÚZK ZIP/GML -> PHP CLI importer -> MySQL 8.4 Spatial
                                      |
                                      v
Leaflet frontend <- GeoJSON <- framework-free PHP API
```

Importer používá per-KÚ checkpointy, transakce, complete-dataset validaci a
atomic publication. API čte pouze aktivní `ready` dataset. Geometrie zůstávají
v nativním EPSG:5514; veřejné viewporty a vybrané výsledky používají ověřenou
obousměrnou transformační cestu přes aplikační SRS `1005514`.

Detailní architektura, API kontrakty a data model zůstávají v specializovaných
dokumentech níže.

## Dokumentace

- [Production Notebook](docs/PRODUCTION-NOTEBOOK.md) — reasoning, klíčová
  rozhodnutí, lessons learned a co bych řešila s více časem.
- [Architecture](docs/ARCHITECTURE.md) — finální technická architektura a flow.
- [API](docs/API.md) — endpointy, validace, errors a spatial query contract.
- [Testing](docs/TESTING.md) — testovací prostředí, matrix a příkazy.
- [Performance](docs/PERFORMANCE.md) — metodika, výsledky a jejich omezení.
- [Data](docs/DATA.md) — ČÚZK data, import lifecycle, schema a metadata.
- [Decisions](docs/DECISIONS.md) — hlubší technický decision record.
- [Discovery](docs/DISCOVERY.md) — historický discovery/research záznam.
- [Implementation Log](docs/IMPLEMENTATION-LOG.md) — chronologická historie
  implementace a verifikace.

Původní [implementation plan](docs/IMPLEMENTATION-PLAN.md) a jeho phase soubory
jsou zachované jako historické plány, nikoli jako aktuální specifikace.
