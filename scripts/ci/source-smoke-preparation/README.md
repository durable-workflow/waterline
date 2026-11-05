This is the **held source successor** for [Waterline #279](https://github.com/durable-workflow/waterline/issues/279).
It implements the recipe, compact receipt, independent binder and tool-source
closure selected at [6648699957](https://github.com/durable-workflow/waterline/issues/279#issuecomment-6648699957),
using the **single same-runner preparation + collection cell** selected at
[6648774601](https://github.com/durable-workflow/waterline/issues/279#issuecomment-6648774601).
That decision supersedes separate-run preparation. It changes no operator stop.

Every ordinary entrypoint refuses before helper imports or operational work.
There is no enable variable, workflow job, dispatch input or permission change.
The existing wrapper selects this refusal when
`WATERLINE_SOURCE_PREPARATION_PROPOSAL` is nonzero. Its ordinary path is unchanged.
CI executes only inert source tests. No recipe, provider reader, guardian Docker
adapter, dependency acquisition, preparation or collection has executed.

The immutable application target is **M1**:

- Source `3d68d7d8954a0743104b318d03708515c7e3c530`, tree
  `aadb0484b0330ed5bfaabde42245450cafa990c4`.
- Standalone lock SHA-256 `bc2014dd77b48074d41b9aded14a82cbab5c3676b59e36e5b2203aeb644e3506`;
  79 production packages; canonical source/dist graph SHA-256
  `ac685314450fb8d939005d69b813a98d28f3571253b8bd91fa6640af0daef777`.
- Exact Docker COPY mapping: 197 leaves, canonical SHA-256
  `b1a4a4edd0d83377371563e71c0fc7fa3dcbb5961b62e8c57601b3c5e5a3ddec`.
- PHP 8.3.36, 64-bit integers. No current main application source is substituted.

All twenty accepted collector members remain byte-identical to accepted
`f5b6bc8e0353f3fabaa8445021b4e6e5cd848921`, tree
`c341552e506cf512938e52d497fc082396bb0ffd`. That packet's own hold stays false.
This directory is a separate exact Git-object allowlist; its `custody.py`
retains regular-file bytes and a SHA-256 manifest, rejecting extras, omissions,
symlinks, executable files and oversized objects. A future independently
admitted launcher must use those retained bytes, never mutable worktree imports.
This change does not supply or activate that launcher.

`preparation.Dockerfile` is mechanically derived from the exact M1 Dockerfile.
`recipe.py` accepts only that derivation. The changes disable Composer plugins,
scripts and install-time autoload; cap Composer HTTP/process concurrency at two;
generate autoload and check it with BuildKit network disabled; and cap PHP
extension compilation at two jobs. It spells the same M1 base digests without
redundant tags, wraps the directory command and defaults the final image to
UID/GID 82, which the accepted collector already requires. COPY statements,
application dependencies, base digests and source/version labels stay M1. The recipe is held and
does not select a platform, build tool, trust configuration or image identity.

`browser-tool-lock.json` closes the npm source graph to Playwright and
playwright-core 1.64.0 with exact registry URLs, SHA-512 integrity, and their only
dependency edge. Chromium and headless-shell revision 1248 and ffmpeg 1013 come
from the upstream pinned browsers source and recorded SHA-256. Metadata was read
from the named primary registry/upstream sources; no package or browser archive
was acquired. This is a source closure, **not a complete runnable tool image**.

`producer.py` checks independently captured host observations against the fixed
M1 Git expectation and a separately admitted tool/config/trust contract. It
requires raw installed JSON before autoload, unique JSON keys, exact production
source/dist authorities, immutable files/classes/PHP, offline autoload and
resource/trust proofs. It serializes only those checked facts. `HostObservation`
is an internal host-reader boundary: it must never be constructed from a
candidate report. A live reader capable of proving these facts is not yet
provided or admitted by this source change; callers supplying synthetic facts
are test fixtures, not execution evidence.

`provider.py` is a held, bounded reader of the existing GitHub host identity.
It addresses only the exact repository/run/attempt/artifact API, validates
metadata before downloading at most 4 MiB, performs no retry loop and creates
no publisher. Candidate roles receive no provider token or reader. `binder.py`
validates provider run/source/event/attempt, nonce-specific artifact name,
unexpired artifact and actual downloaded ZIP digest. The archive contains only
one regular bounded JSON report. Binding must happen while the same run is
in progress. It compares the report against a **fresh independent host
recapture** on the same runner/daemon before deriving the receipt and exact
nine-key collector spec. Authenticated artifact bytes cannot endorse false
image/source/config facts. A cross-runner or later-run receipt refuses; an
artifact transfers metadata, never daemon-local images.

The proposed supported handoff in `cell.py` has one job in the existing
`.github/workflows/service-image-smoke.yml` wrapper. The future separately
admitted activation must perform these steps in that job:

1. Admit the static source/recipe/tool graph/platform/trust/config/resource
   contract and immutable launcher; start and independently handshake a guardian
   **before the first allocation**.
2. Prepare both local images, remove preparation networking before autoload,
   independently inspect the outputs and produce the compact report.
3. Upload only that report with the existing pinned upload-artifact action,
   one-day retention, then use host-only `actions:read` to read its API metadata
   and re-download its actual ZIP in the **same still-running job**.
4. Independently recapture the live daemon outputs, bind the provider bytes and
   derive the exact collector spec, or refuse before starting a collection role.
5. Use the unchanged held collector packet, its own immutable loader/backstop
   and tighter limits; retain no preparation network or provider authority in
   candidate roles. Verify cleanup of its attempted resources before releasing
   the parent lifecycle.
6. Remove owned preparation resources/images and confirm exact IDs, names and
   owner labels absent. Confirm all child processes exited and guardian stopped
   normally before retaining a successful compact outcome.

The whole cell is at most **45 minutes**: 20 preparation, 20 collection and
5 final cleanup. Preparation's active allocation limit stays 15 minutes;
collection's active limit stays 19 minutes. Preparation is at most 2 CPUs,
3 GiB, 256 PIDs, two jobs/requests, 2 GiB network and 8 GiB storage. The static
policy is not a measured budget: a live bounded resource meter/enforcer remains
part of the unadmitted execution adapter. No pure observation dictionary can
prove effective limits or replace that enforcement.

`guardian.py` supplies independent EOF/deadline cleanup logic and a held Docker
adapter. Before an allocation it rejects existing exact names or owner labels;
attempts and returned IDs bind a distinct resource nonce. Cleanup refuses
foreign/replaced objects, checks retained IDs independently of label filters,
and verifies empty exact inventories. It performs no global prune and removes
only owned images without force. It has no live immutable launcher here. The
unchanged collector retains its own reviewed guardian; this source's lifecycle
model is not a second recurring controller or authority grant. The actual
composition, hard-stop propagation and independent process/daemon absence
proof must be supplied and reviewed before any executable cell is accepted.

The following are **genuinely UNBOUND**, rather than placeholder identities:

| Contract | Unbound fields |
| --- | --- |
| Static browser image | base image ID, base config SHA-256, platform, full OS library inventory, all three browser binary SHA-256 hashes |
| Static admission | platform, complete expected application/tool Config bodies, CA SHA-256, effective preparation-tool config SHA-256 |
| Nine-key collector spec | platform, application image ID/config SHA-256, browser image ID/config SHA-256, authenticated preparation receipt SHA-256 |
| Execution closure | immutable host-reader/launcher, effective bounded build/acquisition adapter and meter, same-job upload/read/bind composition and process/daemon cleanup proof |

Only `source`, `packet_commit` and `packet_tree` of the nine-key spec are bound.
Static fields require exact independent input selection and source review.
Dynamic IDs require actual observations inside a future pre-admitted cell.
No OCI export/import, image upload/download handoff, persistent runner, alternate
host, second credential authority or image publisher is proposed.

Run inert controls with:

```sh
python3 -m unittest discover -s scripts/ci/source-smoke-preparation -p 'test_*.py'
```

Controls include the real old-recipe refusal/new-derivation pass, forged reports
whose provider digests truthfully match their lies, cross-daemon handoffs,
duplicate/extra/linked ZIP members, package/source drift, incomplete static/spec
fields, replaced resources and an independent child process's EOF/partial-input
deadline/normal-stop cleanup. These are source countercontrols, not preparation,
collector, installed-package, browser, image, conformance or release PASS.
