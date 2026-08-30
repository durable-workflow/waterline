# Changelog

## Unreleased

Waterline advances to the `2.0.0-rc.34` source identity. Deep-linked run-detail
sections remain pinned below persistent chrome through late browser scroll
restoration and asynchronous service-mode layout settling, while direct user
scrolling or keyboard navigation cancels that bounded positioning window.
Repeated visual qualification now includes the populated service presentation
at the mobile viewport that exposed the late fragment drift. Its package metadata
now describes the operational UI across embedded and service-mode deployments.
The standalone service distribution requires PHP SDK `2.0.0-rc.53`,
while embedded integration targets Workflow `2.0.0-rc.52` without pulling the
standalone SDK into the host application. Laravel-hosted service mode now
checks for the optional SDK at its configuration boundary and reports the exact
installation command when it is absent.
Run details now present embedded inbox/outbox message streams and service-mode
Workflow Streams with their lifecycle, offsets, pending work, delivery mode, and
error state. The operator UI renders the shared diagnostics contract across
desktop and mobile layouts.
Workflow Streams now expose synchronized expanded and collapsed labels and
accessibility state. Deep-linked run sections clear the persistent header and
wait for asynchronously rendered detail to settle before positioning. Compact
navigation remains horizontally reachable without displacing run content, and
stream error text retains readable dark-theme contrast.
Visual qualification now classifies workflow-list dialogs and run detail as
separate product surfaces, runs the material responsive states for each affected
surface, and rejects browser, request, contrast, overflow, clipping, overlap,
and control-reachability failures.
Service-mode run detail now treats a missing remote Workflow Streams route as
an unavailable capability while preserving the run lifecycle, history, and
diagnostics. Authorization, namespace, and transport failures remain explicit.
Embedded run detail now preserves configured summary fields, typed visibility
data, declared command contracts, and the recorded workflow fingerprint when
the selected-run projection is unavailable, while explicitly marking the
operator view as degraded.
Embedded Workflow Stream summaries now use direction-specific database
aggregation, so retained histories stay bounded in application memory and
outbound mirrors cannot inflate actionable pending work. Run detail exposes
typed available, degraded, and unavailable states, while the operator UI keeps
supported-empty and unavailable service responses visibly distinct without
displaying integration reason codes.
Namespace-scoped capacity evidence exposes bounded runtime saturation signals
and guarded, advisory recommendation inputs without treating infrastructure
telemetry as Waterline-owned measurements.
Latency percentiles now sample deterministic midpoint ranks across the complete
observation-window population, with stable primary-key ordering at equal
timestamps. Truncated samples declare their method and covered population;
unknown or locally truncated methods fail closed before capacity guidance.
Embedded sampling now selects those ranks in one database pass per latency
dimension instead of walking the population through growing offsets. Together
with the population count, latency sampling requires at most six queries and
only retained samples are hydrated by Waterline.
Service mode now consumes the Server's versioned namespace capacity contract,
selects only exact declared windows, and returns a typed unavailable response
for older or incomplete Servers instead of advertising partial evidence. The
first compatible service tuple is Server `2.0.0-rc.32`, PHP SDK
`2.0.0-rc.14`, and Waterline `2.0.0-rc.19`. Server window collection is reused
within its declared 30-second freshness bound so ordinary operator polling does
not repeat the full aggregate query set. Waterline evaluates that freshness
bound at request time with one second of clock-skew tolerance, rejects expired
or premature snapshots, and preserves accepted Server timestamps in its output.

Workflow-list filter and view-option dialogs now use a coherent light or dark
palette, keep their action row reachable around an internally scrolling body,
and explicitly mark the surrounding application inert while modal focus is
contained. Browser qualification opens both dialogs across desktop,
intermediate, mobile, and short-height viewports and validates contrast,
overflow, focus containment, and control reachability.

Worker-status conformance now kills its designated stale worker without giving
the managed SDK an opportunity to deregister. It proves the child process is
gone, anchors the stale deadline to the final accepted heartbeat, and separately
proves that an orderly peer shutdown deregisters across server, CLI, and
Waterline projections. Sequential projections compare stable process identity
exactly while validating mutable gauges and optional sticky-cache counters by
shape, bounds, and heartbeat order.

The standalone service now bundles `league/commonmark` 2.9.0, resolving six
security advisories present in the previously locked dependency version.

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
