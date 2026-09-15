# Technical decisions

| Decision | Choice | Rationale | Revisit when |
| --- | --- | --- | --- |
| Scope | Whole Jičín district | Measured import is manageable: 240 KÚ / 272,861 parcels. | Scope or source volume changes. |
| Source lifecycle | ČÚZK predefined GML -> local snapshot | Reproducible demo; no runtime dependency on ČÚZK. | Live freshness becomes required. |
| Database | MySQL 8.0.32+ Spatial | Required spatial functions and CRS conversion, aligned with Viagem stack. | Benchmarks need topology-safe generalisation or advanced GIS work. |
| CRS | Native 5514; API 4326 | Keeps source/local metric geometry; transforms only selected output. | New source uses different native CRS. |
| Delivery | Viewport GeoJSON | Lowest complexity with capped payload. | Benchmarks fail after limits and optimisation. |
| Low zoom | KÚ boundaries, optional name/count tooltip | Authoritative 240-feature LOD; keeps focus on parcel polygons and avoids an extra clustering/aggregation model. | Benchmark/UX shows it does not prevent ordinary `too_dense`. |
| Parcel request threshold | Start at Leaflet zoom 17, then check actual result count | Zoom is a cheap first filter; only a capped response activates parcels, otherwise KÚ remains visible. Initial 2,000-feature ceiling is an unmeasured guardrail, not a render target. | Viewport/device benchmark, geometry complexity and payload results. |
| Map requests | `moveend` + 150 ms debounce + abort/generation guard | No requests during dragging; prevents stale response races without a cache layer. | Measurement shows repeated identical viewport work matters. |
| Detail delivery | Viewport `id` + label; metadata-only detail request | Draw/select data travel once with minimal payload; click fetches source-backed area/reference/KÚ metadata and handles snapshot changes via 404. | Detail grows enough to justify including it in viewport payload. |
| Map-first UI | Full map, KÚ fit-to-bounds shortcut, contextual detail | Keeps the assignment focused on finding and inspecting a parcel; avoids dashboard/search workflow the API does not support. | User testing identifies a concrete missing navigation need. |
| Responsive detail | Sidebar at wide widths; bottom sheet otherwise | Preserves map area on tablet/mobile without device detection. | Browser testing finds an accessibility/layout problem. |
| Basemap | Standard OSM raster tiles for local demo | Keyless familiar context and visible attribution; interactive reviewer use fits the tile policy. | Public/production traffic or provider requirements change. |
| Import consistency | Staging + validation + atomic activation | API must never expose partial data. | A proven production alternative is needed. |
| Import scope selection | Committed fixed Jičín KÚ list | Reproducible 240-KÚ full refresh; avoids runtime scraping and arbitrary scope. | A real multi-district product is required. |
| GML processing | XMLReader, two passes per KÚ ZIP | Bounded memory and no dependence on feature order; KÚ can be inserted before its parcels. | I/O measurement shows this is materially too slow. |
| Incomplete import | Stop and create a fresh snapshot on rerun | Avoids mixed source versions and resume-state complexity in a take-home task. | Production freshness/availability requires resumable imports. |
| Geometry normalisation | Store `MULTIPOLYGON` in 5514 | Source parcel `Polygon` and KÚ `MultiSurface` are normalised explicitly; one stable DB type preserves holes and index contract. | A source introduces unsupported geometry. |
| Parcel geometry type | `MULTIPOLYGON`, not `POLYGON` | INSPIRE permits `GM_Surface` or `GM_MultiSurface` for a cadastral parcel; current Czech Polygon is a valid narrower instance, not a schema guarantee. | Source profile formally narrows the contract and we consciously support only it. |
| Download retry | At most 3 attempts: 0 s, 1 s, 3 s | Retry only transient transport/408/429/5xx failures; fail fast for source, geometry and DB errors. | A production importer needs queue-based retry/alerting. |
| Frontend | Leaflet + vanilla JS + Vite | Focused map UI without framework overhead; versioned dependencies. | Requirements need richer client state. |
| Local runtime | No Docker | Not required; adds setup surface. | Local setup is not reproducible. |
| Zoom and BBOX input | Integer zoom 0..22; original longitude/latitude spans capped separately | Input validity is independent of provisional LOD and district location; padding/outside requests remain valid within size policy. | Tune span ceilings/LOD by benchmark, not the meaning of validation. |
| Complete KÚ bootstrap | One fixed district-envelope request, verify configured KÚ-code set, retain for session | Camera padding, pan and resize cannot turn a partial KÚ fetch into the permanent fallback. | Scope/configuration changes. |
| Navigation area | Padded rectangular maxBounds and responsive overview minZoom | Keeps ordinary navigation near district data without polygon clipping; backend still validates independently. | Actual viewport usability checks refine padding. |
| Conservative spatial viewport | Densified 4326 boundary -> transformed samples -> expanded 5514 envelope | Prefer extra candidates over missed edge parcels; exact predicate is relative to this envelope. | Preflight establishes step/error margin and verifies boundary fixtures; no unverified four-corner fallback. |
| Required relational keys | Mandatory NOT NULL compatible PK/FK types, checkpoint uniqueness, dataset FKs | Prevent orphan/duplicate checkpoints and cross-dataset parcel relationships; no redundant direct parcel dataset FK. | Actual DDL verification only. |
