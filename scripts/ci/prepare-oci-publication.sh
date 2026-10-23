#!/usr/bin/env bash
set -euo pipefail

mode="${1:-prepare}"
[[ "$mode" = prepare || "$mode" = recheck ]]
[[ "$GITHUB_REPOSITORY" = durable-workflow/waterline ]]
[[ "$GITHUB_REF" = refs/heads/main ]]
for identity in "$REVIEWED_SOURCE" "$REVIEWED_TREE" "$BUILD_RUNNER" "$PUBLISHER_COMMIT"; do
    [[ "$identity" =~ ^[0-9a-f]{40}$ ]]
done
for fingerprint in "$BUILD_HASH" "$PUBLISHER_HASH"; do
    [[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]]
done
[[ "$BUILD_RUN" =~ ^[1-9][0-9]{0,19}$ ]]
[[ "$BUILD_ATTEMPT" =~ ^[1-9][0-9]{0,19}$ ]]
[[ "$GITHUB_SHA" = "$PUBLISHER_COMMIT" ]]

work="$RUNNER_TEMP/waterline-publication"
mkdir -p "$work"
check_registry_version() {
    local expected actual
    expected="$(jq -r .image_digest "$work/verified.json")"
    if skopeo inspect --raw --authfile "$work/anonymous-auth.json" \
        docker://docker.io/durableworkflow/waterline:2.13.2 > "$work/existing-index.json" 2> "$work/existing-index-error.txt"; then
        actual="sha256:$(sha256sum "$work/existing-index.json" | cut -d ' ' -f 1)"
        [[ "$actual" = "$expected" ]]
    elif ! rg --ignore-case 'manifest unknown|manifest_unknown' "$work/existing-index-error.txt" >/dev/null; then
        echo 'Cannot establish fixed registry tag absence; publication refused.' >&2
        return 1
    fi
}
gh api repos/durable-workflow/waterline/git/ref/tags/2.13.2 > "$work/tag-ref.json"
if [[ "$(jq -r .object.type "$work/tag-ref.json")" = tag ]]; then
    tag_object="$(jq -r .object.sha "$work/tag-ref.json")"
    [[ "$tag_object" =~ ^[0-9a-f]{40}$ ]]
    gh api "repos/durable-workflow/waterline/git/tags/$tag_object" > "$work/tag-object.json"
fi
gh api "repos/durable-workflow/waterline/commits/$REVIEWED_SOURCE" > "$work/commit.json"
gh api "repos/durable-workflow/waterline/compare/$REVIEWED_SOURCE...$PUBLISHER_COMMIT" > "$work/compare.json"
gh api "repos/durable-workflow/waterline/commits/$REVIEWED_SOURCE/pulls" --jq '{pulls:.}' > "$work/pulls.json"
gh api "repos/durable-workflow/waterline/actions/runs/$BUILD_RUN" > "$work/run.json"
gh api repos/durable-workflow/waterline/actions/workflows/service-image.yml > "$work/workflow.json"
gh api "repos/durable-workflow/waterline/contents/.github/workflows/service-image.yml?ref=$BUILD_RUNNER" > "$work/build-workflow.json"
gh api "repos/durable-workflow/waterline/actions/runs/$BUILD_RUN/artifacts?per_page=100" > "$work/artifacts.json"
python -I scripts/ci/oci_release.py plan --directory "$work" --output "$work/plan-next.json" \
    --source "$REVIEWED_SOURCE" --tree "$REVIEWED_TREE" --runner "$BUILD_RUNNER" \
    --run "$BUILD_RUN" --attempt "$BUILD_ATTEMPT" --publisher "$PUBLISHER_COMMIT" \
    --publisher-hash "$PUBLISHER_HASH" --build-hash "$BUILD_HASH"

if [[ "$mode" = recheck ]]; then
    cmp --silent "$work/plan.json" "$work/plan-next.json"
    check_registry_version
    exit 0
fi
mv "$work/plan-next.json" "$work/plan.json"
for architecture in amd64 arm64; do
    artifact_id="$(jq -r ".artifacts.$architecture.id" "$work/plan.json")"
    [[ "$artifact_id" =~ ^[1-9][0-9]{0,19}$ ]]
    gh api "repos/durable-workflow/waterline/actions/artifacts/$artifact_id/zip" > "$work/$architecture.zip"
done
python -I scripts/ci/oci_release.py prepare --directory "$work"

# Fail closed on any error other than an explicit registry manifest absence.
# This read is anonymous, even if the runner has another auth configuration.
umask 077
printf '%s\n' '{"auths":{}}' > "$work/anonymous-auth.json"
expected="$(jq -r .image_digest "$work/verified.json")"
skopeo copy --all --preserve-digests --authfile "$work/anonymous-auth.json" \
    --digestfile "$work/roundtrip-digest.txt" \
    "oci:$work/verified-oci:2.13.2" "oci:$work/roundtrip-oci:2.13.2"
[[ "$(cat "$work/roundtrip-digest.txt")" = "$expected" ]]
rm -r -- "$work/roundtrip-oci"
check_registry_version
