# Platform Conformance — Waterline Claim

Waterline participates in the public platform conformance suite specified
at <https://durable-workflow.github.io/docs/2.0/platform-conformance>,
schema `durable-workflow.v2.platform-conformance.suite`, version `17`,
and mirrored by `Workflow\V2\Support\PlatformConformanceSuite`. This
document is the per-repo claim: it lists the conformance targets
Waterline claims, the categories it covers, and the release gate that
blocks publication when conformance is broken.

## Dual-backend release evidence

The embedded published-artifact cells install the Waterline Composer package
with the matching Workflow package and exercise the in-process Laravel
operator bridge. The service-image cell builds and, for release tags, pulls the
versioned `durableworkflow/waterline` image, starts it without host PHP or
Composer, and observes a standalone HTTP server through the published PHP SDK.
It verifies namespace and bearer-token forwarding, workflow list and selected
run history semantics, and local read-only refusal before any remote mutation.
Neither cell substitutes for the other before beta exit.

## Claimed targets

Waterline claims one target from the suite's matrix:

- `waterline_contract_surface` — implements the
  `/waterline/api/v2/*` HTTP API and the operator dashboard JSON
  shapes. Covers the `waterline_api` surface family.

## Conformance categories covered by this claim

| Category | Evidence / fixture source | Status |
| --- | --- | --- |
| `signal_query_runtime_contract` | selected-run detail `observer_state` comparison and query action path advertised by this document | stable |
| `search_attribute_runtime_contract` | public search-attribute runtime scenario manifest at `/platform-conformance/search-attribute-runtime-scenarios.json` plus list/detail filter captures | stable |
| `namespace_runtime_contract` | public namespace runtime scenario manifest at `/platform-conformance/namespace-runtime-scenarios.json` plus Waterline list, detail, health, and operator API captures | stable |
| `saga_runtime_contract` | public saga runtime scenario manifest at `/platform-conformance/saga-runtime-scenarios.json` plus in-progress and terminal compensation detail captures | stable |
| `worker_versioning_runtime_contract` | public worker-versioning runtime scenario manifest at `/platform-conformance/worker-versioning-runtime-scenarios.json` plus worker and run compatibility captures | stable |
| `workflow_update_runtime_contract` | selected-run update diagnostics and history-export captures for accepted, completed, failed, and refused update paths | provisional |
| `migration_runtime_contract` | public migration runtime scenario manifest at `/platform-conformance/migration-runtime-scenarios.json` plus Waterline operator visibility for migrated histories, schedules, worker registrations, and rollback state | stable |
| `skew_refusal_matrix_contract` | public skew refusal scenario manifest at `/platform-conformance/skew-refusal-matrix-scenarios.json` plus Waterline render classification and version-pair evidence | stable |
| `principal_attribution_contract` | server-published principal-attribution scenario manifest plus selected-run command and timeline principal captures | stable |
| `waterline_observer_envelopes` | selected-run detail `observer_state` envelope | provisional |

The stable `signal_query_runtime_contract` category is load-bearing for
Waterline's observer comparison: a conformance run must be able to
compare selected-run detail and the advertised query action path against
public signal/query client results. The standalone observer-envelope
fixture set remains **provisional** in suite version `17`. Waterline does
not yet vendor a standalone public fixture directory; the existing
per-repo tests under `tests/Feature/` and `tests/Unit/` exercise the
`/waterline/api/v2/*` shapes against in-process fakes. Selected-run detail
responses include an `observer_state` envelope with the exact API paths
and compact run status, output, signal argument, and declared-query facts
that external conformance runners can compare against public client
signal/query results.

Worker heartbeat visibility has a separate focused published-artifact
cell. It is intentionally not satisfied by database fixtures or by the
in-process projection tests used during development. A release host runs:

```bash
DW_SERVER_VERSION=<exact-version> \
DW_CLI_VERSION=<exact-version> \
DW_PHP_SDK_VERSION=<exact-version> \
DW_WORKFLOW_PHP_VERSION=<exact-2.0-prerelease> \
DW_WATERLINE_VERSION=<exact-2.0-prerelease> \
scripts/conformance/worker-status-published-artifacts.sh \
  --result-dir=/path/to/results
```

