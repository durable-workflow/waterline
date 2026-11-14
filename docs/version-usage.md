# Inspect version usage

The **Version usage** page reads recorded decisions for one workflow type and
change point in one namespace. Enter the proposed minimum supported version.
For a `patched()` legacy branch, use `1`. Identities are exact and case-sensitive.
An empty namespace selects the embedded default namespace. A namespace-scoped
Waterline installation fixes this field to its configured namespace.

The page shows counts for every state, even when the run table is filtered:

- **Legacy**: a recorded version below the proposed minimum.
- **Current**: a recorded version at or above the proposed minimum.
- **Unknown**: no marker, unavailable history or provenance, or invalid history.

A run with no marker remains unknown even when its current workflow definition
matches the deployment. The table links to the exact instance and run. Waterline
does not replay workflows or fetch application payloads for this query.

Results are live observations. Next page preserves the runtime's opaque cursor
and upper run-ID bound. Counts and membership can change between pages. A run
that becomes a match behind the cursor requires **Restart query**. Changing any
filter starts a new traversal. A refused cursor is an error, never a silent
restart. Retry repeats the same failed read.

These observations do not authorize removing a branch. Investigate unknowns and
restart the query after draining or fencing admissions before making that
decision.

## Backend support

Embedded mode requires the v2 engine and
`Workflow\V2\Support\VersionUsageQuery::page()`. Service mode requires the official
PHP SDK's `Client::workflowVersionUsage()` and the Server's
`workflow_version_usage` capability. The SDK owns control authentication,
namespace selection and request timeouts. Waterline does not use a raw HTTP
fallback. Older readers return an explicit unavailable response without counts.

The source adapters and UI require published-package acceptance before this
feature is considered delivered. They do not imply that an existing published
Server or embedded package exposes the reader.

## HTTP read

`GET /waterline/api/v2/workflow-version-usage` accepts `workflow_type`, `change_id`,
and a signed 32-bit `min_version`. Optional fields are `namespace`, `state`
(`legacy`, `current`, `unknown`), `limit` (1–200, default 50), and opaque `cursor`.
Namespace selection cannot widen the installation's operator scope. Existing
Waterline authentication applies.

Successful responses preserve the producer's exact v1 response shape. Invalid
arguments and cursors return 422, unavailable readers return 501, and malformed
producer responses return 502. Authentication, storage and transport failures
remain errors rather than empty results.

The accepted producer contract is
[Workflow's version-usage contract](https://github.com/durable-workflow/workflow/blob/c93cadad8a0104b6e7e5f38e022c3b06c26c86e6/docs/version-usage.md),
with [its v1 schema](https://github.com/durable-workflow/workflow/blob/c93cadad8a0104b6e7e5f38e022c3b06c26c86e6/resources/contracts/version-usage-v1.schema.json).
