# Performance

## Hypotheses

1. MySQL spatial index makes bounded native-CRS BBOX requests fast enough.
2. GeoJSON payload/browser rendering are more likely bottlenecks than CRS
   transformation because transform occurs only after selection.
3. KÚ boundaries eliminate parcel workload at district zoom.
4. Viewport GeoJSON is sufficient before vector tiles are justified.

`PARCEL_FEATURE_LIMIT=2000` is a preliminary server safety ceiling only. It
does not mean that 2,000 parcels, or any fixed number of parcels, is smooth to
draw: geometry vertex count and payload size can dominate the result.

## Benchmark scenarios

- Dense urban Jičín viewport;
- sparse rural viewport;
- viewport crossing KÚ boundaries;
- viewport just under parcel limit;
- intentionally too-dense viewport;
- repeated pan/zoom in Chrome and Firefox.
- the same dense viewport at zoom 16, 17 and 18, including whether a safe
  parcel overlay is returned or the API selects its KÚ fallback guardrail.

Each scenario is run with representative desktop/laptop, tablet and mobile
viewport dimensions. It is also checked on at least one representative stronger
and one weaker device where practical. This is a measurement matrix, not a
proposal for device-specific API limits: smaller mobile BBOXes may contain less
geometry, while mobile rendering capacity may still be lower.

## Metrics

| Layer | Measure |
| --- | --- |
| Database | `EXPLAIN ANALYZE`, index use, p50/p95, candidate and returned feature counts. |
| Geometry | Total polygon vertex count, vertices per feature and geometry complexity distribution. |
| CRS | Query-only versus query + `ST_Transform` for the same BBOX. |
| API | DB query, transform, GeoJSON serialisation, TTFB and total response time. |
| Network | Raw/gzip GeoJSON bytes and transfer duration. |
| Browser | JSON parse time, Leaflet render/layer replacement time, long tasks, memory and pan/zoom responsiveness. |
| Map lifecycle | Requests started/aborted/stale-discarded, layer replacement time and parcel-overlay versus KÚ-fallback rate. |
| Import | Total time, peak memory, DB/index size, validation and activation time. |

## Validation gates and warning signals

- `EXPLAIN ANALYZE` must show spatial-index access for the parcel predicate;
  a full scan for ordinary detail viewports is a blocker to investigate before
  changing delivery format.
- Measure the same BBOX in three database variants: selection only, selection
  plus `ST_Transform`, and selection plus `ST_AsGeoJSON`. Their deltas isolate
  CRS and serialisation cost.
- Test at least sparse, dense, boundary-crossing, near-ceiling and deliberately
  over-ceiling viewports, cold and warm. For each record feature count, total
  vertices, raw/gzip payload, DB query, transform/serialisation, transfer, JSON
  parse and Leaflet rendering separately.
- Warning signals are rising query/API p95 with small capped result sets,
  responses materially above the chosen byte limit, or visible browser lag on
  normal pan/zoom. Diagnose index/SQL, transform, payload and rendering in
  that order; vector tiles are considered only after this evidence.
- If ordinary zoom-gated navigation selects the KÚ fallback too often, first
  adjust the initial zoom threshold and permitted BBOX extent; then revisit the
  hard feature cap or geometry/payload optimisation. If similarly sized feature
  sets vary materially by vertex/payload complexity, evaluate an additional
  server guardrail based on measured geometry/payload complexity rather than
  trusting feature count alone. Do not introduce clustering or tiles merely
  because the guardrail was exercised once by an oversized request.

## Decision rule

Start with a provisional hard parcel ceiling and zoom threshold. Benchmark
results may change the parcel zoom threshold, hard feature limit, maximum
permitted BBOX and, if necessary, add a geometry/payload-complexity guardrail.
GeoJSON remains if realistic requests meet interaction targets across the
viewport/device matrix without visible lag. Consider a different rendering or
delivery approach (including vector tiles) only after measurement proves the
simple GeoJSON path fails despite SQL, payload and rendering optimisation.
## Phase 04 backend fixture results (2026-09-16)

The imported 272,768-parcel Jičín snapshot was not available in the running
local database during Phase 04 verification. A reproducible MySQL 8.4.11
fixture benchmark therefore used 20,000 simple rectangular parcel polygons in
one active ready dataset. Ten warm API calls were measured per scenario; the
payload size is uncompressed JSON. These figures validate the query shape and
guardrail, but do not claim full-snapshot or browser-rendering performance.

| Scenario | BBOX | MBR candidates | Exact results | HTTP | API p50 / p95 | JSON bytes | Spatial index |
| --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| Small | `15.30,50.35,15.31,50.36` | 90 | 90 | 200 | 2.862 / 3.831 ms | 23,081 | yes |
| Medium | `15.28,50.34,15.33,50.38` | 1,545 | 1,545 | 200 | 44.146 / 44.663 ms | 396,002 | yes |
| Deliberately too dense | `14.80,50.15,15.95,50.85` | 20,000 | 20,000 | 409 | 189.410 / 192.908 ms | 123 | yes |