The release-exported runner creates a disposable Laravel host, installs the
exact PHP SDK, Workflow PHP, and Waterline Packagist distributions with
Composer's `prefer-dist` mode, starts the exact public server image, and
installs the exact CLI release. The installed
`waterline:worker-status-conformance` command drives a published PHP SDK
managed worker through successive heartbeats, real workflow work, and a stale
transition while a fresh peer keeps polling. Workflow remains the embedded
Laravel engine. The live captures compare Waterline's worker list and
task-queue detail projections with server worker API and CLI observations.
The result records pins and provenance, run and worker identities, timestamps,
task slots, process and compatibility metadata, routing exclusion, source
hygiene, and cleanup.

Neither the shell handoff nor the Artisan command accepts fixture, plan, or
caller-supplied projection JSON. Only response envelopes captured by the
runner's HTTP client and commands executed by the installed CLI can satisfy
the authority gate. SDK heartbeat-loop implementation remains owned by each
SDK; this Waterline cell invokes the installed PHP SDK worker and does not
duplicate PHP, Python, or Rust heartbeat-loop implementations.

The published package also exposes
`waterline:signals-queries-conformance`. Host conformance runners pass the
public signals/queries evidence JSON plus real Waterline selected-run
detail and query-action captures to that command, or let the command
exercise those routes through the installed application's HTTP kernel. The
`waterline_operator_visibility` shard compares
`observer_state.selected_run`, `observer_state.signals`, and the selected-run
query action response against the server, CLI, and SDK query observations,
records the selected-run API paths, and emits timestamped dashboard JSON
envelopes with the published artifact versions used for the comparison. The
shard fails with typed findings when real Waterline API captures are missing
or cannot prove parity. It also records a
`published_artifact_install_only` metadata scenario and returns a failing exit
code when the server, CLI, workflow PHP, Python SDK, or Waterline version and
source proof is missing, local, or not a recognized published artifact channel.

`observer_state.queries.live_results_materialized` is currently `false`.
Waterline selected-run detail is a durable observer snapshot and does not
store live workflow query results. Runners that need the live query value
must compare through the query action path advertised in
`observer_state.paths.selected_run_query_template`. The conformance shard
records the typed reason
`query_results_not_materialized_in_selected_run_detail` alongside the exact
selected-run detail capture whenever this read-only detail limitation is
observed.

Workflow update operator diagnostics are covered by a focused Waterline shard:

```bash
php artisan waterline:workflow-updates-conformance \
  --selected-run-detail-capture=/path/to/selected-run-detail.json \
  --selected-run-history-capture=/path/to/selected-run-history-export.json \
  --artifact-version=server=0.2.544 \
  --artifact-version=cli=0.1.84 \
  --artifact-version=workflow=2.0.0-alpha.242 \
  --artifact-version=sdk-python=0.4.93 \
  --artifact-version=waterline=2.0.0-alpha.113 \
  --artifact-source=server=docker_image \
  --artifact-source=cli=official_install_script \
  --artifact-source=workflow=packagist_package \
  --artifact-source=sdk-python=pypi_package \
  --artifact-source=waterline=packagist_package
```

The command accepts real captures from `GET
/waterline/api/instances/<instance>/runs/<run>` and `GET
/waterline/api/instances/<instance>/runs/<run>/history-export`, or it can
capture those routes through the installed application's HTTP kernel when
`--instance-id` and `--workflow-run-id` are supplied. It emits a
`durable-workflow.v2.workflow-updates.waterline-operator-shard` document with
the selected-run update paths, API captures, state counts, and an operator
surface matrix proving that accepted, completed, failed, and refused update
rows expose request identifiers, payload/result/error details, outcome or
reason, and matching update history references. The shard returns typed
findings when any required update path or history-export reference is missing.

Namespace-scoped Waterline list, detail, health, and operator API
visibility are load-bearing evidence for the stable
`namespace_runtime_contract` category in suite version `17`. A release
result must evaluate the public namespace runtime scenario manifest
published at `/platform-conformance/namespace-runtime-scenarios.json`
against published Waterline artifacts; in-repo feature coverage remains
implementation evidence, not a substitute for the harness result. The
current namespace runtime manifest requires published-artifact install
evidence, namespace lifecycle cleanup and recreate coverage, workflow and
worker isolation, CLI and SDK namespace selection, search-attribute and
schedule isolation, Waterline operator namespace visibility, explicit
Nexus cross-namespace calls, reserved-name refusal, and result-record
routing for product findings. Suite version `17` also binds the lifecycle
cleanup criteria that preserve cross-namespace external payload ownership:
cleanup may remove only payload references owned by the deleted namespace,
must keep tenant-owned cross-namespace workflow and service-call records
readable through the owning namespace storage context, and must refuse
unsafe cleanup with an operator-visible typed reason instead of resolving
or deleting payloads through the wrong namespace. A missing,
redirected-to-404, stale-suite, or otherwise unloadable namespace runtime
manifest is nonconforming for this stable category and blocks release.

