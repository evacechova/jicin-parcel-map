# Data

## Source and measured scope

ČÚZK INSPIRE Cadastral Parcels predefined data is GML 3.2.1 in one ZIP per
cadastral territory (KÚ), available in EPSG:5514. ČÚZK creates a new dataset
daily only when that KÚ changes.

Measured Jičín district snapshot (14 September 2026):

- 240 KÚ;
- 272,861 parcels;
- 117.76 MiB ZIP input;
- 3.02 GiB uncompressed XML.

The importer processes one ZIP/GML at a time. It must not extract the whole
district or construct a DOM for a complete GML in memory.

## Relevant source features

| Logical entity | CP feature | Stored fields |
| --- | --- | --- |
| Cadastral territory | `CP.CadastralZoning` | KÚ code, name, INSPIRE ID, boundary, reference point, source version. |
| Parcel | `CP.CadastralParcel` | INSPIRE ID, label, national reference, area m², geometry, reference point, validity/version fields and KÚ code. |

Parcel type, land use, ownership, land-register sheet and price are not in the
base CP source and are not represented by the application.

## CRS strategy

- Native storage CRS: EPSG:5514.
- API display CRS: EPSG:4326.
- BBOX filtering occurs in native CRS. Only selected features are transformed
  to 4326.
- API query geometry uses the conservative densified-boundary/native-envelope
  contract in API.md. Its outward margin is a correctness requirement;
  `ST_Intersects` is exact against that envelope, not the original viewport.

Each logical dataset carries `country_code`, `provider`, `native_srid` and
`display_srid`. Physical MySQL datasets remain SRID-restricted per native CRS
so a future country with another CRS has a separate table/dataset rather than
mixed geometry values.

## Import strategy

1. Read the committed Jičín scope configuration and create an importing dataset version.
2. Stream each ZIP/GML into staging in batches.
3. Validate source and KÚ identity, unique parcel IDs, SRID, non-empty
   geometries and counts.
4. Keep the spatial indexes while loading the inactive snapshot (they also
   serve the active snapshot in the shared tables); analyse/measure the import
   cost afterwards. The index must not be dropped or rebuilt globally while
   active data share the table.
5. Atomically activate the validated dataset.

## Validated storage model

The following model was checked against the current Jičín CP ZIP/GML snapshot
and MySQL 8 documentation. It is a design contract, not implemented schema.

### Snapshot and import tables

`dataset` holds one complete import: `id BIGINT UNSIGNED AUTO_INCREMENT`,
`country_code CHAR(2)`, `provider VARCHAR(64)`, `scope_code VARCHAR(32)`,
`native_srid INT UNSIGNED`, `display_srid INT UNSIGNED`,
`source_url VARCHAR(512)`, `status ENUM('importing','ready','failed','retired')`,
timestamps, territory/parcel counts and optional `validation_report JSON`.

`active_dataset` is a one-row pointer:
`slot TINYINT UNSIGNED PRIMARY KEY CHECK (slot = 1)`,
`dataset_id BIGINT UNSIGNED NOT NULL` and `activated_at DATETIME(6)`.
Its FK uses `ON DELETE RESTRICT` so an active snapshot cannot be removed.

`import_territory` records one KÚ download within a dataset: dataset ID, KÚ
code, source URL/revision/checksum where available, status, parcel count and a
safe error message. It enables a failed full import to be diagnosed without
publishing it.

The following relational invariants are mandatory, not optional migration
choices:

- `dataset.id` and the global `id` columns of `cadastral_territory` and `parcel`
  are `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`.
- Every `dataset_id` is `BIGINT UNSIGNED NOT NULL`; `parcel.territory_id` is
  `BIGINT UNSIGNED NOT NULL`, matching the referenced territory ID exactly.
- `import_territory.ku_code` and `cadastral_territory.ku_code` are
  `CHAR(6) NOT NULL` with the same character set and collation.
- `import_territory` has `UNIQUE(dataset_id, ku_code)` and
  `FOREIGN KEY (dataset_id) REFERENCES dataset(id) ON DELETE RESTRICT`.
  One checkpoint represents all download attempts for that KÚ in that dataset;
  retries update it rather than insert another checkpoint.
- `cadastral_territory` has
  `FOREIGN KEY (dataset_id) REFERENCES dataset(id) ON DELETE RESTRICT`.
- `active_dataset.dataset_id` references `dataset(id) ON DELETE RESTRICT`.
- The composite parcel-to-territory FK below is mandatory and uses
  `ON DELETE RESTRICT`. Neither component is nullable. It also guarantees
  existence of the parcel's dataset through its territory, so a redundant
  direct parcel-to-dataset FK is not required.

