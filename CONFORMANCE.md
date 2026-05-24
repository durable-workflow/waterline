# Platform Conformance — Waterline Claim

Waterline participates in the public platform conformance suite specified
at <https://durable-workflow.github.io/docs/2.0/platform-conformance>,
schema `durable-workflow.v2.platform-conformance.suite`, version `12`,
and mirrored by `Workflow\V2\Support\PlatformConformanceSuite`. This
document is the per-repo claim: it lists the conformance targets
Waterline claims, the categories it covers, and the release gate that
blocks publication when conformance is broken.

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
| `waterline_observer_envelopes` | selected-run detail `observer_state` envelope | provisional |

The stable `signal_query_runtime_contract` category is load-bearing for
Waterline's observer comparison: a conformance run must be able to
compare selected-run detail and the advertised query action path against
public signal/query client results. The standalone observer-envelope
fixture set remains **provisional** in suite version `12`. Waterline does
not yet vendor a standalone public fixture directory; the existing
per-repo tests under `tests/Feature/` and `tests/Unit/` exercise the
`/waterline/api/v2/*` shapes against in-process fakes. Selected-run detail
responses include an `observer_state` envelope with the exact API paths
and compact run status, output, signal argument, and declared-query facts
that external conformance runners can compare against public client
signal/query results.

`observer_state.queries.live_results_materialized` is currently `false`.
Waterline selected-run detail is a durable observer snapshot and does not
store live workflow query results. Runners that need the live query value
must compare through the query action path advertised in
`observer_state.paths.selected_run_query_template`, and should record the
typed reason
`query_results_not_materialized_in_selected_run_detail` when a read-only
detail envelope is the only observer surface captured.

Namespace-scoped Waterline list, detail, health, and operator API
visibility are load-bearing evidence for the stable
`namespace_runtime_contract` category in suite version `12`. A release
result must evaluate the public namespace runtime scenario manifest
published at `/platform-conformance/namespace-runtime-scenarios.json`
against published Waterline artifacts; in-repo feature coverage remains
implementation evidence, not a substitute for the harness result. The
current namespace runtime manifest requires published-artifact install
evidence, namespace lifecycle cleanup and recreate coverage, workflow and
worker isolation, CLI and SDK namespace selection, search-attribute and
schedule isolation, Waterline operator namespace visibility, explicit
Nexus cross-namespace calls, reserved-name refusal, and result-record
routing for product findings. Suite version `12` also binds the lifecycle
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
| Required suite version | `PlatformConformanceSuite::VERSION` / public suite manifest version `12` |
| Namespace runtime source | `/platform-conformance/namespace-runtime-scenarios.json` from the public docs origin, with `suite_version` `12` |
| Saga runtime source | `/platform-conformance/saga-runtime-scenarios.json` from the public docs origin, with `suite_version` `12` |
| CI job | `platform-conformance` (blocks on stable runtime categories including `signal_query_runtime_contract`, `search_attribute_runtime_contract`, `namespace_runtime_contract`, `saga_runtime_contract`, and `worker_versioning_runtime_contract`; advisory only for `waterline_observer_envelopes` while it is provisional) |
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
