# API

## Contract principles

- Version prefix: `/api/v1`; only `GET` endpoints exist.
- The API reads only the one active `ready` dataset. A client never supplies a
  dataset ID, CRS, SQL fragment, source URL or arbitrary filter.
- Map geometry is GeoJSON in EPSG:4326. Native MySQL geometry remains 5514.
- The response limit, zoom threshold and permitted BBOX extent are server
  constants. A client may request data, but cannot expand the safety envelope.

The initial server constants are intentionally conservative and benchmarkable:
`PARCEL_MIN_ZOOM=17`, `PARCEL_FEATURE_LIMIT=2000`,
`TERRITORY_FEATURE_LIMIT=240`, and endpoint-specific maximum longitude and
latitude spans defined below. These are size limits, not containment rules.
The native-envelope correctness pair is `EDGE_SAMPLE_STEP_DEGREES=0.01` and
`QUERY_MARGIN_METRES=25`.
The threshold is duplicated as a small frontend
build constant and enforced by the API; a separate metadata endpoint would add
an initial request without enabling a needed UI. The frontend's normal
zoom-gated flow should not hit the parcel limit; `too_dense` is a
direct-client/API guardrail. **2,000 is only an unmeasured hard ceiling against
unbounded responses, not a claimed safe browser-rendering target.**

## Endpoints

### `GET /api/v1/cadastral-territories?bbox=minLng,minLat,maxLng,maxLat`

`bbox` is required. The endpoint returns a GeoJSON `FeatureCollection` of
intersecting KÚ boundaries, at most 240 features. Properties are `ku_code`,
`name` and `parcel_count`. `parcel_count` comes from the completed
import checkpoint, not an expensive live count of all parcels. The frontend may
show it in a hover/click tooltip; labels are not required on every boundary.

```json
{
  "type": "FeatureCollection",
  "features": [{
    "type": "Feature",
    "id": "659541",
    "properties": { "ku_code": "659541", "name": "Jičín", "parcel_count": 12286 },
    "geometry": { "type": "MultiPolygon", "coordinates": [] }
  }]
}
```

Returns `200`, `400 invalid_bbox`, `422 bbox_too_large`, `503
dataset_unavailable`, or `500 internal_error`. An empty FeatureCollection is a
valid `200` response.

### `GET /api/v1/parcels?bbox=minLng,minLat,maxLng,maxLat&zoom=17`

Both parameters are required. The response is a GeoJSON `FeatureCollection`
with at most 2,000 complete parcel features. This is an initial hard safety
ceiling, not a UX capacity promise; benchmark results may lower or otherwise
refine it. Its intentionally small property
set supports drawing and selecting: GeoJSON `Feature.id` is the stable
`inspire_id` for a later detail request and the only property is `label`.

```json
{
  "type": "FeatureCollection",
  "features": [{
    "type": "Feature",
    "id": "CP.99632534010",
    "properties": { "label": "st. 4678" },
    "geometry": { "type": "MultiPolygon", "coordinates": [] }
  }]
}
```

Returns `200`, `400 invalid_bbox`/`invalid_zoom`, `422 zoom_too_low` or
`bbox_too_large`, `409 too_dense`, `503 dataset_unavailable`, or `500
internal_error`. The query reads `limit + 1`; the extra feature is not returned.
If present, it produces `409 too_dense` rather than a silently partial map.

### `GET /api/v1/parcels/{inspireId}`

`inspireId` is URL-decoded once, maximum 64 characters, and must match the
allowlisted local-ID character set `[A-Za-z0-9._-]+`. The endpoint returns only
metadata because the selected polygon is already in the viewport response:

```json
{
  "data": {
    "inspire_id": "CP.99632534010",
    "label": "st. 4678",
    "area_m2": 5.0,
    "national_cadastral_reference": "659541-st. 4678",
    "cadastral_territory": { "ku_code": "659541", "name": "Jičín" }
  }
}
```

The stored source `beginLifespanVersion`, `validFrom`, `referencePoint` and
source URLs are deliberately not returned: they are technical/audit metadata,
not useful parcel information in this map UI. No owner, land use, price or
invented parcel type is returned. Statuses are `200`, `400
invalid_parcel_id`, `404 parcel_not_found`, `503 dataset_unavailable`, and
`500 internal_error`.

## BBOX contract and indexed query

### Zoom syntax and policy

The parcel endpoint requires exactly one scalar `zoom` query parameter. Its
decoded value must match `^(0|[1-9][0-9]*)$` and represent an integer in the
inclusive technical range `0..22`. Missing, repeated, array, signed, whitespace,
decimal (`17.0`), exponent (`1e1`), leading-zero (`017`) and out-of-range values
return `400 invalid_zoom`. This is an API contract, not a tile-provider promise.
The frontend uses `zoomSnap=1` and sends the settled integer zoom on `moveend`;
its configured maximum may be lower than 22 to match the chosen basemap.