KÚ and parcel `inspire_id` values are `NOT NULL`, making their documented
dataset-scoped unique keys effective for every row. Dataset/territory IDs are
immutable; no cascading ID updates are needed. Any manual cleanup deletes
children before parents and must respect active/rollback retention. No new
cleanup mechanism or schema is introduced here.

### Territory and parcel tables

`cadastral_territory` has a global `id BIGINT UNSIGNED AUTO_INCREMENT`,
`dataset_id`, `ku_code CHAR(6)`, name, INSPIRE ID, source timestamps,
`geom_native MULTIPOLYGON NOT NULL SRID 5514` and nullable
`reference_point_native POINT SRID 5514`. It has unique keys on
`(dataset_id, ku_code)` and `(dataset_id, inspire_id)`, a required additional
unique key on `(dataset_id, id)` for the composite FK below, and
`SPATIAL INDEX(geom_native)`.

`parcel` has global `id BIGINT UNSIGNED AUTO_INCREMENT`, `dataset_id`,
`territory_id`, `inspire_id VARCHAR(64)`, `label VARCHAR(128)`,
`national_cadastral_reference VARCHAR(128)`, `area_m2 DECIMAL(16,2)`,
native geometry/reference point and source timestamps. It has unique
`(dataset_id, inspire_id)`, B-tree `(dataset_id, territory_id)` and
`SPATIAL INDEX(geom_native)`.

`FOREIGN KEY (dataset_id, territory_id) REFERENCES cadastral_territory
(dataset_id, id)` is valid InnoDB design: matching child/parent types and an
index with the referenced columns in that order are mandatory. It prevents a
parcel in dataset B from referencing a territory in dataset A. The redundant
parent composite unique index is intentional; the global primary key `id` alone
does not provide the required leftmost `dataset_id` index order.

### GML evidence and normalisation

In all 240 downloaded Jičín ZIPs, the importer observed 272,861
`CadastralParcel` feature starts and 240 `CadastralZoning` starts. Both sets of
feature `gml:id` values were unique within the snapshot. Parcel examples use
`CP.<number>` and territory examples use `CZ.<KU code>`; the importer stores
the `base:Identifier/base:localId` value, not a guessed parsed number.

The parcel feature actually contains `cp:areaValue`,
`cp:beginLifespanVersion`, `cp:geometry`, `cp:inspireId`, `cp:label`,
`cp:nationalCadastralReference`, `cp:referencePoint` and `cp:validFrom`.
The observed parcel geometry is `gml:Polygon`, including possible interior
rings. The observed KÚ geometry is `gml:MultiSurface`. Crucially, the official
INSPIRE constraint for `CadastralParcel` permits either `GM_Surface` **or**
`GM_MultiSurface`; it does not guarantee a single Polygon. `CadastralZoning`
uses `GM_MultiSurface`. The importer therefore explicitly converts a source
Polygon to a one-member MultiPolygon and a MultiSurface to MultiPolygon before
insertion. This wrapper preserves all coordinates, rings and topology; it is a
canonical storage representation, not a geometry generalisation.

`parcel.geom_native` remains `MULTIPOLYGON NOT NULL SRID 5514`. A `POLYGON`
column would be marginally simpler for today's ČÚZK ZIPs but would reject a
legitimate multi-surface parcel from the INSPIRE model. A generic `GEOMETRY`
column would accept both but weakens the database contract and makes the
normalised API/query model less explicit. Unexpected non-surface geometry is
still rejected.

`validFrom` is often explicitly nil in the source and must therefore be
nullable. `beginLifespanVersion` is source lifespan/version metadata for the
geographic-object version, not the creation date of the real-world parcel and
not the dataset import timestamp. It is retained for provenance, auditing and
future source-version comparison, but is not a current API/UI field. `areaValue`
is supplied with `uom=\"m2\"` and is stored as source area, not recomputed from
geometry.

### Activation and rollback

Import rows are logical staging because their `dataset_id` is not pointed to by
`active_dataset`. After all KÚ and validations pass, one short transaction
marks the dataset `ready` and updates the single active pointer. API first
resolves an active dataset joined to `dataset.status = 'ready'`. Thus an
`importing` dataset is unqueryable even if an application bug supplied its ID.
Global auto-increment IDs are safe across snapshots; gaps are irrelevant and
the range is far beyond this dataset size. The prior dataset is retired and retained
for rollback and must not be purged while it is active or needed by in-flight
requests.

Take-home scope is a documented manual full refresh. Production may poll the
manifest daily and stage only changed KÚ using the same atomicity rule.

### MySQL-specific preflight before implementation

