# Mapa parcel Jičín

Read-only mapa katastrálních parcel okresu Jičín. Projekt je ve fázi foundation;
parcelová data, databáze a mapa zatím nejsou implementované.

## Lokální prostředí

- PHP **8.5** a Composer 2.
- Node.js **24 LTS** a npm (frontend bude doplněn v dalším foundation kroku).
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

## Ověření

```sh
composer validate --strict
composer lint
curl --fail http://127.0.0.1:8000/api
```

Schválený plán je v [docs/IMPLEMENTATION-PLAN.md](docs/IMPLEMENTATION-PLAN.md).
Skutečný postup zachycuje [implementation log](docs/IMPLEMENTATION-LOG.md).
