#!/usr/bin/env bash
#
# Starts a built mittwald/kirby image and asserts that it actually serves
# Kirby. The tests are a PHPUnit suite in tests/smoke; this script only maps
# its arguments onto that suite and installs the suite's dependencies.
#
# Usage:
#   scripts/smoke-test.sh IMAGE [--kirby-version 5.5.3] [--php-version 8.4] [PHPUNIT OPTIONS]
#
# Anything else is passed to PHPUnit, e.g. `--filter FirstAdmin` to run one
# scenario. Needs php >= 8.4 and composer on the host.

set -euo pipefail

SUITE="$(cd "$(dirname "$0")/../tests/smoke" && pwd)"
IMAGE=""
PHPUNIT_ARGS=()

while [ $# -gt 0 ]; do
	case "$1" in
		--kirby-version) export SMOKE_KIRBY_VERSION="$2"; shift 2 ;;
		--php-version) export SMOKE_PHP_VERSION="$2"; shift 2 ;;
		-h|--help) sed -n '2,11p' "$0"; exit 0 ;;
		*)
			if [ -z "$IMAGE" ] && [ "${1#-}" = "$1" ]; then IMAGE="$1"; else PHPUNIT_ARGS+=("$1"); fi
			shift
			;;
	esac
done

[ -n "$IMAGE" ] || { echo "usage: $0 IMAGE [--kirby-version X] [--php-version Y] [PHPUNIT OPTIONS]" >&2; exit 2; }
export SMOKE_IMAGE="$IMAGE"

composer install --working-dir="$SUITE" --no-interaction --no-progress --quiet

echo "smoke testing ${IMAGE}"
exec "$SUITE/vendor/bin/phpunit" --configuration "$SUITE/phpunit.xml.dist" ${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"}
