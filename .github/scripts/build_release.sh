#!/usr/bin/env bash
# Builds dist/modulento-<version>.zip and its .sha256 from the committed
# tree. Used by release.yml; runs locally too:
#   bash .github/scripts/build_release.sh 0.1.0
set -euo pipefail

version="${1:?usage: build_release.sh <version>}"
if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "version must look like 1.2.3" >&2
    exit 1
fi

root="$(git rev-parse --show-toplevel)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

# Only committed files end up in a release - never a local .env, logs or
# anything else lying around in the working tree. .gitattributes
# (export-ignore) keeps tests and CI files out.
git -C "$root" archive HEAD | tar -x -C "$stage"

composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --prefer-dist --quiet

# VERSION only ever exists inside a release package, see Updater::currentVersion().
printf '%s' "$version" > "$stage/VERSION"

mkdir -p "$root/dist"
zip_path="$root/dist/modulento-$version.zip"
rm -f "$zip_path" "$zip_path.sha256"

# -X drops extra file attributes; made on Linux, so every entry is marked
# as Unix, which Updater::extract() insists on.
(cd "$stage" && zip -q -r -X "$zip_path" .)
(cd "$root/dist" && sha256sum "modulento-$version.zip" > "modulento-$version.zip.sha256")

echo "Built $zip_path"
cat "$zip_path.sha256"
