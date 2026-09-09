#!/usr/bin/env bash
#
# Starts a built mittwald/kirby image and asserts that it actually serves
# Kirby, that the environment configuration reaches the CMS, and that the
# writable roots behave both for the default unprivileged user and for the
# root + drop-privileges path used with host bind mounts.
#
# Usage:
#   scripts/smoke-test.sh IMAGE [--kirby-version 5.5.3] [--php-version 8.4]

set -euo pipefail

IMAGE=""
EXPECTED_KIRBY=""
EXPECTED_PHP=""

while [ $# -gt 0 ]; do
	case "$1" in
		--kirby-version) EXPECTED_KIRBY="$2"; shift 2 ;;
		--php-version) EXPECTED_PHP="$2"; shift 2 ;;
		-h|--help) sed -n '2,10p' "$0"; exit 0 ;;
		-*) echo "unknown option: $1" >&2; exit 2 ;;
		*) IMAGE="$1"; shift ;;
	esac
done

[ -n "$IMAGE" ] || { echo "usage: $0 IMAGE [--kirby-version X] [--php-version Y]" >&2; exit 2; }

RUN_ID="kirby-smoke-$$"
CONTAINER=""
VOLUME_PREFIX="${RUN_ID}-vol"
FAILURES=0
CHECKS=0

# --- reporting --------------------------------------------------------------

