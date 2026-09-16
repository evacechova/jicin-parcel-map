# 04 — API

## Goal

Implement the documented read-only API, including access rules, safe errors and
integration with an active imported dataset.

## Dependencies

Foundation, migrated schema and a validated dataset (fixture or imported).

## Small steps

1. Add routing, JSON response helper, request ID and central error mapper.
2. Implement active-dataset availability handling and strict BBOX/zoom/path validation.
   Follow API.md's integer zoom, original span ceilings, outside-scope empty
   shortcut and fixed-D bootstrap contract; location is not an input restriction.
3. Implement KÚ GeoJSON endpoint and tests.
4. Implement capped parcel viewport endpoint with indexable spatial query.
   Build the conservative densified/enlarged native envelope from API.md and
   pass the S2 boundary preflight before serving geometry.
5. Implement metadata-only parcel detail endpoint.
6. Add same-origin/CORS configuration and request lifecycle tests.

## Likely files

`app/Http/*`, `app/Service/*`, `app/Geo/*`, API integration tests and README.

## Functional result and verification

The three documented endpoints return their contract. Invalid input, absent
data, too-dense BBOX and unexpected errors use the agreed status/error envelope.

Implemented in Phase 04 with framework-free `app/Http`, `app/Api`, `app/Geo`
and `app/Read` layers. `composer verify:api` runs pure validation plus real
MySQL 8.4 spatial/API tests; `composer benchmark:api` is the separate 20k-row
fixture benchmark. Full details and measured results are in the implementation
log and `PERFORMANCE.md`.

## Risks / confirmations

Confirm `MBRIntersects` + `ST_Intersects` index plan on actual MySQL before
declaring viewport performance acceptable.
