# Architecture

## Scope

The application displays cadastral parcels for the whole Jičín district from a
local, versioned ČÚZK INSPIRE Cadastral Parcels snapshot. It is read-only and
does not proxy ČÚZK at runtime.

## Components and flow

```text
ČÚZK CP Atom / ZIP + GML
             |
             v
PHP CLI importer -> MySQL 8.4 LTS Spatial -> PHP API -> Leaflet frontend
                 staging / validation       GeoJSON    viewport + detail
```

| Component | Responsibility |
| --- | --- |
| PHP CLI importer | Downloads, streams and validates GML one KÚ at a time; builds staging data. |
| MySQL Spatial | Stores native EPSG:5514 geometry, metadata and the active dataset pointer. |
| PHP API | Validates requests, runs indexed BBOX queries, transforms selected geometry to 4326 and returns JSON. |
| Frontend | Leaflet map, zoom-aware layers, request cancellation and parcel detail UI. |
| ČÚZK | Authoritative source during import only. |

## Runtime request flow

```text
Bootstrap: GET /cadastral-territories?bbox=D -> complete persistent KÚ layer

Leaflet `moveend` (EPSG:4326 bounds, integer zoom)
        |
        | below configured LOD            | at/above configured LOD
        v                                  v
retain KÚ; no request                  GET /parcels
                                           |
                         validate original BBOX spans + zoom + active dataset
                                           |
                         intersect BBOX with D; disjoint -> empty
                                           |
                         densify edges -> transform samples to 5514
                                           |
                         native envelope + verified outward error margin
                                           |
                                           v
MySQL SPATIAL INDEX -> MBRIntersects -> ST_Intersects -> LIMIT + 1
          |
          v
selected geometry only -> 4326 -> GeoJSON -> Leaflet layer
                                             |
                                 polygon click / inspireId
                                             v
                              GET /parcels/{inspireId} -> detail panel
```

`moveend` requests are debounced briefly, obsolete fetches are aborted and a
monotonic request generation prevents a late response from replacing a newer
viewport. The frontend makes no parcel request below the configured threshold.
Above it, a successful capped response activates the parcel overlay; a
`too_dense` response silently retains/restores the KÚ representation. It is an
API safety guard rather than the ordinary map experience.

Low zoom displays KÚ boundaries with optional name/parcel-count tooltips. KÚ
remains as context/fallback at detail zoom; parcel polygons appear only for a
safe current viewport. The browser never receives district-wide parcel
geometry. Full endpoint, BBOX, error and security contracts are in
[API.md](API.md).

The technical zoom range is integer 0..22; the initial parcel threshold 17 is
separate and benchmark-adjustable. API size limits compare original longitude
and latitude spans independently, not district containment. D is a validated
conservative dataset envelope shared by bootstrap and overview configuration.
The KÚ bootstrap has an independent controller and must finish completely
before viewport parcel requests begin; it is not repeated on pan/zoom.

The native query envelope conservatively covers the relevant viewport.
`MBRIntersects` is the coarse indexed filter; `ST_Intersects` is exact relative
to that envelope, not the original 4326 rectangle. Step/margin correctness and
MySQL support are implementation-time verification as specified in API.md.
The indexed geometry remains in 5514 and is never transformed in the predicate.
Frontend padded maxBounds and a container-dependent overview minimum zoom keep
navigation near Jičín; they do not restrict the backend's valid input locations.

## Import consistency

A full refresh builds and validates a new staging dataset before one atomic
activation changes the `active_dataset` pointer. API reads see a complete old
or new version, never a half-imported state. Incremental refresh is a production
extension outside this implementation; the current contract is manual full refresh.

## Boundaries

- PHP is the backend runtime.
- Oracle MySQL Community Server 8.4 LTS is the supported database target for
  the EPSG:5514 -> EPSG:4326 transform and spatial storage.
- The frontend uses Vite, vanilla JavaScript and Leaflet.
- Docker, Redis, a queue, vector-tile infrastructure and live ČÚZK runtime
  calls are outside the first version.

Detailed contracts are in [DATA.md](DATA.md), [API.md](API.md),
[DECISIONS.md](DECISIONS.md), [PERFORMANCE.md](PERFORMANCE.md) and
[UI.md](UI.md).
