# Implementation plan

Implementation is intentionally phased. A phase is complete only when its
verification criteria pass and its result is recorded in
`IMPLEMENTATION-LOG.md` once actual implementation starts.

Correctness-test scope and the explicit separation from performance benchmarks
are defined in [TESTING.md](TESTING.md). Phase 08 implements that planned
minimum rather than pursuing coverage for its own sake.

| Phase | Outcome | Plan |
| --- | --- | --- |
| 01 | Reproducible local skeleton | [01-foundation.md](implementation/01-foundation.md) |
| 02 | Versioned, indexed MySQL schema | [02-database.md](implementation/02-database.md) |
| 03 | Reproducible ČÚZK full import | [03-import.md](implementation/03-import.md) |
| 04 | Safe API contracts over imported data | [04-api.md](implementation/04-api.md) |
| 05 | Zoom-aware map and territory layer | [05-frontend-map.md](implementation/05-frontend-map.md) |
| 06 | Parcel interaction and responsive detail | [06-parcel-detail.md](implementation/06-parcel-detail.md) |
| 07 | Measured performance decisions | [07-performance.md](implementation/07-performance.md) |
| 08 | Tests, accessibility, documentation and polish | [08-testing-and-polish.md](implementation/08-testing-and-polish.md) |

No phase authorises an unplanned architectural replacement. A source-data
constraint or failed benchmark is documented first and then resolved through an
explicit decision update.

## Git/GitHub workflow for implementation

Preferred submission repository name: **`jicin-parcel-map`**. The readable
README project title may be **Mapa parcel Jičín**. The existing local working
directory does not need renaming. This review phase does not create a GitHub
repository, initialise implementation, or create IMPLEMENTATION-LOG.md.

At implementation start, before the first push, check whether the intended
`jicin-parcel-map` repository exists, the configured remote and its owner/URL,
the current branch, GitHub authentication and push availability in this
environment. If the repository does not exist, stop before creation and ask
the user to confirm creating `jicin-parcel-map`. If the current configuration
does not match this plan, do not create a different repository or change an
existing remote without user confirmation. Do not bypass authentication.

For each small logical completed change, use:

```text
implementation -> relevant tests/verification -> git diff/review -> commit -> push
```

- Commit only after the relevant verification passes, using concise meaningful
  messages. A phase may contain several logical commits; a coherent change may
  cross files. Neither one commit per line nor one final omnibus commit is the
  intended granularity.
- History must reflect the actual work, not be cosmetically reconstructed
  before submission. Do not rewrite it through rebase/force push without an
  explicit user request.
- Review the staged diff and ignore rules. Never commit `.env`, credentials,
  large downloaded ČÚZK data, database files, build outputs or other ignored
  local artifacts.
- Once a step is verified and the intended authenticated repository/branch
  is confirmed, pushing that commit is authorised. If push is unavailable,
  create only the local commit and clearly report the unpushed state.

Example granularity: initialize PHP/Vite, add schema, importer, an API endpoint,
map/LOD, detail, or spatial test coverage. These are examples, not a mandatory
commit list or a one-commit-per-phase rule.
