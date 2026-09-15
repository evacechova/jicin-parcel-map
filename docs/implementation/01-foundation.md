# 01 — Foundation

## Goal

Create a reproducible PHP/Vite skeleton without business features.

## Dependencies

Approved architecture and API contract.

Before implementation, perform the repository/remote/branch/authentication
checks in [IMPLEMENTATION-PLAN.md](../IMPLEMENTATION-PLAN.md#gitgithub-workflow-for-implementation).
Use the preferred `jicin-parcel-map` name and obtain confirmation before
creating a missing repository or changing a mismatched remote. Then follow
the documented verify/review/commit/push workflow for each logical change.

## Small steps

1. Add Composer autoloading and a minimal HTTP entry point.
2. Add Vite and vanilla frontend entry files with a development `/api` proxy.
3. Add `.env.example`, non-secret configuration loading and ignore rules.
4. Document local versions and startup commands.
5. Create `IMPLEMENTATION-LOG.md` with the first code change.

## Likely files

`composer.json`, `public/index.php`, `app/`, `frontend/`, `.env.example`,
`.gitignore`, `README.md` and then `docs/IMPLEMENTATION-LOG.md`.

## Functional result and verification

PHP and frontend development servers start from clean documented commands.
Neither holds credentials or domain logic.

## Risks / confirmations

Confirm target PHP and Node versions before generating dependency manifests.
