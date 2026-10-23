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
   two verified manifest descriptors and uses [Skopeo's digest-preserving
   copy](https://github.com/containers/skopeo/blob/main/docs/skopeo-copy.1.md) to
   the fixed version. It never checks out candidate code, builds its Dockerfile,
   starts its image or executes candidate scripts. Existing different version
   digests and ambiguous registry reads are refusals.
5. Authentication is removed, then the existing installed-package and native
   image workflow runs on fresh unprivileged runners with anonymous pulls.
   Record the new index/children and actual acceptance before release promotion.

The publication Environment must admit only the protected-main publisher and
hold the narrowest available expiring registry push credential. Remove the old
repository-level copies before tagging. Account-side predecessor revocation is
a separate coordinated action after all known holders migrate; deleting GitHub
secrets does not revoke a registry credential.

The OCI layout and index follow the [OCI image layout](https://github.com/opencontainers/image-spec/blob/main/image-layout.md)
and [image index](https://github.com/opencontainers/image-spec/blob/main/image-index.md)
specifications. Archives and provenance are inert input to the publisher, not
commands. Missing, failed, expired, stale or mismatched evidence never qualifies.