Separately, a valid zoom below `PARCEL_MIN_ZOOM` returns `422 zoom_too_low`.
That LOD threshold starts at 17 and can change after benchmarking, independently
of the technical input range. The KÚ endpoint does not require zoom.

### Scope, request size and complete KÚ bootstrap

`DISTRICT_BOUNDS_4326` (D) is a committed, conservative rectangular envelope
containing all KÚ and parcel geometry in the fixed Jičín scope. It is shared
as configuration by the API and frontend; no new metadata endpoint is needed.
Import validation must confirm this containment before activation (see DATA.md).
D is the overview/bootstrap extent, not an allowed-location restriction on
client input. Its committed value is `[14.80, 50.15, 15.95, 50.85]` in
min-longitude, min-latitude, max-longitude, max-latitude order. Frontend
navigation bounds are a separately padded copy of D.

For the original, unclipped request BBOX B, calculate exactly:

```text
width_deg  = maxLng - minLng
height_deg = maxLat - minLat
too_large  = width_deg > endpoint.MAX_LNG_SPAN_DEG
          OR height_deg > endpoint.MAX_LAT_SPAN_DEG
```

Both limits must be positive finite server-owned constants. Equality passes.
There is no area-only test, metre conversion, aspect-ratio test or requirement
that B lie inside D or the district polygon. Long thin requests cannot evade
the independent span limits. Initial limits for both endpoints are D's width
and height respectively. Parcel limits may be tuned by benchmark; KÚ limits
must always admit D so the fixed bootstrap request succeeds. These initial
limits are not measured performance targets. Changing navigation padding does
not implicitly enlarge API limits.

After valid syntax and endpoint policies, with an available active dataset:

| Request | Result |
| --- | --- |
| Partly outside the district | `200` with matching dataset features (including the small conservative spatial margin described below); never reject for location. |
| Wholly outside dataset coverage | Empty `200 FeatureCollection`; location is not an error. A query immediately beside data may include edge false positives from the conservative margin. |
| Invalid BBOX syntax/ranges/order | `400 invalid_bbox`. |
| Either original BBOX span exceeds its endpoint limit | `422 bbox_too_large`, even if outside D. |

Empty success does not override invalid zoom, size policy or unavailable data.
For deterministic validation, check BBOX syntax, zoom syntax (parcels only),
BBOX size, then parcel LOD policy, then resolve the active ready dataset.
Only afterwards perform the empty shortcut or spatial query.

Bootstrap always calls `GET /api/v1/cadastral-territories?bbox=D`, not the
current padded screen bounds. It returns all configured 240 KÚ with full
geometry. The frontend checks that the returned unique KÚ-code set matches
the committed scope before publishing it as the persistent fallback. A partial
bootstrap is a retryable data-load failure, never a complete fallback.
The camera's padding/aspect ratio and pan/resize cannot change this request.

### Coordinate syntax and conservative native query

The Leaflet frontend always sends EPSG:4326 in this exact order:

```text
minLng,minLat,maxLng,maxLat
```

PHP performs the following, in order:

1. Require one scalar BBOX parameter, split into exactly four finite decimal
   values, each matching `^-?[0-9]+(\.[0-9]+)?$`. No spaces, plus signs,
   exponent notation, empty values, arrays or duplicate parameters are allowed.
2. Check longitude `[-180,180]`, latitude `[-90,90]`, `minLng < maxLng`,
   `minLat < maxLat`, no antimeridian crossing, and endpoint-specific maximum
   BBOX extent.
3. After policy validation and resolving one active ready `dataset_id`,
   intersect B with D in 4326. If their closed rectangles are disjoint, return
   empty without transforming coordinates elsewhere in the world. Do not use
   clipping to bypass the original request-size check. Boundary-only contact
   is not disjoint and must remain a point/line query input.
4. Build the conservative native query envelope Q by the method below, using
   only validated coordinates bound as WKT data. Do not transform the indexed
   stored geometry in the predicate.
5. Query the native indexed column using `MBRIntersects(geom_native,Q)` for
   R-tree candidates and `ST_Intersects(geom_native,Q)` for exact intersection
   with Q, not for an exact test against the original screen rectangle.
6. Apply `LIMIT + 1`; only after selection transform each returned geometry to
   4326 and serialise it with `ST_AsGeoJSON`.

#### S2 decision: densified boundary -> expanded native envelope

Choose a small PHP geometry helper plus MySQL coordinate transformation:

1. Sample all four edges of B intersected with D, including corners. Subdivide
   each edge so consecutive samples are at most the configured angular step
   apart; use linear interpolation in longitude/latitude, not geodesic arcs.
   A boundary-only line/point intersection is sampled without inventing area.