The supported server is Oracle MySQL Community Server 8.4 LTS. The original
8.0.32 minimum identified when the required projection support first became
available is no longer the project target. The implementation must nevertheless
run one small database preflight before importing the district: confirm that
SRIDs 5514 and 4326 are present in
`INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS`, that a known EPSG:5514 point
transforms to 4326, and that the resulting `ST_AsGeoJSON()` coordinates are
`[longitude, latitude]` as required by GeoJSON/Leaflet. This is an integration
assertion about MySQL's axis-order handling, not a reason to transform every
imported feature in advance.

## Full-refresh import design

### Scope selection and CLI contract

The take-home importer has one intentional scope: `jicin`. A committed scope
configuration will contain the fixed set of 240 KÚ codes, their display names
and the CP EPSG:5514 base URL, plus a conservative `DISTRICT_BOUNDS_4326` (D)
enclosing the complete scope. D is shared with frontend overview/KÚ bootstrap
configuration. It is not a client-location allowlist. It is not discovered by scraping the large ČÚZK
directory on every run. For each configured code the deterministic source is
`{base-url}/{ku_code}.zip`; the source directory remains useful only as the
human-verifiable origin of the list.

The minimal command is:

```text
php bin/import-cadastral.php --scope=jicin
```

It creates a new complete snapshot; it never updates the active snapshot in
place. The only optional take-home switches are `--keep-artifacts` for
debugging and `--source-dir=PATH` for a previously downloaded local set of
ZIPs. The user does not supply an arbitrary URL, CRS, SQL fragment, parcel
limit or a subset of KÚ. This keeps the run reproducible and the importer
contract small. A scheduler, free-form scope selection and incremental mode
are production extensions.

### Source artifacts and bounded memory

Each run creates `storage/imports/<run-id>/downloads/`. ZIP files are
downloaded there one at a time with finite connect/transfer timeouts and a
temporary filename, then atomically renamed after a successful HTTP response.
The run records source URL, retrieval timestamp, byte count and SHA-256 in
`import_territory`. A ZIP is opened with `ZipArchive` and its one expected GML
entry is read as a stream; it is **not** expanded as a 3.02 GiB district-wide
working tree. No parsed feature collection is accumulated in PHP.

By default, successful run artifacts are removed after activation; failed-run
artifacts remain for diagnosis and `--keep-artifacts` retains successful ZIPs.
Checksums and source metadata always remain in the database. This is enough
for reproducible reruns; archival of every source ZIP is a production/audit
choice, not a requirement of the assignment.

`XMLReader` opens the GML stream with network access disabled. A streaming
state machine recognises only the expected CP/GML namespace elements and reads
one feature at a time. It keeps scalar fields plus the WKT being built for the
current geometry, then discards them after the DB batch is executed. It does
not call `simplexml_load_file()`, build a document-wide DOM, or load external
entities. A feature-size/coordinate-count guard makes malformed input fail
explicitly rather than consuming unbounded memory.

### Per-KÚ processing

One KÚ ZIP is processed in two streaming passes inside one database
transaction:

1. Pass 1 requires exactly one `cp:CadastralZoning`, validates that its KÚ code
   matches the configured ZIP code and inserts `cadastral_territory`.
2. Pass 2 reads `cp:CadastralParcel` elements, verifies their zoning reference
   is the inserted KÚ, and inserts their parcels.

The second pass avoids relying on source feature order: parcels need the
generated `territory_id`, but buffering a potentially large set of parcels
until a territory happens to appear would defeat streaming. Re-reading one ZIP
is a modest I/O cost in exchange for a simpler and safer importer.

The importer prepares SQL once and retains at most a modest batch (for example
250 parcel parameter sets) before executing it; the outer transaction is still
one KÚ, not one full district. This limits memory and makes a bad KÚ fully
rollbackable. If ordinary prepared-row execution is already fast enough, it is
preferred over a more complex generated multi-value statement; batching is an
implementation optimisation to measure, not a correctness dependency.

The shared `parcel` and `cadastral_territory` tables keep their spatial indexes
throughout the import because they are also serving the active dataset. This
makes inserts somewhat more expensive but preserves API availability. Dropping
and rebuilding a global index, or loading a full district in one transaction,
is not appropriate for this model.

### GML-to-MySQL geometry contract

The parser accepts the checked CP shapes allowed by the source contract:

- parcel `gml:Polygon` becomes a WKT `MULTIPOLYGON(((...)))` with one polygon;
- a future/legitimate parcel `gml:MultiSurface` becomes the corresponding WKT
  `MULTIPOLYGON`, just like a territory;
- KÚ `gml:MultiSurface` becomes WKT `MULTIPOLYGON(((...)), ((...)))`, preserving
  each surface and interior ring;