Search-attribute filters and selected-run detail values are
load-bearing evidence for `search_attribute_runtime_contract`; a
Waterline result must compare the public scenario manifest against
published artifacts rather than substituting in-repo feature tests.
Waterline ships a focused evidence shard for the operator-visible
search-attribute cell:

```bash
php artisan waterline:search-attributes-conformance \
  --run-id=conformance-run-id \
  --artifact-version=server=0.2.238 \
  --artifact-version=cli=0.1.75 \
  --artifact-version=workflow=2.0.0-alpha.189 \
  --artifact-version=sdk-python=0.4.84 \
  --artifact-version=waterline=2.0.0-alpha.76 \
  --artifact-source=server=docker_image \
  --artifact-source=cli=official_install_script \
  --artifact-source=workflow=packagist_package \
  --artifact-source=sdk-python=pypi_package \
  --artifact-source=waterline=packagist_package \
  --json
```

The command seeds two namespaces in the host database and exercises
Waterline list filters, selected-run detail, saved-view retrieval and
application, keyword-list membership filtering, and scoped namespace
isolation through package HTTP routes. It emits a
`durable-workflow.v2.search-attribute-runtime.result` document with the
expected and actual workflow counts for search-attribute list filters,
the selected workflow-instance and run identity, the typed selected-run
search attributes, saved filter state before and after retrieval, API
response captures, the conformance run id, and an operator surface matrix
for the focused Waterline cell. When these focused checks and the published
artifact metadata checks pass, the shard reports `outcome=pass` so a host
runner can merge it into the full runtime ledger. It is a shard, not a full
search-attribute runtime run: schema definition, workflow-side upserts,
range queries, type-safety probes, cross-language checks, indexing
latency, and adversarial parser checks remain the responsibility of the
full search-attribute harness.

Principal attribution is load-bearing evidence for
`principal_attribution_contract`. Waterline ships a focused evidence
shard for the operator-visible principal-attribution cell:

```bash
php artisan waterline:principal-attribution-conformance \
  --artifact-version=server=0.2.238 \
  --artifact-version=cli=0.1.75 \
  --artifact-version=workflow=2.0.0-alpha.189 \
  --artifact-version=sdk-python=0.4.84 \
  --artifact-version=waterline=2.0.0-alpha.76 \
  --artifact-source=server=docker_image \
  --artifact-source=cli=official_install_script \
  --artifact-source=workflow=packagist_package \
  --artifact-source=sdk-python=pypi_package \
  --artifact-source=waterline=packagist_package \
  --json
```

The command seeds a completed run, workflow command, and history timeline
with an authenticated principal, exercises the selected-run detail API
through package HTTP routes, and emits a
`durable-workflow.v2.principal-attribution.waterline-operator-shard`
document with command principal fields, command-context auth fields,
timeline principal fields, API response captures, and an operator surface
matrix for the focused Waterline cell. It claims the
`waterline_contract_surface` target and reports `non_passing` when the
local shard passes because it is not a full principal-attribution
contract run. Server, worker, CLI, SDK, anonymous, spoofing, completion,
failure, and query attribution scenarios remain the responsibility of the
full principal-attribution harness.

Waterline also ships a focused evidence shard for the namespace operator
visibility cell:

```bash
php artisan waterline:namespace-conformance \
  --artifact-version=server=0.2.238 \
  --artifact-version=cli=0.1.75 \
  --artifact-version=workflow=2.0.0-alpha.189 \
  --artifact-version=sdk-python=0.4.84 \
  --artifact-version=waterline=2.0.0-alpha.76 \
  --artifact-source=server=docker_image \
  --artifact-source=cli=official_install_script \
  --artifact-source=workflow=packagist_package \
  --artifact-source=sdk-python=pypi_package \
  --artifact-source=waterline=packagist_package \
  --json
```

The command seeds two tenant namespaces in the host database, exercises
Waterline list, detail, schedule, dashboard scope, stats/operator API,
search-attribute, and unscoped authority surfaces through the package HTTP
routes, emits a
`durable-workflow.v2.namespace-runtime.result` document with
`waterline_operator_namespace_visibility` populated, includes the scoped and
unscoped API and dashboard response captures used by the pass/fail checks,
records the conformance suite version and rejects missing, placeholder,
local, source, or development artifact metadata, adds an
`operator_surface_matrix` verdict for
scoped workflow lists, workflow details, schedule views,
search-attribute values, dashboard scope, stats/operator APIs, and documented
unscoped authority, and removes its fixture rows unless `--keep-fixtures` is
supplied. It is a shard, not a full
namespace run: all non-Waterline namespace scenarios remain `not_covered` for
the full harness to merge or evaluate separately.