pass() { CHECKS=$((CHECKS + 1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { CHECKS=$((CHECKS + 1)); FAILURES=$((FAILURES + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
group() { printf '\n\033[1m%s\033[0m\n' "$1"; }

assert_eq() {
	local expected="$1" actual="$2" label="$3"
	if [ "$expected" = "$actual" ]; then
		pass "$label"
	else
		fail "$label (expected '${expected}', got '${actual}')"
	fi
}

assert_contains() {
	local haystack="$1" needle="$2" label="$3"
	if printf '%s' "$haystack" | grep -qF -- "$needle"; then
		pass "$label"
	else
		fail "$label (missing '${needle}')"
	fi
}

assert_not_contains() {
	local haystack="$1" needle="$2" label="$3"
	if printf '%s' "$haystack" | grep -qF -- "$needle"; then
		fail "$label (unexpectedly found '${needle}')"
	else
		pass "$label"
	fi
}

# --- container helpers ------------------------------------------------------

cleanup() {
	if [ -n "$CONTAINER" ]; then
		docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
	fi
	docker volume rm "${VOLUME_PREFIX}-content" "${VOLUME_PREFIX}-storage" "${VOLUME_PREFIX}-media" >/dev/null 2>&1 || true
	rm -rf "${BIND_DIR:-}" 2>/dev/null || true
}
trap cleanup EXIT

# Publishes to an ephemeral host port and reports it, so parallel matrix jobs
# on the same runner never collide.
start_container() {
	local name="$1"; shift
	docker rm -f "$name" >/dev/null 2>&1 || true
	docker run -d --name "$name" -P "$@" "$IMAGE" >/dev/null
	CONTAINER="$name"
}

host_port() {
	docker port "$CONTAINER" "$1" | head -n1 | sed 's/.*://'
}

wait_healthy() {
	local port="$1" attempt=0
	until curl -fsS "http://127.0.0.1:${port}/healthz" >/dev/null 2>&1; do
		attempt=$((attempt + 1))
		if [ "$attempt" -ge 60 ]; then
			echo "container did not become healthy within 60s" >&2
			docker logs "$CONTAINER" >&2 || true
			return 1
		fi
		if [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" != "true" ]; then
			echo "container exited before becoming healthy" >&2
			docker logs "$CONTAINER" >&2 || true
			return 1
		fi
		sleep 1
	done
}

status_of() {
	curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT}$1"
}

body_of() {
	curl -s "http://127.0.0.1:${HTTP_PORT}$1"
}

echo "smoke testing ${IMAGE}"

# ---------------------------------------------------------------------------
# Phase 1 — default run, no configuration at all beyond a renamed panel so the
# environment -> Kirby option path is exercised end to end.
# ---------------------------------------------------------------------------
group "phase 1: default container"

start_container "$RUN_ID" \
	-e KIRBY_PANEL_SLUG=steuerung \
	-e PHP_MEMORY_LIMIT=512M \
	-e KIRBY_OPTIONS_JSON='{"smartypants":true}'

HTTP_PORT="$(host_port 80/tcp)"
HEALTH_PORT="$(host_port 8090/tcp)"
wait_healthy "$HEALTH_PORT"

assert_eq "200" "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HEALTH_PORT}/healthz")" "health endpoint responds"
assert_eq "200" "$(status_of /)" "home page responds"
assert_contains "$(body_of /)" "<h1>Home</h1>" "home page is rendered by Kirby"

# Kirby renders its own error page for unknown routes; a raw Caddy 404 would
# not contain the content from content/error.
assert_eq "404" "$(status_of /this-page-does-not-exist)" "unknown page is a 404"
assert_contains "$(body_of /this-page-does-not-exist)" "could not be found" "404 is Kirby's error page, not Caddy's"

group "phase 1: environment configuration reaches Kirby"
# The panel redirects an anonymous visitor to its login/installation view, so
# the redirect has to be followed to see whether the slug took effect.
assert_eq "200" "$(curl -sL -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT}/steuerung")" "panel is served from KIRBY_PANEL_SLUG"
assert_eq "404" "$(status_of /panel)" "default panel slug is gone once overridden"

# PHP_* variables are applied through the Caddyfile's php_ini block, which only
# covers the server. The CLI keeps the static defaults from zz-kirby.ini, and
# checking for that confirms the ini file is loaded at all.
assert_eq "256M" "$(docker exec "$CONTAINER" php -r 'echo ini_get("memory_limit");')" "static php.ini defaults apply to the CLI"

docker exec -i "$CONTAINER" sh -c 'cat > /app/public/_probe.php' <<<'<?php echo json_encode(["memory"=>ini_get("memory_limit"),"upload"=>ini_get("upload_max_filesize"),"opcache"=>(int)ini_get("opcache.enable"),"validate"=>ini_get("opcache.validate_timestamps"),"tz"=>ini_get("date.timezone"),"display"=>ini_get("display_errors")]);'
PROBE="$(body_of /_probe.php)"
docker exec "$CONTAINER" rm -f /app/public/_probe.php

assert_contains "$PROBE" '"memory":"512M"' "PHP_MEMORY_LIMIT is applied to the server"
assert_contains "$PROBE" '"upload":"128M"' "upload_max_filesize keeps its default"
assert_contains "$PROBE" '"opcache":1' "OPcache is enabled"
assert_contains "$PROBE" '"validate":"0"' "OPcache timestamp validation is off by default"
assert_contains "$PROBE" '"tz":"UTC"' "timezone defaults to UTC"
assert_contains "$PROBE" '"display":""' "display_errors is off"

group "phase 1: hardening"
assert_eq "404" "$(status_of /.env)" "dotfiles are blocked"
assert_eq "404" "$(status_of /.git/config)" "dotfiles in subdirectories are blocked"
assert_eq "404" "$(status_of /site/config/config.php)" "site/ is not web accessible"
assert_eq "404" "$(status_of /content/site.txt)" "content/ is not web accessible"
assert_eq "404" "$(status_of /kirby/bootstrap.php)" "kirby/ is not web accessible"
assert_eq "404" "$(status_of /storage/sessions)" "storage/ is not web accessible"
assert_eq "404" "$(status_of /composer.json)" "composer.json is not web accessible"

HEADERS="$(curl -s -D - -o /dev/null "http://127.0.0.1:${HTTP_PORT}/")"
assert_contains "$HEADERS" "X-Content-Type-Options: nosniff" "security headers are set"
assert_not_contains "$HEADERS" "X-Powered-By" "PHP version is not advertised"

group "phase 1: runtime identity and contents"
assert_eq "1000" "$(docker exec "$CONTAINER" id -u)" "runs as the unprivileged kirby user"
assert_eq "1000" "$(docker exec "$CONTAINER" id -g)" "runs with the kirby group"

if [ -n "$EXPECTED_KIRBY" ]; then
	assert_eq "$EXPECTED_KIRBY" "$(docker exec "$CONTAINER" php -r 'require "/app/kirby/bootstrap.php"; echo Kirby::version();')" "Kirby reports the pinned version"
	assert_eq "$EXPECTED_KIRBY" "$(docker exec "$CONTAINER" printenv KIRBY_VERSION)" "KIRBY_VERSION environment variable"
fi

if [ -n "$EXPECTED_PHP" ]; then
	assert_eq "$EXPECTED_PHP" "$(docker exec "$CONTAINER" php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" "PHP version"
fi

for extension in gd intl exif zip apcu Zend\ OPcache; do
	if docker exec "$CONTAINER" php -m | grep -qxF "$extension"; then
		pass "PHP extension ${extension} is present"
	else
		fail "PHP extension ${extension} is missing"
	fi
done

for path in /app/content /app/storage/accounts /app/storage/cache /app/storage/sessions /app/storage/logs /app/public/media; do
	if docker exec "$CONTAINER" test -w "$path"; then
		pass "${path} is writable"
	else
		fail "${path} is not writable"
	fi
done

LOGS="$(docker logs "$CONTAINER" 2>&1)"
assert_not_contains "$LOGS" "PHP Fatal error" "no fatal PHP errors in the log"
assert_not_contains "$LOGS" "not formatted" "Caddyfile is canonically formatted"

docker rm -f "$CONTAINER" >/dev/null
CONTAINER=""

# ---------------------------------------------------------------------------
# Phase 2 — the declared volumes survive a container replacement, and content
# written through Kirby's roots lands in them.
# ---------------------------------------------------------------------------
group "phase 2: volumes persist across container replacement"

docker volume create "${VOLUME_PREFIX}-content" >/dev/null
docker volume create "${VOLUME_PREFIX}-storage" >/dev/null
docker volume create "${VOLUME_PREFIX}-media" >/dev/null

VOLUME_ARGS=(
	-v "${VOLUME_PREFIX}-content:/app/content"
	-v "${VOLUME_PREFIX}-storage:/app/storage"
	-v "${VOLUME_PREFIX}-media:/app/public/media"
)

start_container "${RUN_ID}-vol1" "${VOLUME_ARGS[@]}"
HTTP_PORT="$(host_port 80/tcp)"
wait_healthy "$(host_port 8090/tcp)"

assert_contains "$(body_of /)" "<h1>Home</h1>" "seeded content is visible through the volume"

docker exec "$CONTAINER" sh -c 'mkdir -p /app/content/persisted && printf "Title: Persisted\n" > /app/content/persisted/default.txt'
docker exec "$CONTAINER" sh -c 'printf "written\n" > /app/storage/cache/smoke-test'
docker rm -f "$CONTAINER" >/dev/null

start_container "${RUN_ID}-vol2" "${VOLUME_ARGS[@]}"
HTTP_PORT="$(host_port 80/tcp)"
wait_healthy "$(host_port 8090/tcp)"

assert_eq "200" "$(status_of /persisted)" "page created in the content volume survives"
assert_eq "written" "$(docker exec "$CONTAINER" cat /app/storage/cache/smoke-test)" "storage volume survives"

docker rm -f "$CONTAINER" >/dev/null
CONTAINER=""

# ---------------------------------------------------------------------------
# Phase 3 — root start with a foreign-owned bind mount. The entrypoint has to
# take ownership and then drop back to the kirby user.
# ---------------------------------------------------------------------------
group "phase 3: root start drops privileges and fixes bind mount ownership"

BIND_DIR="$(mktemp -d)"
mkdir -p "${BIND_DIR}/content/bound"
printf 'Title: Bound\n' > "${BIND_DIR}/content/bound/default.txt"
printf 'Title: Site\n' > "${BIND_DIR}/content/site.txt"
chmod -R 700 "${BIND_DIR}"

start_container "${RUN_ID}-root" \
	--user 0:0 \
	-v "${BIND_DIR}/content:/app/content" \
	-e KIRBY_LICENSE='{"license":"K-SMOKE-TEST"}'

HTTP_PORT="$(host_port 80/tcp)"
wait_healthy "$(host_port 8090/tcp)"

assert_eq "200" "$(status_of /bound)" "content from the bind mount is served"
assert_eq "kirby" "$(docker exec "$CONTAINER" stat -c '%U' /proc/1)" "the server process dropped to the kirby user"
assert_eq "1000" "$(docker exec "$CONTAINER" stat -c '%u' /app/content)" "bind mount ownership was fixed up"
assert_eq '{"license":"K-SMOKE-TEST"}' "$(docker exec "$CONTAINER" cat /app/site/config/.license)" "KIRBY_LICENSE was written to the license file"
assert_eq "600" "$(docker exec "$CONTAINER" stat -c '%a' /app/site/config/.license)" "license file is not world readable"

docker rm -f "$CONTAINER" >/dev/null
CONTAINER=""

# ---------------------------------------------------------------------------
# Phase 4 — the single-volume setup from the README: content and media
# relocated below the storage root via KIRBY_ROOT_*.
# ---------------------------------------------------------------------------
group "phase 4: relocated roots on a single volume"

start_container "${RUN_ID}-roots" \
	-v "${VOLUME_PREFIX}-storage:/app/storage" \
	-e KIRBY_ROOT_CONTENT=/app/storage/content \
	-e KIRBY_ROOT_MEDIA=/app/storage/media

HTTP_PORT="$(host_port 80/tcp)"
wait_healthy "$(host_port 8090/tcp)"

for path in /app/storage/content /app/storage/media /app/storage/accounts; do
	if docker exec "$CONTAINER" test -w "$path"; then
		pass "${path} was created and is writable"
	else
		fail "${path} is missing or not writable"
	fi
done

docker exec "$CONTAINER" sh -c 'mkdir -p /app/storage/content/relocated && printf "Title: Relocated\n" > /app/storage/content/relocated/default.txt'
assert_eq "200" "$(status_of /relocated)" "Kirby reads pages from the relocated content root"

docker rm -f "$CONTAINER" >/dev/null
CONTAINER=""

# ---------------------------------------------------------------------------

group "result"
if [ "$FAILURES" -eq 0 ]; then
	printf '\033[32m%d/%d checks passed\033[0m\n' "$CHECKS" "$CHECKS"
	exit 0
fi

printf '\033[31m%d of %d checks failed\033[0m\n' "$FAILURES" "$CHECKS"
exit 1
