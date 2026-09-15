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
There are no results yet.
