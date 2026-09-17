# Testing and verification

## Scope

The project verifies risky contracts rather than pursuing a coverage number.
Correctness, browser behaviour, performance and live-source compatibility are
separate checks because they answer different questions.

The implemented toolset is deliberately small:

| Layer | Implemented runner |
| --- | --- |
| PHP validation, importer and integration checks | Deterministic PHP CLI verification scripts invoked by Composer |
| Database and spatial correctness | PHP CLI scripts against an isolated Oracle MySQL 8.4 `_test` database |
| Frontend request-state tests | Vitest |
| Browser flow and rendering benchmark | Playwright driving installed Google Chrome |
| Live ČÚZK compatibility | Explicit opt-in network smoke scripts |

PHPUnit is not a project dependency. The current PHP checks are executable,
fail-fast verification scripts with assertions tailored to their fixtures.

## Safety boundary for database tests

Database verification commands are destructive to their fixture rows. They
must use a dedicated database whose name ends in `_test`, configured only
through `TEST_DB_*`. The test guard also rejects `TEST_DB_NAME` equal to
`DB_NAME` and verifies the actually connected database before cleanup or seed.

Create an optional test database and user as an administrator:

```sql
CREATE DATABASE viagem_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

CREATE USER 'viagem_test'@'127.0.0.1'
  IDENTIFIED BY 'replace-with-a-local-test-password';

GRANT ALL PRIVILEGES ON viagem_test.* TO 'viagem_test'@'127.0.0.1';
```

Then create the ignored configuration:

```sh
cp .env.test.example .env.test
```

Set its `TEST_DB_*` values, provision server-global SRS `1005514` as described
in the README, and verify/migrate the test database:

```sh
composer verify:srs:test
php bin/database.php migrate --test
php bin/database.php status --test
```

Automated tests must never point at the protected real-data E2E snapshot or any
application database that should be preserved.

## Fast deterministic checks

These checks do not require a database or network:

```sh
composer validate --strict
composer lint
composer verify:api-validation
composer verify:importer
npm run test:frontend
npm run build
```

They cover:

- Composer metadata and PHP syntax;
- strict BBOX, zoom, parcel-ID and error-envelope validation;
- GML `Polygon`/`MultiSurface`, rings, nullable metadata and malformed input;
- ZIP entry validation, bounded download retry and fail-fast source errors;
- frontend API response validation, fixed-D KÚ bootstrap and completeness;
- zoom-gated parcel requests, abort/generation guards, `too_dense`, retry and
  parcel-detail state transitions.

Vitest exercises application-owned state and request logic; it does not attempt
to simulate Leaflet rendering internals in jsdom.

## MySQL integration verification

With the isolated `_test` database configured:

```sh
composer verify:srs:test
composer verify:crs
composer verify:database
composer verify:importer-db
composer verify:importer-publication
composer verify:api
```

### `composer verify:crs`

Runs the mandatory offline CRS regression over 145 paired EPSG:5514 /
EPSG:4326 vertices from five real parcels in different parts of Jičín district.
The checked-in fixture records ČÚZK WFS provenance, INSPIRE IDs, KÚ codes and
lifespan versions. OSM is explicitly not a coordinate reference.

The regression checks both transform directions, systematic error and native
round trip. Its gates are designed to catch the former multi-metre offset. The
measured sample does not override the selected EPSG operation's declared 1 m
accuracy or claim survey-grade precision.

### `composer verify:database`

Applies migrations and checks schema metadata, SRID-restricted geometry,
relational invariants, active-dataset isolation, spatial predicates, GeoJSON
axis order and actual spatial-index use.

### Import verification

`verify:importer-db` checks transactional write/rollback behaviour with small
local fixtures. `verify:importer-publication` covers the complete 240-step
orchestration contract, validation failures, first publication, A -> B switch,
retirement and activation rollback at the transaction boundary. Neither is a
full public ČÚZK import.

### API verification

`verify:api` combines pure validation, conservative viewport-envelope checks
and routed API integration. It covers active dataset resolution, KÚ and parcel
GeoJSON, detail, empty/outside viewports, safe failures, `limit + 1`,
`too_dense` without partial data and index-plan assertions.

