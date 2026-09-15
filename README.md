# Mapa parcel Jičín

Read-only mapa katastrálních parcel okresu Jičín. Projekt je ve fázi foundation;
parcelová data, databáze a mapa zatím nejsou implementované.

## Lokální prostředí

- PHP **8.5** a Composer 2.
- Node.js **24 LTS** a npm.
- Bez Dockeru. MySQL zatím není potřeba.

Pokud používáš Homebrew `node@24`, v každém projektovém terminálu nastav:

```sh
export PATH="/opt/homebrew/opt/node@24/bin:$PATH"
node --version
```

Nastavení platí pouze pro daný terminál; nemění globální konfiguraci shellu.

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
- `frontend/index.html`, `main.js`, `style.css`: stránka, kontrola spojení a styl.
- `vite.config.js`: frontendový root, dev proxy a výstup buildu.
- `composer.lock`, `package-lock.json`: přesné verze závislostí pro instalaci.
- `.env.example`: veřejný vzor konfigurace; vlastní `.env` se necommituje.
- `docs/`: schválený návrh, fáze implementace a stručný log.

Leaflet bude zapojen s mapou v Phase 05. Foundation zatím nemá doménové API,
databázi, import ani testovací/benchmarkovou infrastrukturu dalších fází.

## Ověření

```sh
composer validate --strict
composer lint
npm run build
curl --fail http://127.0.0.1:8000/api
```

Schválený plán je v [docs/IMPLEMENTATION-PLAN.md](docs/IMPLEMENTATION-PLAN.md).
Skutečný postup zachycuje [implementation log](docs/IMPLEMENTATION-LOG.md).

Historii uzavřené discovery fáze uchovává [docs/DISCOVERY.md](docs/DISCOVERY.md).
Jde o historický záznam; finální architekturu a rozhodnutí určují příslušné dokumenty v `docs/`.
