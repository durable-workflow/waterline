# Held native recipe source packet

This is source authoring for [Waterline #279](https://github.com/durable-workflow/waterline/issues/279).
It adds no workflow job, input, permission, dispatcher or execution grant. The
ordinary production Dockerfile and native builder/publisher workflows are
unchanged. `held-native-recipe.json` inventories the proposed envelope; it is
not an admission token. The exact Git commit/tree and complete member manifest
must be supplied independently in the review record.

The application stays the frozen M1 source/tree/79-package lock and 197-leaf
mapping. The trusted recipe is a separate input. It removes candidate autoload
and reflection probes, disables Composer scripts/plugins, consumes only the
independently sealed cache with network-none Composer steps, and limits
Composer/extension jobs to two. Trusted Composer may generate autoload files;
it does not load those candidate files. The final image entrypoint and health
check are data during an OCI build, not executed by this authoring code.

## Independent input and output authority

The existing `ArchiveReader` must seal all 79 actual objects before preparation
consumes them. Its authenticated-source-observation manifest binds exact bytes,
source/dist references, TLS and transport counters to the original source,
workflow/run/attempt/runner/daemon/nonce. It explicitly makes no upstream
published-checksum claim. Stock Composer downloads and candidate reports are
not alternatives. Its operational methods retain their literal holds.

`SealedArchives.composer_cache` checks all completed releases and retains exact
immutable cache bytes. SHA-1 is used only for Composer 2.10.3's cache filenames;
archive authority uses the independently observed SHA-256. `hosted_inputs`
combines those bytes with independently checked M1 Git leaves and the separate
trusted Dockerfile, then reuses the canonical archive boundary. No subsequent
worktree or cache-directory read may replace that retained tar. The two
standalone executables use the admitted 0755 image-leaf modes. Other M1 source
modes remain unchanged. The filesystem archive profile retains its original
roots/modes; the explicitly selected build-context profile is separate.

`hosted_build_command` describes one existing Buildx invocation, with that tar
on stdin, its trusted `Dockerfile`, no push/load/tag/cache exports and a local
OCI output. The proposed existing native job must hold the trusted reader and
its sealed objects across that invocation. A thin one-shot job adapter is
required before activation; no second controller, service or scheduler is
proposed. The worker config serializes BuildKit work so the recipe's two-job
settings do not multiply across concurrent stages. These are logical job
settings, not proof of host CPU/RAM/PID limits.

`hosted_artifact` reuses the immutable accepted OCI validator and checks the
actual supplied archive, platform, config, manifests, attestations and complete
blob closure. It requires the independently derived recipe, observed-source,
cache-manifest and canonical-context digests in the provenance arguments.
Opaque tar metadata/trailers, unreferenced blobs, changed subjects/platforms or
spliced inputs refuse. The strict tar profile is synthetic and unqualified for
an actual Buildx export. No native archive has been requested or inspected.

The resulting receipt is `waterline.build-only-native-artifact/v1`, with
qualification, execution verification, provider-origin verification and
publication admission all false. Its config digest is not a daemon image ID.
The existing publisher rejects this schema even if its provider ZIP digest is
truthful. Independent complete vendor/PHP byte trust, actual installed graph,
cache clear/build/inspect/clear and browser/runtime acceptance remain required
before any later candidate execution or promotion.

## Proposed executor and retained stops

| Boundary | Proposed executor and current disposition |
| --- | --- |
| A: local source authoring | This draft and inert Git/byte/OCI fixtures only. No candidate image, acquisition, artifact inspection/retrieval or publication. |
| B: ordinary source CI | Existing automatic PR/main jobs, including disposable unpublished source-image smoke and its current-stable regression control. No native release archive or installed acceptance follows. General's blanket source-CI hold is superseded; current required checks still apply. |
| C: new artifact production | Propose an explicitly selected, default-false manual mode in the existing `service-image.yml` native job on its standard Ubuntu amd64/arm64 runners, maximum two matrix jobs, 30 minutes per job, one original attempt. This mode is not implemented or selected by this packet. No tag trigger may activate it. |
| C: source capture/preparation | Same original job/runner, existing independent capture/seal primitives and exact retained context. CA/IP/tool/base/config identities must be independently bound before use. No stock-acquisition fallback, candidate token, scripts/plugin execution or candidate autoload/reflection. No new credential or larger/paid runner. |
| C: fresh archive validation/retention | Only the just-produced unqualified archive in its original job, at most 1 GiB per platform, bounded sanitized logs and a distinct build-only artifact with one-day retention. Proposed upload remains held. No scanner, daemon import, service/SDK/browser execution or later retrieval. |
| C: stopped native tasks | Frozen 2.15.0/#275 inspection, remote native evidence retrieval, rejected observation qualification, Server full failover and renamed equivalents stay stopped. A hosted VM grants no replacement authority for any of them. |
| C: later collector | Corrected collector origin is recorded separately. Neither it nor the legacy preparation handoff is invoked here. New native contexts are explicitly rejected by the old handoff/binder. Actual collector/image/daemon identity and cleanup qualification require their own existing gates. |
| D: publication and adoption | Protected publisher, anonymous installed acceptance, Release/latest, aliases, scan pointer, service-catalog observer, tuple/examples and downstream/Cloud adoption all remain held. Source CI and a build-only receipt do not release them. |

The proposed VM envelope differs from the older capped same-cell experiment.
The capture ledger can bound proxy-observed external transport to 2 GiB, with
128 MiB/object and 512 MiB quarantine pool. This is not a global BuildKit/APK
network quota. The 1 GiB retained archive check is not a hard working-store cap.
The older 2-CPU/3-GiB/256-PID execution caps and weaker monitored 8-GiB daemon-store
cutoff are not proved for this hosted builder. Standard job timeout/VM teardown,
logical worker/job settings and any separately bound driver limits must not be
reported as those old controls. Exact tool/parent/config/trust and effective
resource fields remain UNBOUND in the inventory; any absent field must refuse.

Central APT/setup remediation stays with the shared CLI owner. The proposed
build-only mode would skip native host skopeo installation with the skipped
import stage; it does not bless affected acquisition or publisher paths. Root
Alpine APK operations remain explicit build effects requiring the new input and
executor decision, not proof that all package-manager contexts are fixed.

## Exact fresh operator decision

General must request an explicit new-task decision for the named immutable
packet and proposed hosted envelope: whether to allow connecting the existing
native builder to one new sealed-source artifact-only attempt, including its
source/base/tool acquisition and preparation, Buildx execution, inert validation
of that new output and bounded private one-day upload. The decision must
explicitly reconcile the resource/egress/storage differences above. It must
exclude every stopped inspector/retrieval/observation/failover task and all D
operations; it is not permission to resume a stopped task on another executor.

This source packet alone is not a dispatch-ready cell. The thin existing-job
adapter and exact still-unbound static inputs must be completed and reviewed
before a concrete one-attempt dispatch can be selected. No manual dispatch,
activation, build, capture/seal, retention/retrieval, tag or publication is
requested by this draft. There is no automatic retry, timeout extension or
promotion path. General owns that operator request and later scoped selection;
Waterline owns source corrections and the remaining adapter/input closure.
