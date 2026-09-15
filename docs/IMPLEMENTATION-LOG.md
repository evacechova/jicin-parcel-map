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