2. Send the samples as a bound 4326 `MULTIPOINT` with explicit
   `axis-order=long-lat`; transform that one input geometry to 5514.
3. Compute min/max native X/Y over the transformed samples in PHP and expand
   each side outwards by `QUERY_MARGIN_METRES`. Construct Q as a native 5514
   WKT rectangle with explicit SRID-defined axis order. No geographic
   `ST_MakeEnvelope` or MySQL geometry-buffer dependency is required.

The angular step and positive margin are a correctness pair, not benchmark
controls: the margin must cover the maximum unsampled boundary excursion and
numeric transformation error over the bounded Jičín domain for that step.
Q must contain the transformed relevant viewport, including its interior and
boundary. A smooth, nonsingular local transformation and that error bound must
be verified in the spatial preflight. Four transformed corners, or a fixed
number of edge samples with no justified margin, do not satisfy this contract.

This establishes the no-false-negative invariant: every relevant viewport
point lies in Q, so a parcel intersecting the viewport also intersects Q.
The MBR candidate filter cannot discard such a parcel. `ST_Intersects` then
removes MBR-only false positives relative to Q. Since Q is an enclosing
rectangle, some features outside the original viewport may remain; this is
accepted and counted towards the feature cap. Geometry is never clipped in
the response. There is no second exact original-viewport filter that could
reintroduce edge omissions.

| Small alternative | Decision |
| --- | --- |
| Transform only four corners | Reject: nonlinear edges can escape the approximation. |
| Densify and use the transformed polygon directly | Do not choose: chord errors still need an outward safety construction. |
| Densify, take native min/max and expand outwards | Choose: a simple rectangle, explicit safety margin and existing spatial predicates; accepts extra candidates. |

