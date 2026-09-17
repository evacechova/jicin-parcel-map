# 07 — Performance

> **Historical implementation plan.** Some phase boundaries and decisions
> evolved during implementation. See the [README](../../README.md),
> [Production Notebook](../PRODUCTION-NOTEBOOK.md),
> [Architecture](../ARCHITECTURE.md) and
> [Implementation Log](../IMPLEMENTATION-LOG.md) for the final/current state.

## Goal

Measure the real dataset and make the GeoJSON/vector-tile decision from evidence.

## Dependencies

Complete import, API and interactive parcel map.

## Small steps

1. Define reproducible BBOX scenarios from `PERFORMANCE.md`.
2. Capture MySQL `EXPLAIN ANALYZE` and p50/p95 query timing.
3. Measure query-only, query + CRS transform, and full GeoJSON serialisation.
4. Measure raw/gzip payload and browser parse/render behaviour in Chrome and
   Firefox.
5. Test repeated pan/zoom, cap boundary and `too_dense` scenarios.
6. Record results and decide whether viewport GeoJSON remains sufficient.

## Likely files

Benchmark scripts/fixtures, `docs/PERFORMANCE.md`, implementation log and
possibly API/frontend tuning files.

## Functional result and verification

Performance documentation contains measured results, bottleneck analysis and a
defensible delivery decision.

## Risks / confirmations

Do not introduce vector tiles without a recorded failing GeoJSON benchmark.