Saga compensation visibility is load-bearing evidence for
`saga_runtime_contract`. A result must compare the public saga runtime
scenario manifest against published artifacts and show whether operators
can distinguish completed forward steps, running compensation, pending
compensation, completed compensation, and failed compensation from
Waterline surfaces. Required cells cover forward success, reverse-order
compensation after later-step failure, early failure, compensation retry
idempotence, compensation failure visibility, worker restart replay,
PHP/Python cross-language compensation, typed compensation errors, and
operator-visible in-progress compensation status. A missing,
redirected-to-404, stale-suite, or otherwise unloadable saga runtime
manifest is nonconforming for this stable category and blocks release.

Worker cohort and per-run compatibility visibility are load-bearing
evidence for `worker_versioning_runtime_contract`. A result must compare
worker build IDs, drain/resume state, no-compatible-worker diagnostics,
and run compatibility fields through public Waterline surfaces.

Migration runtime visibility is part of this suite-17 claim. Waterline
release evidence must compare the public migration runtime scenario
manifest against the resolved published artifact tuple and prove operator
visibility for migrated histories, in-flight progress, schedules, worker
registrations, new v2 starts, rollback state, and version-skew refusal.
In-repo upgrade smoke coverage remains implementation evidence, not a
substitute for a downloadable public scenario manifest result.

Skew refusal matrix coverage is also part of this suite-17 claim. A
release result must compare the public skew refusal scenario manifest
against published artifacts and show how Waterline classifies compatible,
backward-skewed, and unsupported server or worker protocol combinations.

Principal attribution visibility is also part of the Waterline release
evidence. The focused Waterline shard must be evaluated with the full
principal-attribution harness output before the operator visibility cell
is treated as covered.

The standalone observer-envelope fixture set is promoted to **required**
in a future suite version once the contract slice for the operator
dashboard JSON envelope is public. Until then, a failure in
`waterline_observer_envelopes` is a warning, not a release blocker.

## Release gate

A release of `waterline` must produce a harness result document before
tag.

| Field | Value |
| --- | --- |
| Required claimed targets | `waterline_contract_surface` |
| Required suite version | `PlatformConformanceSuite::VERSION` / public suite manifest version `17` |
| Namespace runtime source | `/platform-conformance/namespace-runtime-scenarios.json` from the public docs origin, with `suite_version` `17` |
| Saga runtime source | `/platform-conformance/saga-runtime-scenarios.json` from the public docs origin, with `suite_version` `17` |
| Migration runtime source | `/platform-conformance/migration-runtime-scenarios.json` from the public docs origin, with `suite_version` `17` |
| Skew refusal source | `/platform-conformance/skew-refusal-matrix-scenarios.json` from the public docs origin, with `suite_version` `17` |
| Principal attribution source | server-published principal-attribution scenario manifest plus the `waterline:principal-attribution-conformance` shard output |
| CI job | `platform-conformance` (blocks on stable runtime categories including `signal_query_runtime_contract`, `search_attribute_runtime_contract`, `namespace_runtime_contract`, `saga_runtime_contract`, `worker_versioning_runtime_contract`, `migration_runtime_contract`, `skew_refusal_matrix_contract`, and `principal_attribution_contract`; advisory only for `waterline_observer_envelopes` while it is provisional) |
| Block on `nonconforming` | yes |
| Artifact attached to release | harness result document, schema `durable-workflow.v2.platform-conformance.result` |

A release reviewer must confirm the harness result document is attached,
the conformance level is `full` or `provisional`, and the suite version
in the result matches the version exposed by the Waterline build under
test before tagging. A `nonconforming` result blocks release.

While `waterline_observer_envelopes` is provisional, a release reviewer
must still attach the harness result document to the release for
traceability. The conformance level will read `provisional` when that
provisional category is the only remaining gap; the release notes must
enumerate the provisional categories the result depends on.

## Cross-references

- Authority spec: <https://durable-workflow.github.io/docs/2.0/platform-conformance>
- Authority manifest class: `Workflow\V2\Support\PlatformConformanceSuite`
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Existing in-repo coverage: `tests/Feature/`, `tests/Unit/` exercising
  the `/waterline/api/v2/*` surfaces.
