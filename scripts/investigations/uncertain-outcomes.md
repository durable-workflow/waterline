# Uncertain external outcomes: bounded investigation

This is the synthetic evidence runner for Waterline #232, not a product recovery
endpoint. It installs exact normal published Waterline/Core packages in an
isolated Laravel consumer. Neither package is patched or replaced by path source.
The runner commit, package references, timestamps, browser version and scenario
outcomes are retained in GitHub Actions artifacts for seven days.

The separate loopback HTTP provider commits receipts outside the workflow
database. It deliberately provides no idempotency deduplication. For a lost
response it commits, then delays its response beyond the activity's real HTTP
timeout. The workflow caller cannot infer the provider outcome from that timeout.
The independent provider oracle is test truth and is never a Waterline route.

The three primary cases are:

- An external commit followed by a lost response, with an explicit one-attempt
  budget. The original workflow fails while the provider has committed once.
- A confirmed provider rejection exhausting an explicit two-attempt budget,
  with no external effect.
- A workflow processing failure after the activity's confirmed response has
  been recorded. The provider has committed once.

The browser reads the actual Waterline API/UI for authorized viewers/operators,
records repair/signal/update refusals on each terminal original, captures all
three pages in both themes at desktop/mobile widths, and records which inputs,
messages and completed receipts are visible. These observations are findings,
not a requirement that every field already be present.
The internal runtime oracle records original run, activity and attempt identities
separately from the operator-visible evidence, checking that observation/refusal
does not alter them and authored reconciliation does not replace them.

A separate application-authored control represents an unknown result explicitly
and waits for reconciliation. Its authorized update records an operator note;
its signal requests a read-only provider lookup. Only a unique confirmed receipt
allows the authored workflow to complete. Neither command supplies or invents a
successful external result, and the provider's effect count must stay one.

Finally, disposable fresh workflows reuse each ambiguous/processing operation
key. The deliberately non-idempotent provider records a second effect. This
demonstrates the prerequisite for reconciliation/idempotency before fresh start;
it does not recommend restarting unresolved operations.

Run the existing `published-image-qualification.yml` workflow at the exact
admitted investigation branch after the selected public Waterline qualification
and Core upgrade profile pass. Supply its normal immutable Waterline image,
version and source inputs, plus `uncertain_core_version` and
`uncertain_core_commit`. Existing publication/native/installed gates remain
unchanged. The optional investigation job runs only on explicit dispatch. The
release's declared peer floor remains separate from this experimental tuple.

Inspect each phase and artifact before drawing a conclusion. Setup/transport
failures in the harness are not evidence that a product recovery contract failed.
This does not test production, real payment providers, worker-loss recovery,
Server/native behavior, or the unfinished failed-run redrive work in Core #864.
