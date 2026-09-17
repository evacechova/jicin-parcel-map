# Testing strategy

## Goal and boundary

The goal is confidence in risky contracts, not 100% coverage. Correctness tests
are deterministic and use fixtures/test data. They do not measure performance:
query plans, p95 latency, full Jičín import time and rendering capacity remain
in [PERFORMANCE.md](PERFORMANCE.md).

## Recommended stack

| Layer | Tool | Why |
| --- | --- | --- |
| PHP unit, parser and API integration | PHPUnit | Standard PHP runner with data providers; no additional framework. |
| Frontend unit/integration | Vitest + jsdom | Natural Vite fit for DOM and request-state logic. |
| Browser E2E | Playwright | Reliable auto-waiting, browser control and request interception. |
| Spatial correctness | PHPUnit against real MySQL test schema | Mocks cannot prove SRID, spatial predicates or transforms. |

No Docker or hosted test infrastructure is required. Integration tests use a
dedicated local MySQL schema such as `viagem_test`, created from migrations and
reset only by explicit test commands. It must never point at the development or
imported database.

## Testing pyramid

### PHP unit tests

Test pure logic only:

- BBOX parsing: valid values; wrong field count; blank/non-numeric/non-finite
  values; `min >= max`; coordinate ranges; antimeridian; maximum extent.
- Zoom and path-ID validation, including boundary values and missing/invalid input.
- Zoom syntax accepts only canonical integer strings 0..22; reject decimals,
  signs, whitespace, leading zeros, arrays and duplicates. A valid value below
  the configured LOD threshold yields 422 rather than 400.
- BBOX limits compare original width/height in degrees separately: equality
  passes, either excess fails, and a thin long rectangle cannot bypass them.
  Verify policy precedence and reject duplicate/array BBOX input.
- API error envelope: stable code, safe message, request ID, no leaked details.
- Small pure formatting/normalisation helpers.

Use PHPUnit data providers. Do not unit-test PDO, MySQL, Leaflet or HTTP server
internals.

### Importer/parser tests

Use tiny deterministic GML fixtures derived from real CP element structure, with
the required namespaces and attributes. They are kilobytes, not copied
production files. One small reviewed CP excerpt may remain as a compatibility
fixture.

Without DB, test valid `CadastralZoning` and parcel extraction;
`gml:Polygon` to one-member `MULTIPOLYGON`; `gml:MultiSurface` to
`MULTIPOLYGON`; an interior ring; nullable technical metadata; missing required
values; unsupported/bad geometry; and malformed XML/GML. A small ZIP fixture
tests `ZipArchive` entry selection and stream entrypoint.

The parser deliberately accepts only the documented CP surface contract, not
every theoretical GML/INSPIRE type. Database insertion and rollback belong in
integration tests.

### Database/spatial integration tests

Run against supported real MySQL after provisioning and verifying application
SRS `1005514`, then applying migrations in `viagem_test`. Seed two
datasets and a few named EPSG:5514 polygons: inside, outside and a shape whose
MBR overlaps a viewport while its actual polygon does not.

Required assertions:

- SRID-restricted 5514 columns and spatial indexes exist.
- Reject duplicate `(dataset_id, ku_code)` import checkpoints, orphan
  territory/checkpoint dataset references, null required relationship keys,
  and a parcel referencing a territory in another dataset. Referenced parent
  deletion is restricted. Checkpoint retries update the existing unique row.
- `MBRIntersects` + `ST_Intersects` returns inside, excludes outside and excludes
  the MBR-only false positive.
- Stored SRID is 5514; transient 5514 -> 1005514 relabelling followed by
  `ST_Transform(...,4326)` succeeds; returned GeoJSON is
  `[longitude, latitude]` within the authoritative tolerance.
- Active-pointer query exposes only dataset A, then only dataset B after a
  pointer switch; importing/inactive rows never appear.
- S2 boundary fixtures: place parcels crossing each of the four true viewport
  edges and corners, including locations between densification samples and
  curved-edge extrema. All must survive the conservative envelope query.
  Include thin/tiny BBOXes, D-edge contact, partial overlap with D and the
  largest permitted relevant domain. Use independently reviewed control
  geometry/reference coordinates, not only the production helper to generate
  expected results. Assert envelope coverage, not merely agreement between
  two calls to the same approximation.
- Verify native query margin/step and coordinate-order handling on actual
  MySQL (including transformed MULTIPOINT); record the justification for the
  error bound. Dense sampling is supporting evidence, not a proof of a bound
  between samples. A failed coverage preflight blocks serving spatial data.
- Allow documented false positives outside the original viewport but inside
  Q; keep the existing MBR-only exclusion assertion relative to Q. Assert
  that enlarging the conservative query does not clip returned geometry.
- Dataset validation rejects geometry outside the committed D envelope;
  a fixed-D KÚ request returns the whole seeded scope, not a padded viewport
  subset. No valid dataset is activated with unsafe envelope metadata.

This is the correctness form of the spatial preflight. It is not `EXPLAIN
ANALYZE` or a latency test; those belong to benchmarks.

### CRS regression and live authority check

`composer verify:crs` is the mandatory offline regression. Its checked-in
fixture contains 145 paired EPSG:5514 / EPSG:4326 vertices from five real
parcels in different parts of Jičín district, together with INSPIRE IDs, KÚ
codes and `beginLifespanVersion`. ČÚZK WFS is the authority; OSM is explicitly
not a coordinate reference. The test checks both directions and rejects:

- forward or reverse maximum error above 0.50 m;
- the magnitude of the mean east/north error vector above 0.25 m;
- native -> public -> native round-trip error above 0.01 m.

