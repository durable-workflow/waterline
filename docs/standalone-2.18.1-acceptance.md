# Standalone 2.18.1 acceptance scope

This is the source-only effect map requested in [#311](https://github.com/durable-workflow/waterline/issues/311#issuecomment-6864196034).
It selects no execution and adds no executable, job, flag, input, permission,
upload or retrieval route. General retains the concrete source/scope disposition;
Security counter-reviews the complete implementation before the first qualifying
run. An unresolved row below stays unselected. This is not a dispatch packet.

## Current target and independent subjects

The accepted release-preparation baseline is commit
`115cc8213568bb868327c79e3e9ad8581b278ee4`, tree
`0bacd558e6f84a99f55e60b514c1fe064629c123`. Readback after its November 13
20:36 UTC merge found the 2.18.1 tag absent. The
standalone lock SHA256 is
`c2181f466bcbb799e3ce183d58531574d32de0cf9eba2f78dd9c18b211c60466`.
Its 79 production records include Laravel 12.72.0 at
`b089d3020e508f49b3625726ab4a2c897f1b78d5` and unchanged PHP SDK 3.16.0 at
`00a531e688ccbcaf4ce59a662cdbcd03dd49ee4c`.

The existing `waterline_release_identity.production_graph()` comparator's
sorted compact JSON for that lock hashes to
`b37c7f2bb7eff8231d65ce6665acae51ad9d6b06fcaf9cf70dac9193b8735d3b`.
This is an expected Git value, not an installed observation. The complete lock,
not only these two packages or this digest, is the comparison authority.

The final application source, acceptance runner commit/tree and workflow/helper
hashes must be independently fixed after the implementation is reviewed. Each
native archive/config/platform and later published index/child must be bound to
that source and to its actual run/attempt. Those artifact and execution subjects
are currently UNBOUND. An image-reported version or a candidate-supplied expected
hash cannot supply them.

## Effect-to-stop map

| Proposed effect | Existing ordinary location | Selection boundary |
| --- | --- | --- |
| Read accepted Git manifests, lock and workflow/helper bytes; run inert parser tests | Existing source contracts and reviewed Git objects | Source authoring only. No image, acquisition or collection follows from a parser pass. |
| Acquire production dependencies and build fresh native OCI | Existing unprivileged `service-image.yml` jobs | Unselected. Requires the final exact source/workflow and explicit current-target scope disposition before a tag or dispatch. No held preparation recipe, archive reader, sealed cache, M1 source or fallback. |
| Scan and import the just-produced OCI on its original runner | Existing native job's scanner and `skopeo copy` steps | Unselected with the native run. Retain exact archive/platform/config and fresh scanner/database subjects. This does not retrieve a remote or historical native archive. |
| Read `/app/composer.lock` and `/app/vendor/composer/installed.json` from the exact image before application start | Proposed narrow addition to ordinary unprivileged acceptance | Implemented but unwired in #317. Use a captured, inspected creation ID; bounded regular-file extraction; reject links, extra members, duplicate JSON keys and oversized output. Compare all 79 packages outside application code before autoload or startup. No held collector invocation or port. |
| Start the ordinary service/fixture roles and inspect PHP and canonical classes | Existing `service-mode-image-smoke.sh` ordinary path | New graph/cache integration remains unselected. Bind the inspected image/config and captured role IDs, synthetic data only, no host control/code mounts or production credentials. The two existing held proposal variables remain unset. |
| Clear, build, inspect and clear the route cache in that fresh application filesystem | Proposed narrow addition to the same ordinary path | Implemented with inert controls, still unwired. Only current-target `/app/bootstrap/cache` in the disposable role is writable for these commands. No old compiled cache/vendor copy or M1 collector supplies evidence. |
| Observe stable routes and native service/API/Server-budget behavior before and after cache rebuild | Existing ordinary native service acceptance | Preserve all native assertions and bind each observation to its phase and role. The existing job division does not require adding the full Playwright matrix to native OCI jobs. No shortened assertions, synthetic PASS, production probe or Server failover. |
| Repeat all browser budget, typed refusal and recovery assertions across cache phases | Existing published-image request-budget job | Phase-wrap the complete unchanged browser assertions in their existing job. Each published platform retains its required graph/cache proof and exact source/artifact/config binding. No earlier phase, base or platform supplies a missing observation. |
| Repeat graph/cache checks on each anonymous published platform | Existing `published-image-qualification.yml` unprivileged image jobs | Unselected until a separately accepted published tuple exists. Reports must bind the exact index/child/config/source. Embedded hosts keep their separate lock/cache authority. |
| Verify and publish inert OCI; sign provenance | Existing protected publisher | Separate later gate. Publisher remains inert and its native artifact allowlist stays exactly `image.oci.tar` and `provenance.json`. It never executes the new observer or candidate code. |
| Retain graph/cache reports | Existing same-job acceptance evidence | Reports stay separate from the publisher's two-file input. No new remote retrieval/uploader, reusable admission token or cross-run collector route. Retention and exact output subjects must be fixed before selection. |
| Remove resources and emit success | Existing acceptance cleanup, corrected for the added roles | Full captured IDs plus ownership, finite deadlines and independent absence checks are required. A refused workspace must still permit ID-only cleanup. Foreign replacements are preserved. Uncertain cleanup is NONPASS. |

## Required source closure before selection

The minimal implementation belongs in the existing unprivileged acceptance
path. It must not import, copy, rename, enable or invoke either held
`source-smoke-preparation` or `source-smoke-evidence` proposal. Their literal
holds and both ordinary wrapper refusal branches remain unchanged.

The unwired implementation addresses the following source boundaries. Exact source and effect counter-review must still precede a run:

1. Bind the exact reader/commands to independently reviewed source, image and
   config subjects. Extract raw metadata before any application execution, with
   regular-file/no-follow, duplicate-key, byte-count and timeout refusals. Compare
   complete package names, versions and source references against the accepted
   lock using the existing inert comparator; reject Core, development, missing,
   duplicate or extra packages. Preserve source/dist authorities separately.
2. Record fresh vendor origin from the actual native build and its exact
   manifest/lock/context. Report the actual Composer command/options and cache
   behavior, rather than assuming plugins or caches were disabled. Begin with
   absent bootstrap route cache. Record actual PHP, configuration and canonical
   Waterline/Laravel class paths before cache operations.
3. Run clear-before, cache-build/inspect and clear-after in one clean role. Bind
   cache file identity/digest and stable route identities to each phase. Permit
   only the existing documented normalization for generated cached route names;
   changed methods, URI, middleware, actions, order or collisions refuse.
4. Exercise `/up` and the existing service/API, budget, typed refusal/recovery
   controls before and after rebuilding. Follow the [clarified existing job division](https://github.com/durable-workflow/waterline/issues/311#issuecomment-6867649075):
   native service/API and actual Server-budget checks stay in native acceptance;
   all browser assertions repeat in the existing published request-budget job.
   Preserve the actual assertions and source/artifact identities. Fix state isolation for repeated writes;
   do not count replayed synthetic reports as fresh behavior.
5. Specify and inspect finite CPU/RAM/PID limits, role count, total deadline,
   per-command/output bounds, network, writable paths and cleanup ownership.
   Standard job timeout alone does not prove per-role limits. If adding init
   changes PID 1, adapt the existing effective-application-UID observation rather
   than treating the init process as PHP. No host-placement or quota changes.
6. Retain self-contained inert predecessor controls for stale/extra graphs,
   malformed extraction, stale cache/routes, subject swaps, output/deadline
   overruns and foreign resource replacements. Keep source fixtures distinct
   from actual native/published outcomes. Bind final evidence only after exact
   owned cleanup is independently confirmed.

No native or published installed graph/cache observation exists at this source
checkpoint.

The unwired ordinary implementation is being assembled in [#317](https://github.com/durable-workflow/waterline/pull/317).
Its native inner lifecycle keeps the service/API and actual Server-budget
assertions. The proposed published connection adds one alternative fixture
profile with a loopback-only proxy and a finite stdio control channel. One
invocation cannot create both fixture profiles. Neither profile mounts host
code or writable host data into a container.

The original browser programs remain byte-identical, including their pinned
ordinary-input hashes. `service_smoke_browser_source.py` constructs ordinary
callable modules from those exact inputs, checks their literal output hashes,
and retains all original assertion lines. The actual primary callable is
8,521 bytes, SHA256
`66830e03aa579b884c9a88fcee8f1dab3ec38baa5905cc0719043cd523dac4ec`;
the automatic-refresh callable is 2,616 bytes, SHA256
`5e258805d90be6f95d53652662670782067f612b83d66459e843b7039fb5a4ce`.
These subjects establish only source identity. The wrapper has no ordinary
call site and has not run a browser or application. It imports no held adapter,
VM hook or collector. Its complete source/scope disposition remains open.

Source/main checks, #312/#313 merges and this map do not qualify a patched base,
resume stopped M1 inspection/retrieval/observation tasks, permit a PHP Server
release, close #311, or allow #310's candidate execution.

## Unwired workflow connection

The complete ordinary invocation source is now present in
`service_smoke_bootstrap.py`. Its literal reviewed module digests and loader
identity are checked before compilation. `service_smoke_first_stage.py` is the
canonical source for a future literal trusted workflow run block. Reopening that
project file to obtain the first-stage guard is not an accepted invocation.
Neither file has a CLI or an ordinary acceptance call site.

The proposed connection changes the existing native OCI acceptance step and
existing published-image browser step only. Native acceptance imports the same
just-produced archive, then runs graph inspection, trust and deterministic memory
refusal controls, and all service/API and actual Server-budget assertions across
three cache phases. Published acceptance waits for the existing signed producer
verification, reads the current immutable registry index/child/attestation,
performs one bounded anonymous pull, then repeats graph/cache and all original
browser assertions in separate primary and automatic-refresh invocations. Each
invocation starts with its own filesystem and metadata observation.

An invocation permits at most five sequentially admitted roles: metadata, trust,
memory refusal, one of the two fixture profiles, and application. Application is
bounded at two CPUs, 768 MiB and 256 PIDs. Other roles have one CPU, 256 MiB and
128 PIDs. All use init, a read-only root, bounded private tmpfs and inspected
creation identities. Only the application and published proxy expose ephemeral
numeric loopback ports on an owned bridge with masquerading disabled. There are
no host code or data mounts. Each complete invocation has a shared 600-second
work deadline, bounded commands and output, followed by independent 120-second
cleanup. The anonymous pull has a 180-second limit within that work deadline.

The browser process uses the existing unprivileged job and pinned tooling. It
retains the Node executable descriptor, limits affinity to two CPUs, CPU time
to 120 seconds, wall time to 180 seconds, descriptors to 1,024, UID process
ceiling to 512, output files to 64 MiB and Node heap to 2,048 MiB. It only lowers
inherited ceilings. The observed physical host memory must be no more than
32 GiB and is recorded; this does not assert a private host-memory quota.

Before Docker creation, the invocation retains verified code in an independent
private source snapshot and writes the exact role context into the existing job
outputs. A proposed `always()` cleanup step reads that retained code independently
of the workspace and evidence roots. Lost create output can grant deletion-only
recovery after exact owner/run/role/image checks. Foreign or ambiguous objects
are preserved, and any unresolved cleanup makes the result NONPASS.

The complete report is emitted as bounded, counted, SHA256-bound chunks in the
existing Actions log with its observed retention (at most 90 days). Published
phase reports and screenshots remain under the existing browser evidence path
and seven-day uploader. Native publisher input remains exactly `image.oci.tar`
and `provenance.json`; reports are never added to that allowlist. No new workflow,
operator input, permission, uploader or historical evidence retrieval is added.

These are implemented source boundaries and proposed call sites. The workflow
connection, exact artifact/run subjects and first qualifying execution remain
unselected pending the complete source/effect review on #317.

Buildx raw-manifest reads have a separate fresh owner-only plugin-state directory.
Only that closed command receives `BUILDX_CONFIG`; ordinary Docker acquisition,
inspection and cleanup retain their unlinked, non-creatable credential root.
The plugin state is addressed through the controller's held descriptor, checked
before and after each read, and removed through the held parent even if its
namespace is renamed. A foreign replacement is preserved and makes cleanup
NONPASS. Bounded stderr and state cleanup outcomes remain in the same job log.
The causal controls use synthetic mkdir/descriptor children, not a live registry
or Buildx invocation. Actual plugin compatibility remains a separate effect gate.

The manifest attempt is recorded before state construction. Immediately after
`mkdir`, the constructor captures the no-follow directory identity before any
open or held `fstat`. Failed construction permits only removal of that exact
still-named empty owned inode. Unknown identity, nonempty state or a replacement
is preserved and reported as cleanup NONPASS. The original creation failure and
cleanup outcome are both retained. Predecessor controls against the earlier Git
source reproduce the two staged open/fstat cleanup failures.