**IMPLEMENTATION-TIME VERIFICATION:** establish and record the supported step
and conservative error margin on the actual MySQL transformation; verify
MULTIPOINT input/output, axis order and fixtures along every edge, corners,
thin viewports and D boundaries. Dense reference sampling and fixtures are
regression evidence, not by themselves a mathematical bound on unsampled
extrema. If the bound/coverage cannot be justified, the spatial preflight fails;
do not silently fall back to four corners or claim correctness from timing.
No general GIS engine, PROJ dependency, new CRS or database change is planned.
The reasoning behind edge densification is also documented by
[PROJ's bounds transformation](https://proj.org/en/stable/development/reference/functions.html#c.proj_trans_bounds).

Phase 04 verified the chosen 0.01°/25 m pair on MySQL 8.4.11 with independent
dense transformed samples over D, small and thin viewports and point/line
contacts. No dense sample escaped the unexpanded coarse envelope at the
reported precision; the 25 m outward margin therefore remains deliberately
conservative. Boundary-crossing parcel fixtures cover all four edges and a
corner. `EXPLAIN ANALYZE` on both correctness and benchmark data confirmed use
of `sp_parcel_geom_native` rather than relying on index existence alone.

```sql
-- :query_envelope_wkt is Q, already built by the conservative helper.
WITH viewport AS (
  SELECT ST_GeomFromText(:query_envelope_wkt, 5514,
    'axis-order=srid-defined') AS geom_5514
)
SELECT p.inspire_id, p.label,
       ST_AsGeoJSON(ST_Transform(p.geom_native, 4326)) AS geometry_json
FROM viewport AS v
JOIN parcel AS p
  ON MBRIntersects(p.geom_native, v.geom_5514)
 AND ST_Intersects(p.geom_native, v.geom_5514)
WHERE p.dataset_id = :active_dataset_id
ORDER BY p.inspire_id
LIMIT :hard_limit_plus_one;
```

The KÚ endpoint uses the same BBOX construction and two-stage spatial predicate
over `cadastral_territory.geom_native`.

## Map level of detail

Below the configured parcel threshold, the map shows the complete retained
KÚ boundaries without another KÚ request. It may expose the KÚ name and parcel
count through a tooltip or a label where there is room; it does not send parcel
geometry. At or above the threshold, it retains the KÚ layer as context and
*attempts* a parcel request for the current viewport. Only a
successful response at or below the parcel cap makes the parcel overlay active;
`too_dense` leaves the KÚ representation active and is retried naturally after
the next completed zoom/pan.

This is preferred over parcel-point clustering: clustering would require a
second representation/aggregation of 272k parcel points and teaches the user
to interact with clusters although the target is a polygon. A grid/hex
aggregation has the same extra data model and styling cost. KÚ is an
authoritative, meaningful, bounded (240-feature) level of detail that already
exists in the source.

Zoom 17 is a starting UX/performance threshold, not an assumption of measured
capacity. Benchmarking will test 16, 17 and 18 in dense Jičín and rural areas,
then adjust the threshold and the result cap together so ordinary movement does
not produce `too_dense`.

## Request lifecycle

1. App creates Leaflet at the padded district overview with the navigation
   bounds from UI.md. It starts the fixed-D KÚ bootstrap using its own request
   controller. Map navigation never aborts this bootstrap. Retry repeats D;
   parcel requests wait until the complete fallback is available. On success,
   retain that layer for the session and refresh the latest viewport once.
2. It listens to `moveend` only (not every `move`); this covers completed pan
   and zoom. A short 150 ms debounce coalesces programmatic initial events.
3. A refresh aborts the prior in-flight parcel controller, increments
   a monotonically increasing request generation and reads the current bounds
   and zoom. No request is sent during drag animation.
4. Below the configured parcel threshold it ensures the retained KÚ layer is
   shown and makes no map-data request. At/above it, request parcels for the
   current BBOX while leaving KÚ boundaries/context available.
5. The request shows a non-blocking map loading state. Existing successful
   geometry remains visible but is visually subdued until the replacement is
   ready, preventing flicker.
6. An aborted request is silent. A response may update a layer only if its
   generation is still current; stale responses are ignored even if aborting
   lost a race.
7. A successful parcel response atomically replaces the parcel overlay and
   makes KÚ fills transparent while retaining their outlines. An empty `200`
   clears only the parcel overlay. `409 too_dense` clears/demotes any parcel
   overlay and restores KÚ fill without an error toast. A network/server error
   retains the last usable representation and offers one retry action.
8. Clicking a parcel uses its viewport `inspireId`, marks the polygon selected,
   opens a loading detail panel and calls its metadata endpoint. A newer click
   aborts/invalidates the prior detail request by the same generation rule.

The initial KÚ layer is retained for the page session because it is a bounded,
meaningful fallback representation. No parcel-BBOX cache is needed initially:
aborting and `moveend` already remove most duplicate work, while cache
invalidation must respect active dataset changes. Browser tile caching handles
the basemap. Revisit a small cache keyed by active dataset plus normalized BBOX
only if measurements show repeated viewport requests are material.

## Errors and user behaviour

Every API error uses this safe JSON envelope:

```json
{
  "error": {
    "code": "too_dense",
    "message": "Viewport exceeds the parcel representation limit.",
    "requestId": "01J..."
  }
}
```

| Status / code | API meaning | Frontend behaviour |
| --- | --- | --- |
| `400 invalid_bbox`, `invalid_zoom`, `invalid_parcel_id` | Invalid client input. | Do not retry automatically; log with request ID. It should not occur through normal UI. |
| `404 parcel_not_found` | Selected parcel is absent from the current snapshot. | Clear selection and say it is no longer available; do not treat as server outage. |
| `409 too_dense` | Valid request conflicts with the bounded parcel representation. | Silently keep/restore KÚ and wait for the next viewport change; frontend branches on `error.code`, never on message text. |
| `422 zoom_too_low`, `bbox_too_large` | Valid syntax outside endpoint policy. | Do not retry; return to low-detail behaviour. |
| `503 dataset_unavailable` | No ready snapshot or DB temporarily unavailable. | Keep last data; show retryable service message. |
| `500 internal_error` | Unexpected server failure. | Keep last data; show generic retryable message and request ID. |
| browser network failure/timeout | No trustworthy HTTP response. | Keep last data; show retry action; do not expose technical transport detail. |

Server logs correlate request ID with safe route/status/timing context. They do
not expose stack traces, credentials, raw SQL or database details to clients.

## Public API protection

### Required for the assignment

- Strict route, method, path-ID, BBOX and zoom validation; finite response/BBOX
  caps; no arbitrary CRS or data-source input.
- PDO prepared statements; server-owned SQL, table names, limits and SRIDs.
- Read-only public routes; the importer remains CLI-only and has no HTTP route.
- JSON responses with `application/json`; frontend inserts source attributes as
  text, never HTML, preventing source-derived XSS.
- Secrets only in untracked `.env`; public frontend configuration contains no
  database credentials or pretend API key.

### Useful locally, but deliberately small

- Same-origin production deployment. Development CORS permits only the exact
  configured Vite origin, `GET`/`OPTIONS`, no wildcard and no credentials.
- A request ID and safe central error mapper. PHP file routing never maps a URL
  directly to filesystem paths, so path traversal is not a feature surface.

### Production concern, not an assignment dependency

Per-IP rate limiting belongs behind a reverse proxy/gateway or shared store.
It is documented but not simulated with a fragile per-process PHP counter. If
the public demo is exposed beyond a reviewer, add a small proxy limit then.
