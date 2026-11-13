# Native image publication

The bounded publisher source admits the fixed Waterline **2.18.0** candidate.
Package/tag and image delivery remain separate: do not announce an image or advance the current
product tuple until the normal package and anonymous native acceptance succeed.
Source admission is not execution approval. Existing operator stops and each
separate build, inspection, retrieval, publication and observation gate still
apply; changing the fixed version does not resume a stopped task.

1. Qualify the exact source, merge it, and independently record its commit/tree.
   Review both workflow commits and SHA-256 fingerprints. Never move a tag.
2. The tag starts `service-image.yml`. Native amd64/arm64 runners build OCI
   archives, import and test those exact images, and upload immutable artifacts.
   These jobs have read-only permissions and no publication Environment, registry
   login or registry cache write. The unprivileged dispatcher can also qualify a
   candidate or rebuild an immutable release from reviewed main tooling.
3. Independently review the successful run/attempt, workflow identities and
   native artifact hashes. Dispatch `service-image-publish.yml` from the exact
   reviewed protected-main commit with those identities. Only a tag-push build
   or a reviewed main recovery build is admissible for promotion.
4. The protected publisher verifies the GitHub tag, source/tree, run and native
   archives before login. It rejects unsafe archive paths, links, changed blobs,
   wrong platforms, source/version labels and SDK provenance. It assembles the
   two verified image descriptors and their attestation manifests into one
   index, and uses [Skopeo's digest-preserving
   copy](https://github.com/containers/skopeo/blob/main/docs/skopeo-copy.1.md) to
   the fixed version. It never checks out candidate code, builds its Dockerfile,
   starts its image or executes candidate scripts. Existing different version
   digests and ambiguous registry reads are refusals.
5. The same job signs GitHub build provenance for the published index and both
   platform images with `actions/attest-build-provenance` and pushes it to the
   registry. Authentication is removed, then the existing installed-package and
   native image workflow runs on fresh unprivileged runners with anonymous pulls.
   Record the new index/children and actual acceptance before release promotion.

## Public provenance

Each native build exports BuildKit SLSA v1 provenance (`mode=max`) and an SPDX
SBOM as one attestation manifest per image. The verifier admits exactly one
image and one attestation manifest per archive. That manifest must reference
the image digest, hold exactly the two statements, stay within the size cap,
and record the reviewed `SOURCE_COMMIT` and `WATERLINE_VERSION` build arguments.
The published index therefore has four entries, and its digest covers all of
them. The SBOM generator is BuildKit's syft scanner, pinned by digest in
`service-image.yml`.

BuildKit leaves the `subject` list of each in-toto statement empty in an OCI
export. The binding to the image is the attestation manifest's `subject` and the
index's `vnd.docker.reference.digest` annotation, and the verifier requires
both. If a statement does name subjects, each one must be the image digest.

From 2.14.0, published acceptance fails unless the index carries one
attestation manifest per platform, and unless `gh attestation verify` accepts the
index and both platform digests. That check runs against both the GitHub API
and the registry copy, signed by this repository's publisher workflow on
`main`. Waterline 2.13.2 was published without public provenance.

## If publication fails after the push

A registry tag is immutable once pushed. The publisher still admits a rerun
with the same reviewed inputs when the tag already names the identical index.
The copy is then a no-op, and the attest steps sign again.
- **The attest step fails after the copy:** rerun the publisher with the same
  inputs. Never retag or overwrite.
- **Published acceptance fails** (provenance guard, browser or installed
  package): do not announce the release or advance the product tuple. Record
  the failure on the release issue, then fix forward in the next patch
  version.
- **An image already exists under the tag with a different index:** the
  publisher refuses. Investigate before taking any other action.

The publication Environment must admit only the protected-main publisher and
hold the narrowest available expiring registry push credential. Remove the old
repository-level copies before tagging. Account-side predecessor revocation is
a separate coordinated action after all known holders migrate; deleting GitHub
secrets does not revoke a registry credential.

The OCI layout and index follow the [OCI image layout](https://github.com/opencontainers/image-spec/blob/main/image-layout.md)
and [image index](https://github.com/opencontainers/image-spec/blob/main/image-index.md)
specifications. Archives and provenance are inert input to the publisher, not
commands. Missing, failed, expired, stale or mismatched evidence never qualifies.

## Security patch installed-graph and route-cache qualification

The 2.18.0 source candidate retains the reviewed production lock. Before any
artifact can be accepted, collect the actual Composer installed-package metadata
inside a clean qualification filesystem and compare every production package's
name, version and source reference with the lock archived from the independently
reviewed source commit. Missing, extra, duplicate, development or Core packages
are refusals. An SDK-only check does not establish the complete installed graph.
Keep the standalone graph separate from an embedded host's own lock and cache.

The optional `--installed-json` input to `waterline_release_identity.py` compares
that metadata as inert JSON. It neither installs packages nor starts an image,
and existing workflows do not enable this optional check automatically. Its
receipt alone does not establish the origin of the metadata: the separately
reviewed collector must bind the actual source commit, lock digest, platform,
image config and OCI archive or published manifest to the package observation.

On each admitted native candidate architecture and each anonymous published
platform, qualification must begin with clean vendor/bootstrap-cache state,
verify the actual PHP and canonical Waterline class paths, then clear, build,
inspect and clear the route cache against the patched installed graph. Compare
stable route identities and exercise `/up` plus existing service API, request
budget and refusal/recovery controls before and after the rebuild. Retain the
cache and graph observations with the exact source/config/artifact subjects.
Neither the Dockerfile nor entrypoint currently prebuilds a route cache; this
qualification requirement does not change production cache policy. No compiled
cache or vendor tree from an older artifact can stand in for this proof.

Keep any additional graph/cache report separate from the publisher's existing
two-file native-artifact allowlist. The protected publisher must continue to
verify inert content without executing Composer, candidate helpers or images.
Fresh complete dependency audits, both full package inventories, current scan
databases and zero HIGH/CRITICAL findings remain separate acceptance conditions.
Frozen 2.15.0 source, tag, digests, aliases and observer/cohort records remain
historical inputs and supply no clearance for the new candidate.
