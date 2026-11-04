# Native image publication

The bounded publisher currently admits Waterline **2.13.2**. Package/tag and
image delivery remain separate: do not announce an image or advance the current
product tuple until the normal package and anonymous native acceptance succeed.

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
