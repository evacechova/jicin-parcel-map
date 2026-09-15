# 03 — ČÚZK import

## Goal

Build a reproducible, memory-bounded full refresh of Jičín CP data.

## Dependencies

Migrated schema, successful MySQL CRS/GeoJSON preflight, committed Jičín KÚ
scope list and confirmed source access.

## Small steps

1. Add fixed `jicin` scope metadata with the 240 KÚ codes and source base URL.
2. Create one `importing` dataset and 240 pending import checkpoints.
3. Download and integrity-check one ZIP into a run-local storage directory.
4. Use two XMLReader passes: insert its KÚ, then stream/insert its parcels.
5. Normalise source Polygon/MultiSurface to SRID-5514 WKT MultiPolygon;
   execute bounded parcel batches in one per-KÚ transaction.
6. Retry only transient downloads at 0 s, 1 s and 3 s; persist
   attempt/status/count/checksum or roll back the KÚ and fail the whole run.
7. Add complete-dataset structural, source, geometry and relationship checks.
8. Atomically publish the ready snapshot and retain one rollback snapshot.
9. Run complete 240-KÚ refresh and record factual results.

## Likely files

`bin/import-cadastral.php`, `app/Import/*`, importer tests, storage ignore
rules, README and implementation log.

## Functional result and verification

The documented command imports the complete district with bounded memory. An
intentional invalid ZIP/KÚ cannot replace the active dataset.

## Risks / confirmations

Confirm XMLReader geometry handling with a one-KÚ spike; measure whether simple
prepared-row inserts need modest batching. Do not silently skip features or KÚ,
drop shared spatial indexes, or add resume/scheduler behaviour without a new
decision.
