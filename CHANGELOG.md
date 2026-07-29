# Changelog

## Unreleased

Waterline advances to the `2.0.0-rc.8` source identity. The qualified
`2.0.0-rc.5` aggregate remains the installation recommendation until the exact
RC8 train completes qualification.

Embedded and service-mode health snapshots now expose disjoint active and stale
worker-registration rosters with matching total, active, and stale counters.
Worker table rows and active-lease totals are derived only from active
registrations in both modes.

Worker Health now labels the returned registration total accurately and shows
active and stale registration counts separately. Historical stale rows are no
longer described as active workers.

Command-contract health alerts now use actionable open-run backfill counts when
available, so immutable contract gaps on closed runs are no longer reported as
active fleet health failures.

Saved-view filter metadata and normalization are now owned by Waterline, so
service mode can render and use saved views without loading the optional
embedded Workflow package. Service-mode workflow lists apply supported saved
filters through the authoritative SDK query before pagination and report any
embedded-only filters as unavailable instead of silently ignoring them.
Embedded-mode query execution continues to use the Workflow engine's visibility
filters.

Avro payload previews now understand the shared fixed typed Value schema and
render binary values explicitly, so byte sequences cannot be mistaken for
equal-looking UTF-8 or base64 text.

Managed Waterline now loads executable scripts and package-authored style
elements from the same origin under the production Content Security Policy,
without `unsafe-inline` or `unsafe-eval` script permission and without external
style or font origins. Package-authored markup no longer contains static inline
style attributes. The UI runtime still creates style elements and attributes
for charting, positioning, and virtualized history, so managed routes retain
the browser-qualified, route-scoped `style-src-elem 'self' 'unsafe-inline'` and
`style-src-attr 'unsafe-inline'` allowances; `style-src 'self'` alone is not a
supported policy for the operator interface.

The packaged service now rejects process-local SQLite memory databases before
running migrations. Service mode supports file-backed SQLite, MySQL, and
PostgreSQL persistence across its separate migration and HTTP server processes.

Published worker-status validation now accepts exact SemVer Server release
identities, including stable releases. Workflow and Waterline package identities
remain restricted to exact numeric 2.0 alpha, beta, and release-candidate
versions; ranges, branch aliases, and unpinned values are rejected.

Release-plan recovery now consumes immutable, exact-version release-note
preparation authority before publishing a newly recorded plan.
Explicit recovery also rejects terminally superseded plans before and after
publication preflight while keeping completed-plan verification idempotent.

Waterline keeps the Durable Workflow 2.0 conformance claim aligned to
platform conformance suite version 17. The focused
`waterline:principal-attribution-conformance` shard now documents
operator-visible command and timeline principal attribution alongside the
namespace and search-attribute Waterline shards. Migration runtime and
skew refusal matrix evidence remain load-bearing Waterline release
categories.

The package now exports a focused published-artifact worker-status runner.
It compares live Waterline list and task-queue worker projections with the
standalone server API and CLI through two heartbeats, real workflow work, and
a bounded stale transition. Published-port readiness requests have per-attempt
timeouts and retries, and all outcomes remove the package host plus labeled
Compose containers, volumes, networks, and scratch state. Published observer
hosts can align Waterline's freshness classification with the server through
`WATERLINE_WORKER_STALE_AFTER_SECONDS`. Standalone worker execution now uses
the exact published PHP SDK package while Workflow remains the embedded engine;
normal and early-failure evidence retain the canonical PHP SDK version and
Packagist provenance. Worker-status projections now derive the advertised PHP
SDK identity and worker protocol from the installed SDK runtime contract.
