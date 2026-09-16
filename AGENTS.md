# Repository agent instructions

## Protected local E2E snapshot

On the current development workstation, the following MySQL 8.4 snapshot is a
durable local E2E/development asset containing genuinely imported ČÚZK data:

- runtime directory: `/private/tmp/viagem-mysql84-phase05-e2e`
- MySQL datadir: `/private/tmp/viagem-mysql84-phase05-e2e/data`
- socket: `/private/tmp/viagem-mysql84-phase05-e2e/mysql.sock`
- TCP port: `33313`
- database: `viagem_e2e`
- active dataset: ID `1`, scope `jicin`, status `ready`, validation `valid=true`
- contents: exactly 240 cadastral territories and 272,768 parcels
- provenance: public Phase 03 CLI full import from ČÚZK, not a synthetic fixture

Treat this snapshot as protected even when its MySQL process is stopped:

- Never delete its runtime directory, datadir, database, dataset, active slot,
  territories, parcels, checkpoints, or validation report during automated
  cleanup.
- Never seed a synthetic fixture into `viagem_e2e`, and never point destructive
  integration/performance tests or `TEST_DB_*` variables at it.
- Automated tests that reset data must use a separate isolated datadir and a
  separate database whose name ends in `_test`. Resolve and verify that target
  before running any reset or cleanup command.
- MySQL, PHP, and Vite processes may be stopped after manual work, but preserve
  the datadir so the real snapshot can be restarted without another full
  import.
- Do not commit database files, downloaded source archives, local environment
  files, or credentials. If runtime credentials are unavailable later, create
  or reset only a local application account through the protected instance's
  root socket; do not recreate or clear the database.
- If the observed local state differs from this record, report the discrepancy
  before mutating anything.
