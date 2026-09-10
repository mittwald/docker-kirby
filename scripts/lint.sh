#!/usr/bin/env bash
#
# Every static check this repository runs, in one place.
#
# CI runs exactly this script, and every tool is pinned and containerised, so a
# green run here means a green run there. That parity is not decoration: the
# checks once passed locally and failed in CI because the workflow used
# hadolint 2.12 through an action while local runs used :latest, and 2.12
# cannot resolve `FROM ${BASE_IMAGE}` to a tagged image.
#
# Usage:
#   scripts/lint.sh          # run everything, report every failure
#   scripts/lint.sh hadolint # run one check

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

HADOLINT_IMAGE="hadolint/hadolint:v2.14.0"
SHELLCHECK_IMAGE="koalaman/shellcheck:v0.11.0"
ACTIONLINT_IMAGE="rhysd/actionlint:1.7.10"
PHP_IMAGE="dunglas/frankenphp:1-php8.4-trixie"

FAILED=()

run() {
	local name="$1"
	shift
	printf '\n\033[1m==> %s\033[0m\n' "$name"
	if "$@"; then
		printf '\033[32mok\033[0m\n'
	else
		printf '\033[31mFAILED\033[0m\n'
		FAILED+=("$name")
	fi
}

# --- individual checks ------------------------------------------------------

check_hadolint() {
	docker run --rm -i "$HADOLINT_IMAGE" hadolint --failure-threshold warning - < image/Dockerfile
}

check_shellcheck() {
	docker run --rm -v "$PWD:/mnt" -w /mnt "$SHELLCHECK_IMAGE" \
		--severity=warning scripts/*.sh image/entrypoint.sh
}

check_actionlint() {
	docker run --rm -v "$PWD:/repo" -w /repo "$ACTIONLINT_IMAGE" -color
}

check_python() {
	python3 -m compileall -q scripts \
		&& python3 -m unittest discover -s scripts -p 'test_*.py'
}

check_php() {
	docker run --rm -v "$PWD:/src" -w /src --entrypoint sh "$PHP_IMAGE" -c \
		'find image -name "*.php" -print0 | xargs -0 -n1 php -l'
}

check_caddyfile() {
	docker run --rm -v "$PWD/image:/src" -w /src --entrypoint frankenphp "$PHP_IMAGE" \
		fmt Caddyfile > /dev/null
}

# Both that the manifest resolves against Packagist, and that it is stored in
# the formatting the automation emits, so an automated bump shows only the line
# it changed.
check_versions() {
	python3 scripts/resolve-versions.py > /dev/null || return 1
	python3 scripts/check-updates.py --reformat || return 1
	if ! git diff --quiet versions.json; then
		echo "versions.json is not canonically formatted." >&2
		echo "Fix: python3 scripts/check-updates.py --reformat" >&2
		git --no-pager diff versions.json >&2
		return 1
	fi
}

# --- driver -----------------------------------------------------------------

declare -a CHECKS=(hadolint shellcheck actionlint python php caddyfile versions)

if [ $# -gt 0 ]; then
	CHECKS=("$@")
fi

for check in "${CHECKS[@]}"; do
	if ! declare -F "check_${check}" > /dev/null; then
		echo "unknown check: ${check}" >&2
		echo "available: ${CHECKS[*]}" >&2
		exit 2
	fi
	run "$check" "check_${check}"
done

printf '\n'
if [ ${#FAILED[@]} -eq 0 ]; then
	printf '\033[32mall checks passed\033[0m\n'
	exit 0
fi

printf '\033[31mfailed: %s\033[0m\n' "${FAILED[*]}"
exit 1
