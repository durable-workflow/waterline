# Platform Conformance — Waterline Claim

Waterline participates in the platform conformance suite specified in
[`workflow/docs/architecture/platform-conformance-suite.md`](https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/platform-conformance-suite.md)
and mirrored by `Workflow\V2\Support\PlatformConformanceSuite`. This
document is the per-repo claim: it lists the conformance targets
Waterline claims, the fixtures it serves, and the release gate that
blocks publication when conformance is broken.

## Claimed targets

Waterline claims one target from the suite's matrix:

- `waterline_contract_surface` — implements the
  `/waterline/api/v2/*` HTTP API and the operator dashboard JSON
  shapes. Covers the `waterline_api` surface family.

## Fixture sources served by this repo

| Category | Source path | Status |
| --- | --- | --- |
| `signal_query_runtime_contract` | selected-run detail `observer_state` comparison and query action path advertised by this document | stable |
| `waterline_observer_envelopes` | selected-run detail `observer_state` envelope | provisional |

The stable `signal_query_runtime_contract` category is load-bearing for
Waterline's observer comparison: a conformance run must be able to
compare selected-run detail and the advertised query action path against
public signal/query client results. The standalone observer-envelope
fixture set remains **provisional** in suite version `4`. Waterline does
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
| Required suite version | `PlatformConformanceSuite::VERSION` (currently `4`) |
| CI job | `platform-conformance` (blocks on stable categories; advisory only for `waterline_observer_envelopes` while it is provisional) |
| Block on `nonconforming` | yes |
| Artifact attached to release | harness result document, schema `durable-workflow.v2.platform-conformance.result` |

While `waterline_observer_envelopes` is provisional, a release reviewer
must still attach the harness result document to the release for
traceability. The conformance level will read `provisional` when that
provisional category is the only remaining gap; the release notes must
enumerate the provisional categories the result depends on.

## Cross-references

- Authority spec: `workflow/docs/architecture/platform-conformance-suite.md`
- Authority manifest class: `Workflow\V2\Support\PlatformConformanceSuite`
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Existing in-repo coverage: `tests/Feature/`, `tests/Unit/` exercising
  the `/waterline/api/v2/*` surfaces.