`EXPLAIN ANALYZE` named `sp_parcel_geom_native` in every scenario. The ordinary
small/medium paths are fast enough to retain viewport GeoJSON. The medium
payload also supports the original hypothesis that transfer/render complexity
will matter before native candidate lookup. The wide request returned the
small `too_dense` error instead of parcel geometry. No cache, S2 index,
simplification or vector-tile mechanism is justified by this backend fixture;
repeat the benchmark against the real full snapshot before making production
capacity claims.

## Phase 05 browser fixture results (2026-09-17)

The full 272,768-parcel snapshot was still not available in a running local
database. Browser measurements therefore used a reproducible MySQL 8.4.11
fixture with the complete committed 240-code KÚ bootstrap and 1,500 synthetic
parcel polygons. Each parcel ring had 17 coordinates, intentionally more than
the earlier rectangular API fixture but not a claim about the real dataset's
vertex distribution. The frontend called the real Phase 04 PHP API through
the Vite proxy; only external OSM tiles were stubbed.

Google Chrome 152.0.7977.83 ran headless. One warm-up was discarded and five
warm runs were measured per viewport. `request` includes fetch and JSON parse;
`render` measures synchronous Leaflet GeoJSON layer creation and atomic layer
replacement. Payload bytes are Resource Timing decoded-body values; Vite/PHP
development serving did not gzip these local responses.

| Viewport | Returned features | Request p50 / p95 | JSON parse p50 / p95 | Leaflet render p50 / p95 | Decoded JSON | Long tasks | JS heap p50 / p95 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Desktop 1440×900 | 1,500 | 119.8 / 120.9 ms | 2.6 / 2.7 ms | 26.6 / 27.6 ms | 850,934 B | 0 / 0 | 21.38 / 21.45 MB |
| Mobile 390×844 | 1,033 | 88.3 / 94.2 ms | 1.9 / 2.1 ms | 19.8 / 20.0 ms | 586,027 B | 0 / 0 | 20.49 / 20.49 MB |

The same script selected a visible parcel, loaded the metadata-only detail,
closed it, and asserted that the desktop sidebar and mobile bottom sheet did
not overlap zoom/reset/scale controls or attribution. A deterministic
`409 too_dense` interception retained all 240 KÚ, rendered zero parcel paths
and showed no retry/error status, matching the documented silent fallback.

These results support retaining viewport GeoJSON and Leaflet for the current
implementation: even the deliberately high 1,500-feature desktop fixture had
no task over 50 ms and layer replacement stayed below 28 ms p95. They do not
validate the real snapshot's geometry-complexity distribution, weaker physical
devices, Firefox/Safari, gzip transfer, or repeated long-session memory. The
real imported snapshot and wider device/browser matrix remain required before
changing the provisional zoom threshold or claiming production capacity.

## Post-fix CRS regression check (2026-09-17)

The corrected bidirectional transformation was benchmarked on the same
isolated MySQL 8.4 test setup after the application SRS was provisioned. The
fixture is regenerated through the corrected 4326 -> 1005514 -> storage-5514
path, so the medium candidate count and JSON byte totals are not expected to be
bit-for-bit identical to the earlier inaccurate transform.

| API scenario | Before p50 / p95 | After p50 / p95 | After result / bytes | Index |
| --- | ---: | ---: | ---: | --- |
| Small | 2.862 / 3.831 ms | 3.224 / 3.764 ms | 90 / 21,230 B | yes |
| Medium | 44.146 / 44.663 ms | 49.880 / 50.076 ms | 1,547 / 364,465 B | yes |
| Deliberately too dense | 189.410 / 192.908 ms | 198.034 / 215.816 ms | 409 / 123 B | yes |

`EXPLAIN ANALYZE` continued to use `sp_parcel_geom_native`. The stored column
and indexed predicates remain native 5514; the extra work is limited to one
viewport multipoint transform, selected output geometries and a small exact-SRS
identity check when the read service is constructed. This run shows a modest
latency increase but no query-plan or guardrail regression.

The browser fixture was repeated with Node 24 / headless Chrome after the fix:

| Viewport | Features | Request p50 / p95 | Parse p50 / p95 | Render p50 / p95 | Decoded JSON | Long tasks | Heap p50 / p95 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Desktop 1440×900 | 1,500 | 118.3 / 130.5 ms | 2.7 / 2.8 ms | 28.0 / 29.1 ms | 824,774 B | 0 / 0 | 21.49 / 21.72 MB |
| Mobile 390×844 | 1,033 | 100.4 / 102.7 ms | 1.9 / 2.0 ms | 20.4 / 20.7 ms | 568,056 B | 0 / 0 | 20.56 / 20.66 MB |

The deterministic `too_dense` case again retained all 240 KÚ, rendered no
parcel overlay and produced no UI error. The differences are within normal
local development-run variance and do not change the Phase 05 delivery choice.

Finally, the real protected 240-KÚ / 272,768-parcel snapshot was exercised
through the public HTTP API. All 145 authoritative control vertices from five
parcels were found with mean 0.138 m, p95 0.204 m and maximum 0.222 m distance
from the paired ČÚZK WFS EPSG:4326 coordinates. This is a correctness result,
not an OSM-based benchmark.
