#!/usr/bin/env sh

# The same smoke recipe also exercises immutable historical release images.
# A missing new guard cannot qualify those images, but must not change their
# existing application smoke into a request for a file they never shipped.
image_requires_brotli_guard() {
    case "$1" in
        0.0.0-service-smoke) return 0 ;;
        2.*)
            brotli_image_core="${1%%-*}"
            if ! printf '%s\n' "$brotli_image_core" | grep -Eq '^2\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'; then
                return 2
            fi
            brotli_first_version="$(printf '%s\n' 2.12.3 "$brotli_image_core" | LC_ALL=C sort -V | head -n 1)"
            test "$brotli_first_version" = 2.12.3
            ;;
        *) return 2 ;;
    esac
}

image_requires_curl_guard() {
    case "$1" in
        0.0.0-service-smoke) return 0 ;;
        2.*)
            curl_image_core="${1%%-*}"
            if ! printf '%s\n' "$curl_image_core" | grep -Eq '^2\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'; then
                return 2
            fi
            curl_first_version="$(printf '%s\n' 2.13.2 "$curl_image_core" | LC_ALL=C sort -V | head -n 1)"
            test "$curl_first_version" = 2.13.2
            ;;
        *) return 2 ;;
    esac
}