- `gml:posList`/`gml:pos` coordinates must have dimension 2, an EPSG:5514
  `srsName`, valid ring cardinality/closure and finite numeric values.

The actual source coordinate order is preserved and passed explicitly to the
MySQL WKT constructor using the SRID-defined axis policy. SQL binds WKT as data
and creates the stored geometry with `ST_GeomFromText(:wkt, 5514,
'axis-order=srid-defined')`; it never interpolates coordinates into SQL.
Reference points use the equivalent `POINT(x y)` constructor. Unsupported
geometry, an unexpected SRID, an unclosed ring or an invalid coordinate is a
hard per-KÚ failure, not a silently simplified feature.

### Statuses, failure handling and restart policy

At the start, the importer creates `dataset(status='importing')` and 240
`import_territory(status='pending')` rows. A territory moves through
`pending -> processing -> imported`; a failure writes `failed` with a concise
safe reason and records no partial territory/parcel rows because its transaction
has rolled back. Dataset statuses are only `importing`, `ready`, `failed` and
`retired`.

The assignment retry policy applies **only to downloads**. There are at most
three total attempts: immediately, then after 1 second, then after 3 seconds
(a `Retry-After` value up to 30 seconds may replace the next wait). Retry only
connection/time-out/TLS-reset failures and HTTP 408, 429 or 5xx responses.
Do not retry 4xx responses other than 408/429, a corrupt ZIP, unexpected ZIP
contents, malformed GML, a missing required source field, unsupported or
invalid geometry, or any database/constraint error. Those are deterministic
for the current run and a retry would only hide the cause.

Each download attempt uses a new `*.part` file. A partial file is deleted
before retry and never opened as input; a fully received ZIP is integrity
checked and atomically renamed before parsing. `import_territory` records the
source URL, `attempt_count`, last-attempt timestamp, last HTTP status where
available, a stable error code and a short safe error message, plus checksum
and parcel count on success. After the third retryable failure or the first
non-retryable failure, the KÚ is `failed`, the dataset becomes `failed`, and
the import stops with the active dataset untouched. If the process crashes at
KÚ 137, the same invariant holds; the new dataset remains `importing` with
completed per-KÚ checkpoints but is not publishable.

For the assignment we deliberately do **not** resume an incomplete snapshot.
A rerun starts a fresh dataset and redownloads all 240 KÚ. Resume would need to
define how to prevent a mixture of old and newly changed source versions, and
adds more state than it saves for a one-off local import. A manual cleanup of a
stale `importing`/`failed` run is sufficient. Production could add a run lock,
resumable download cache and a source-manifest version token.

### Dataset validation, activation and rollback

Before publication, the importer validates all of the following against the
new `dataset_id`:

- exactly the configured 240 KÚ are `imported`, with none pending/failed;
- exactly one stored territory exists for each configured KÚ and no extras;
- the complete KÚ and parcel geometry coverage lies inside the committed
  conservative D envelope in 4326. Verify full spatial extent with an
  outward-safe transformation, not only reference points or four corners.
  This is import validation, not a second stored display geometry. A failed
  containment check blocks activation; do not silently publish an incomplete
  fixed-D bootstrap or make outside-D empty shortcuts unsafe;
- imported parcel-count totals equal the stored parcel count; each parcel has
  the matching dataset/territory relationship;
- unique source identities and KÚ identities are enforced by their database
  keys and rechecked as counts; all native geometries are non-null, non-empty,
  SRID 5514 and geometrically valid;
- parsed source KÚ reference, required attributes and source URL/checksum are
  present; total parcel count is recorded for comparison, not hard-coded to the
  discovery value of 272,861 because ČÚZK data can change.

On success, one short transaction locks the active-pointer row, changes the
new dataset from `importing` to `ready`, points `active_dataset` at it and
changes the former active dataset to `retired`. API resolves the pointer only
through `dataset.status='ready'`, so it observes either the old complete
snapshot or the new complete snapshot. The prior retired snapshot is retained
as the single rollback candidate. Rollback is the inverse short transaction:
point back to it, restore it to `ready` and retire the unsuccessful current
snapshot. Retention/purging beyond one rollback snapshot is production policy.

### Assignment boundary

Required here: fixed Jičín scope, streamed ZIP/GML parsing, per-KÚ transaction,
failure-safe snapshot status, validation, atomic activation, manual rerun and
documented local artifacts. Not required: cron/queue workers, daily Atom
polling, incremental updates, parallel downloads, resume across runs, object
storage, alerting or long-term source archival.

## Reproducibility

Dataset metadata records source URL, timestamp, CRS, KÚ/parcel counts and
validation result. ZIP/GML and database dumps are local working files, never
committed to Git.
