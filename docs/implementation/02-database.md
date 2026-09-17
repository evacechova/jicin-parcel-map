# 02 — Database

> **Historical implementation plan.** Some phase boundaries and decisions
> evolved during implementation. See the [README](../../README.md),
> [Production Notebook](../PRODUCTION-NOTEBOOK.md),
> [Architecture](../ARCHITECTURE.md) and
> [Implementation Log](../IMPLEMENTATION-LOG.md) for the final/current state.

## Goal

Create versioned MySQL schema with native EPSG:5514 geometry and required
indexes, but no imported production dataset yet.

## Dependencies

Foundation; Oracle MySQL Community Server 8.4 LTS with EPSG:5514 and
`ST_Transform(..., 4326)` preflight.

## Small steps

1. Write idempotent migration mechanism and database creation instructions.
2. Create dataset metadata and active-dataset pointer tables.
3. Create KÚ and parcel tables with source IDs and native geometry columns.
4. Add the mandatory FK/unique/NOT NULL invariants from DATA.md, including
   dataset references and one import checkpoint per dataset/KÚ; add the
   documented B-tree and spatial indexes after confirming MySQL DDL.
5. Add a minimal fixture dataset only if integration tests need it.
6. Verify SRID restrictions, index plan and migration rollback/reapply path.

## Likely files

`database/migrations/*`, `app/Database/*`, database test helpers and README.

## Functional result and verification

A fresh database migrates cleanly; a small test BBOX query uses the spatial
index and respects the active dataset pointer.

## Risks / confirmations

Verify the documented key types and constraints against MySQL DDL; measure
the spatial-index plan. Required relationship keys are not nullable and their
types are already fixed in DATA.md.