## Browser verification and benchmark

The reproducible browser script uses Playwright with the locally installed
**Google Chrome 152**. It is both a synthetic Leaflet performance benchmark and
a focused browser smoke:

- complete 240-KÚ bootstrap;
- desktop and mobile-size parcel viewports;
- parcel selection, metadata detail and close;
- sidebar/bottom-sheet non-overlap with controls and attribution;
- deterministic `too_dense` fallback with 240 KÚ and no parcel overlay;
- request/JSON parse/Leaflet layer timing, long tasks and JS heap.

The fixture contains 1,500 synthetic parcel polygons in the isolated test DB.
External OSM tiles are stubbed so the measurement does not depend on a public
tile service. `DB_*` for the temporary PHP server must point to the same fixture
database as the seeder's `TEST_DB_*` values.

Seed first, then run PHP and Vite in separate terminals. The PHP server's
`DB_*` values must temporarily identify the same `_test` database:

```sh
# Prepare the verified _test fixture.
composer seed:frontend-benchmark

# Terminal 1
composer dev

# Terminal 2
npm run dev

# Terminal 3, after both servers are ready
npm run benchmark:frontend

# After stopping both servers
composer clean:frontend-benchmark
```

Run the benchmark after both servers are ready, then stop them and run cleanup.
Always clean the same verified `_test` database. Detailed results and
limitations are in [PERFORMANCE.md](PERFORMANCE.md).

Final browser verification was performed in Google Chrome 152. Firefox was not
part of the final verification, and the project does not claim a general
cross-browser test matrix.

## Optional live network smokes

These are explicit pre-release compatibility checks, not deterministic test
suite dependencies:

```sh
composer smoke:cuzk
composer smoke:crs
composer smoke:importer-db
```

- `smoke:cuzk` downloads and parses one current KÚ archive without a database.
- `smoke:crs` refetches the same five control parcels from ČÚZK WFS in both
  CRSs and requires unchanged point counts and lifespan versions.
- `smoke:importer-db` imports one current KÚ into the isolated `_test` database
  and cleans its fixture rows and temporary ZIP after success.

Source changes or network availability can make a live smoke request a fixture
review rather than an application regression. No live smoke contacts OSM.

## Performance verification

The backend fixture benchmark is separate from correctness checks:

```sh
composer benchmark:api
```

It seeds and removes 20,000 simple polygons in the `_test` database, measures
bounded and deliberately too-dense API paths, and confirms spatial-index use.
It is not evidence for the real snapshot's geometry-complexity distribution.

The browser benchmark commands above are likewise synthetic. Do not present
either fixture as simultaneous rendering of all 272,768 real parcels.

## Completed and pending manual coverage

Completed evidence includes the Chrome benchmark/smoke, manual real-data E2E
that exposed the CRS issue, authoritative ČÚZK comparison and public HTTP
verification against the protected 240-KÚ / 272,768-parcel snapshot.

Broader manual checks remain useful but are not claimed as completed:

- a wider set of physical devices and viewport dimensions;
- extended touch, keyboard and long-session interaction;
- live OSM contrast under different tile content;
- system dark appearance;
- additional real-geometry density/performance scenarios.

## Test data policy

- Parser tests use small checked-in XML fixtures derived from the real CP
  structure, not full downloaded datasets.
- The 145-point CRS fixture is intentional deterministic regression evidence.
- Spatial/API tests seed only the isolated `_test` database.
- Browser performance uses a separate reproducible synthetic fixture.
- Full ČÚZK ZIP/GML, database files, credentials and local runtime artifacts
  are never committed.
- The real E2E snapshot may be read for manual verification but must never be
  reset, seeded or cleaned by automated tests.

## Explicit non-goals

The suite does not test Leaflet internals, browser tile caching, the public
availability SLA of ČÚZK/OSM, pixel-perfect screenshots, production proxy/rate
limits, schedulers or incremental-import infrastructure. Full-district
performance is a benchmark concern, not a correctness-runner assertion.
