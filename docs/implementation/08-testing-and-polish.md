# 08 — Testing and polish

> **Historical implementation plan.** Some phase boundaries and decisions
> evolved during implementation. See the [README](../../README.md),
> [Production Notebook](../PRODUCTION-NOTEBOOK.md),
> [Architecture](../ARCHITECTURE.md) and
> [Implementation Log](../IMPLEMENTATION-LOG.md) for the final/current state.

## Goal

Make the assignment reproducible, understandable and presentable.

## Dependencies

All functional phases and their benchmark conclusion.

## Small steps

1. Implement PHPUnit unit/parser fixtures for validation, BBOX, error mapping
   and CP Polygon/MultiSurface mapping.
2. Run MySQL spatial correctness integration tests with deterministic 5514
   seeds, transform/GeoJSON-axis preflight and active-snapshot isolation.
3. Complete API contract tests against the seeded schema, including `too_dense`
   without partial results and safe error envelopes.
4. Implement Vitest map-request/detail state tests and two Playwright E2E
   scenarios from [TESTING.md](../TESTING.md).
5. Run the manual responsive/accessibility/map checklist from
   [TESTING.md](../TESTING.md).
6. Review security headers, CORS development behaviour and secret handling.
7. Complete README: prerequisites, migrations, import, run, tests, source
   attribution and known limitations.
8. Update architecture/data/API/decision/performance documents and implementation
   log with actual results.
9. Perform a clean-machine setup rehearsal.

## Likely files

`tests/*`, parser fixtures, database test seed, Playwright config, `README.md`,
`docs/*` and minor application fixes found by tests.

## Functional result and verification

Another developer can set up, import, run and evaluate the project from README;
all documented test commands and benchmark references are reproducible.

## Risks / confirmations

Keep scope focused: correctness tests do not become performance benchmarks, and
polish does not authorise unmeasured features or a late framework/database
change.
