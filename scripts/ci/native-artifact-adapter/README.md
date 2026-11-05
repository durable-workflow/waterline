# Unwired private artifact adapter

This is the next source proposal for [Waterline #279](https://github.com/durable-workflow/waterline/issues/279).
It introduces no workflow, trigger, provider client, process controller, upload,
credential route or execution grant. Running `adapter.py` refuses before imports
or argument/environment reads. `execute()` always refuses. Every actual effect
in [the input inventory](../../../release/held-private-adapter.json) is UNBOUND.

The accepted recipe remains exactly 27 members at public source commit
`a04d0680d740722577ebe53c294d0c76d26bd6f8`, tree
`aa24f3d900408925799261a369b5554381c6060c`, manifest
`2a81084227dd3629bde18373f1e29504858de6251eea2750ea30401debaa7332`.
`base.primitives()` retains and verifies every Git blob before importing any of
those primitives. It does only bounded local Git reads; no archive acquisition
or provider operation follows. The older packet is neither edited nor silently
reinterpreted as a private job.

## Actual private context and separate public authority

The protected future task supplies the actual private repository ID/node/name,
visibility, workflow path/commit/tree/bytes, event/ref/head, original run/attempt,
job ID/name, runner/daemon/nonce and audience snapshot. Those real identifiers
and audience records stay in their private owning record. This public packet's
fixtures are fictional. It supplies no actual repository/workflow/task values.

`validate_task()` compares that protected selection with independent provider
and host observations. A second, closed runtime identity map must match actual
job variables, including repository/run/attempt and workflow ref/SHA. Matching
private API metadata alone cannot relabel a public runtime. The upload token
route is the same private job's existing runtime mechanism; no token value is
an input, no public token transfer is accepted, and extra-secret/broker/hop
routes refuse. The build environment is a separate complete allowlist with
isolated HOME and empty anonymous Docker configuration. It carries no upload
or repository credential into Buildx or candidate inputs.

`private_context()` performs that preflight before a future capture can be
considered; `prepare()` repeats it and checks the actual completed seal's
transport identity. A caller cannot first capture under one policy and splice
another matching-looking static digest into the build inputs.

This is source consistency validation, not an authenticated provider reader.
The real private workflow owner must bind the independent readers and their
source identities, complete audience/readback freshness, tool/parent/config,
transport and effective resource evidence before activation can be reviewed.
An audience snapshot is not a permanent or exclusive-access guarantee. An
inaccessible, incomplete or changed audience must refuse; no extra permission
or credential acquisition is supplied here.

`PrivateContext` reports the actual producer identity and the accepted public
code authority as separate objects. The old public `Context` and hosted wrapper
are not used to represent a private run. The existing same-cell seal/capture,
source authority, COPY mapping and canonical tar primitives accept this new
context without changing their blobs. Their operational methods retain their
literal holds. The old bootstrap/provider/lifecycle handoff is not a route for
this new context.

The new adapter's own commit/tree/member manifest is a further independent
protected input, separate from both that base and the private workflow. It is
retained in the context and compared with the host observation. This inventory
cannot endorse its own source: the private owner must obtain the reviewed
adapter through independently bound Git bytes before using it.

## One retained input and original output

`prepare()` requires the independent completed 79-object seal and its original
ledger. It retains the sealed cache bytes, fixed M1 COPY leaves and separate
trusted Dockerfile in one canonical stdin tar. It reuses the accepted Buildx
argv and never executes it. Later cache-directory mutation cannot replace the
retained input. Composer stays offline, scripts/plugins stay off and recipe
jobs remain at most two.

`describe_output()` accepts independent observations of that one original
invocation, not candidate JSON. It binds the command, stdin digest and complete
private attempt. Actual supplied OCI bytes go through the accepted validator:
size, platform/config/manifest/attestation/blob closure and all four input
digests are bound. A success with no export refuses. A nonpassing attempt may
produce a diagnostic-only proposal, preserving its original exit/timeout/OOM
and bounded stdout/stderr bytes; it cannot be relabelled as a successful export
or trigger a retry. The source tests use synthetic archive bytes only.

`upload_proposal()` returns data for the existing pinned upload primitive,
using the same private job. The receipt retains the complete original sealed
observed-source and cache manifests, not just unrecoverable digest references
after runner teardown. It binds a closed member set and every retained
byte digest. No wildcard, hidden-file, overwrite, changed-byte or cross-origin
fallback is selected. The original OCI limit is 1 GiB per platform; each of the
two original diagnostic streams is bounded to 1 MiB. The receipt is at most
3 MiB. The provider ZIP has a separate 1 GiB + 6 MiB bound allowing only these
members and wrapper overhead; this does not increase the OCI export cap. Retention is
a one-day request, separately bound to the selected pinned action, resolved
inputs and an independent protected source/clock receipt. Artifact API facts do
not contain `retention_days` or `overwrite`; neither field is manufactured from
request inputs. The artifact response's repository/run/head is paired with a
separate original run response for its attempt, and an independent audience
reader supplies audience facts.

`freeze_retention()` retains the first supplied provider ID/name/digest/size and
literal creation/expiry strings in an immutable memory snapshot. One-day
metadata can have an 86,399-second or 86,400-second creation-to-expiry interval.
The original shorter expiry stays shorter. Positive lifetimes must remain at
most one day and within the original protected maximum; no broad tolerance is
added. `validate_retention()` requires every later factual readback and the
protected request to match that first snapshot exactly. A replacement, renewed
or even shortened expiry refuses rather than silently replacing the original.

The protected retention anchor/maximum is an independent task input, not
inferred from the artifact's creation timestamp. No ordering or exact retention
clock relationship between API creation, upload start/completion and that
anchor is established here, including for larger uploads. The actual protected
source reader, clock authority and provider lifecycle relation remain UNBOUND
and unqualified. Missing authority/deadline refuses; source consistency never
becomes an authentication or execution claim. The tar/Buildx assumptions also
remain synthetic and unqualified for a real export.

The actual private workflow owner still owns immutable materialization, the
upload source path and authenticated artifact ID/digest/member/expiry readbacks.
This packet implements none of those effects and performs no download. Source
receipt, provider-origin, cleanup, runtime and publication verification flags
remain false. A truthful ZIP digest is not artifact-content or publisher trust.

## Executor, cleanup and retained stops

The proposed executor is one new sealed-source build-only task in an existing
private-repository Actions job: standard Ubuntu native runners, at most two
matrix jobs, 30 minutes/job and one original attempt. It does not adopt a
stopped inspector/retrieval/observation/failover task by changing its name or
repository. The private workflow owner retains any eventual real workflow
source. This public adapter installs or changes no trigger in either repository.

Cleanup uses the existing owner-bound direct-ID primitives and a private job's
`finally` path, with independent absence readback and the original job timeout /
ephemeral-runner teardown as backstop. Label discovery alone is insufficient.
The actual daemon/resource IDs, immutable materialization paths and cleanup
receipt are UNBOUND. No cleanup operation is invoked here and no bounded
completion is claimed. Job timeout and logical parallelism do not establish the
older hard CPU/RAM/PID or global egress/working-store caps. The old monitored
daemon-store cutoff is not silently carried into this proposal.

- **A/B:** source authoring and ordinary source CI only; no native release or
  installed qualification follows from green source tests.
- **C, new task:** a future private-context source/preparation/Buildx/SPDX and
  bounded fresh-output retention proposal, still unwired/unselected. All
  effect fields remain null and a fresh operator decision remains required.
- **C, stopped tasks:** existing native inspector/retrieval/observation and
  Server full failover stops stay held; no replacement route is provided.
- **D:** candidate execution, installed/runtime/browser acceptance, publication,
  release/latest/aliases, tuple/examples and downstream adoption remain held.
  Production, credential, spend and private-custody gates are unchanged.

General owns final scope selection. Waterline owns this adapter/input source;
the private workflow owner owns real origin/readers and any later workflow.
Security's eventual exact source/effect review remains separate from ordinary
source integration. No blocked review is reconstructed or replaced here.

Run inert source controls with the existing host Python and Git:

```sh
python3 -m unittest discover -s scripts/ci/native-artifact-adapter -p 'test_*.py'
```
