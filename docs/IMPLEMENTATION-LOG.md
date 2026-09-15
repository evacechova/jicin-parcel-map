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