These gates follow the measured 0.22–0.24 m maximum of the selected standard
operation and are tight enough to catch the former multi-metre systematic
offset. EPSG's declared operation accuracy is 1.0 m; the sample result does not
override that specification or claim survey-grade precision.
`composer verify:srs:test` separately checks the exact provisioned SRS identity
before the regression runs.

`composer smoke:crs` is a one-time/pre-release online check, not part of the
deterministic suite. It refetches exactly those five parcels in both CRSs from
the current ČÚZK WFS and requires the same 145 coordinates and unchanged
lifespan versions; a source update asks for fixture review instead of silently
rewriting expected values.

### API integration/contract tests

Exercise the routed PHP application against the seeded test schema. An
in-process request handler is sufficient; a web server per assertion is not
necessary. Assert status, content type and selected JSON structure—not large
GeoJSON snapshots.

| Case | Required assertion |
| --- | --- |
| `GET /cadastral-territories` | `200`, GeoJSON FeatureCollection, KÚ geometry, `ku_code`, `name`, `parcel_count`. |
| `GET /parcels` | `200`, FeatureCollection, geometry, stable Feature `id`, `label`, no repeated detail fields. |
| `GET /parcels/{id}` | `200`, documented detail fields; no technical/source-only fields. |
| Bad BBOX / zoom / parcel ID | `400` plus uniform safe error envelope. |
| Zoom policy / overlarge BBOX | `422` and stable error code. |
| Valid partial/outside BBOX within policies | Partial dataset matches or empty `200`; no location-validation error. Disjoint from D is empty without CRS transformation. |
| Outside BBOX exceeding a span ceiling | `422 bbox_too_large`; outside-location shortcut cannot bypass limits. |
| Missing parcel | `404 parcel_not_found`. |
| Seed above limit | `409 too_dense`, no partial FeatureCollection. |
| No ready dataset / controlled exception | `503` / generic `500`, no stack/database detail. |

### Frontend unit/integration tests

Do not test Leaflet. Isolate our LOD/request coordinator behind a thin map-view
adapter and use Vitest/jsdom with spies/fake fetch.

- Below threshold: KÚ state and no parcel request; above it: parcel request.
- Successful parcel response activates overlay and makes KÚ fill contextual.
- `too_dense` restores/keeps KÚ with no error toast.
- New viewport aborts earlier request; stale response cannot replace newer state.
- Parcel click starts detail request; test loading, success, `404` and close.
- Network/500/503 retains last usable representation and exposes retry.
- KÚ click-to-fitBounds is a small adapter interaction.
- Bootstrap always requests fixed D, validates the complete unique KÚ-code
  set and retains it across pan/zoom/resize/reset. Navigation cannot cancel
  bootstrap; retry requests D again. After success, fetch parcels only for
  the latest viewport. A partial/failed bootstrap never enables parcels.

Do not simulate Leaflet wheel, geometry hit-testing or tiles in jsdom.

### Small E2E layer

Use Playwright for two high-value scenarios. It fits Vite and can
intercept/disable tile requests so OSM is not a test dependency.

1. Happy path: open -> KÚ visible -> select KÚ -> map navigation completes ->
   parcels available -> select parcel -> detail shows parcel number, area and
   KÚ -> close detail.
2. Graceful fallback: deterministic over-limit API response -> KÚ remains,
   parcel overlay is absent and no error toast appears.

Responsive edge cases, detailed keyboard handling and all error permutations
are more valuable as unit/manual checks than many browser scripts.

## Manual pre-submission checklist

- [ ] Clean local setup, migrations, seed and documented test commands work.
- [ ] Desktop/tablet/mobile: map remains dominant; panel/sheet does not cover controls or attribution.
- [ ] Mouse/trackpad, touch pinch/drag, double-click, reset and KÚ fitBounds work naturally.
- [ ] Padded maxBounds prevent ordinary navigation far outside the district;
      keyboard/inertia/touch also settle inside the bounds. Overview keeps all
      KÚ visible on narrow/wide screens without clipping to the district shape.
- [ ] Resize/orientation recomputes overview minZoom; “Celý okres” restores
      the full padded view. Large viewport dimensions do not trigger a partial
      KÚ reload; parcel size-guard fallback is silent and complete.
- [ ] KÚ and parcel selected/default contrast is readable over real OSM tiles.
- [ ] Touch targets are about 44px; essential information is not hover-only.
- [ ] Keyboard focus and close/reset/retry controls work; reduced motion is respected.
- [ ] Empty, `too_dense`, network/500/503 and detail-404 keep meaningful map context.
- [ ] OSM attribution is visible; tiles are not prefetched.
- [ ] Source scope and known limitations are clear in README.

## Test data strategy

- Unit/parser: checked-in small XML/GML/ZIP fixtures; no live ČÚZK/internet.
- Spatial/API: migrations plus deterministic seed with a few 5514 polygons,
  two datasets and explicit active pointer.
- Automated E2E: same seeded isolated `*_test` API data; intercepted/stubbed
  basemap tiles.
- Final manual E2E may use the protected local `viagem_e2e` real ČÚZK snapshot;
  automated tests must never reset, seed or clean it.
- Benchmark: separate local full Jičín snapshot; never required for correctness.

## Explicitly not tested

- Leaflet internals, browser tile caching or MySQL spatial-index implementation;
  test our calls and observable results instead.
- Live ČÚZK/Atom/OSM availability or tile SLA in automated tests.
- Pixel-perfect screenshots, visual-regression suite and 100% coverage.
- Production proxy/rate limit, queues, scheduler and incremental-import systems.
- Full-district performance in the correctness runner.

These are intentional scope decisions, not claims the concerns are irrelevant in
production.

## Open implementation confirmations

- Keep route-handler/service separation testable without adding a framework.
