#!/usr/bin/env bash
#
# Build every branch in versions.json locally, without GitHub Actions.
#
# Resolves the same build matrix the workflows use (versions.json against
# Packagist via scripts/resolve-versions.py) and builds each branch with
# exactly the build arguments the matrix carries. What is left out on purpose:
#
#   - the foreign architecture, so the build runs natively on the host
#     (on Apple Silicon that is arm64, without qemu)
#   - provenance and SBOM, which only make sense for a pushed image
#
# Branches build one after another, so the shared php-base layers (base
# image, distribution packages, PHP extensions) are built once and reused,
# and a second run is a near-instant no-op.
#
# Each image is tagged with the full CI tag ladder (mittwald/kirby:5, 5.x.y,
# latest, ...) so `docker run mittwald/kirby:5` runs exactly what the next
# publish would ship.
#
# Usage:
#   scripts/local-build.sh              # build and smoke test all branches
#   scripts/local-build.sh --skip-smoke # build the images only

set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

SKIP_SMOKE=false

for arg in "$@"; do
	case "$arg" in
		--skip-smoke) SKIP_SMOKE=true ;;
		-h|--help)
			echo "usage: $0 [--skip-smoke]"
			echo ""
			echo "Builds every branch in versions.json for the host architecture,"
			echo "with the same build arguments CI uses, and smoke tests each"
			echo "image."
			echo ""
			echo "  --skip-smoke    build the images only, skip scripts/smoke-test.sh"
			exit 0
			;;
		*) echo "unknown option: $arg" >&2; exit 2 ;;
	esac
done

VCS_REF="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
BUILD_DATE="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

# The matrix as one line per branch:
#   image  branch  kirby  plainkit  php  frankenphp  base image  tag tag tag
resolve_matrix() {
	python3 scripts/resolve-versions.py --format json | python3 -c '
import json, sys
for entry in json.load(sys.stdin)["include"]:
    image = entry["tags"][0].split(":", 1)[0]
    print("\t".join([
        image,
        entry["branch"], entry["kirby_version"], entry["plainkit"],
        entry["php"], entry["frankenphp"], entry["base_image"],
        " ".join(entry["tags"]),
    ]))
'
}

build_branch() {
	local image="$1" branch="$2" kirby_version="$3" plainkit="$4" php="$5"
	local frankenphp="$6" base_image="$7" tags="$8"

	local tag
	local -a tag_list=()
	local -a tag_flags=()
	IFS=' ' read -r -a tag_list <<< "$tags"
	for tag in "${tag_list[@]}"; do
		tag_flags+=("--tag" "$tag")
	done

	echo "==> building kirby ${branch}: Kirby ${kirby_version} on PHP ${php} (${base_image})"
	docker build \
		--file image/Dockerfile \
		"${tag_flags[@]}" \
		--build-arg "BASE_IMAGE=${base_image}" \
		--build-arg "KIRBY_VERSION=${kirby_version}" \
		--build-arg "PLAINKIT_CONSTRAINT=${plainkit}" \
		--build-arg "PHP_VERSION=${php}" \
		--build-arg "FRANKENPHP_VERSION=${frankenphp}" \
		--build-arg "VCS_REF=${VCS_REF}" \
		--build-arg "BUILD_DATE=${BUILD_DATE}" \
		./image

	if [ "$SKIP_SMOKE" = false ]; then
		echo "==> smoke testing ${image}:${branch}"
		./scripts/smoke-test.sh "${image}:${branch}" \
			--kirby-version "$kirby_version" \
			--php-version "$php"
	fi
}

LATEST_TAG=""

while IFS=$'\t' read -r image branch kirby_version plainkit php frankenphp base_image tags; do
	case " $tags " in *" ${image}:latest "*) LATEST_TAG="${image}:latest" ;; esac
	build_branch "$image" "$branch" "$kirby_version" "$plainkit" "$php" \
		"$frankenphp" "$base_image" "$tags"
done < <(resolve_matrix)

echo ""
echo "done. the images are tagged like the published ones, e.g.:"
echo "  docker run --rm -p 8080:80 ${LATEST_TAG}"
