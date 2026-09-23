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
	docker rm -f "${RUN_ID}-mailpit" >/dev/null 2>&1 || true
	docker network rm "${RUN_ID}-net" >/dev/null 2>&1 || true
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
assert_contains "$(body_of /this-page-does-not-exist)" "<h1>Error</h1>" "404 is Kirby's error page, not Caddy's"

# The site skeleton is installed from Kirby's plainkit at build time rather
# than maintained in this repository. If that ever silently stops happening,
# the image would ship an empty site that still answers 200.
group "phase 1: the skeleton came from plainkit"
assert_eq "getkirby/plainkit" "$(docker exec "$CONTAINER" php -r 'echo json_decode(file_get_contents("/app/composer.json"))->name;')" "composer root package is plainkit"
for path in /app/site/templates/default.php /app/site/blueprints/site.yml /app/site/blueprints/pages/default.yml /app/content/home /app/content/error; do
	if docker exec "$CONTAINER" test -e "$path"; then
		pass "plainkit provided ${path}"
	else
		fail "plainkit did not provide ${path}"
	fi
done

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

MODULES="$(docker exec "$CONTAINER" php -m)"
for extension in gd intl exif zip apcu Zend\ OPcache; do
	if printf '%s' "$MODULES" | grep -qxF "$extension"; then
		pass "PHP extension ${extension} is present"
	else
		# Dumping the module list costs nothing here and saves a round trip:
		# an extension can be missing on one architecture only, which is not
		# something the assertion text alone would ever reveal.
		fail "PHP extension ${extension} is missing; php -m reported: $(printf '%s' "$MODULES" | tr '\n' ' ')"
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
# Phase 5 — option groups that Kirby reads as a whole. `email`, `thumbs` and
# `cache.pages` are looked up by their parent key, so the KIRBY_* variables
# only reach them as nested arrays. The mail is sent to a Mailpit instance
# that requires SMTP auth, so its arrival proves the credentials were used.
# ---------------------------------------------------------------------------
group "phase 5: nested options and SMTP mail"

MAILPIT_IMAGE="axllent/mailpit:v1.31.2"
MAIL_SUBJECT="smoke test ${RUN_ID}"

docker network create "${RUN_ID}-net" >/dev/null
docker run -d --name "${RUN_ID}-mailpit" --network "${RUN_ID}-net" --network-alias mailpit -P \
	-e MP_SMTP_AUTH="smoke:s3cret" \
	-e MP_SMTP_AUTH_ALLOW_INSECURE=true \
	"$MAILPIT_IMAGE" >/dev/null
MAILPIT_PORT="$(docker port "${RUN_ID}-mailpit" 8025/tcp | head -n1 | sed 's/.*://')"

start_container "${RUN_ID}-mail" \
	--network "${RUN_ID}-net" \
	-e KIRBY_EMAIL_TRANSPORT=smtp \
	-e KIRBY_EMAIL_HOST=mailpit \
	-e KIRBY_EMAIL_PORT=1025 \
	-e KIRBY_EMAIL_USER=smoke \
	-e KIRBY_EMAIL_PASSWORD=s3cret \
	-e KIRBY_EMAIL_SECURITY=false \
	-e KIRBY_THUMBS_QUALITY=70 \
	-e KIRBY_CACHE_PAGES=true \
	-e KIRBY_CACHE_PAGES_TYPE=apcu \
	-e KIRBY_AUTH_METHODS=password,code \
	-e KIRBY_AUTH_EMAIL_FROM=login@example.com \
	-e "KIRBY_AUTH_EMAIL_FROM_NAME=Smoke Sender" \
	-e KIRBY_OPTIONS_JSON='{"email":{"presets":{"smoke":{"from":"kirby@example.com"}}},"auth":{"methods":["password"]}}'

wait_healthy "$(host_port 8090/tcp)"

attempt=0
until curl -fsS "http://127.0.0.1:${MAILPIT_PORT}/api/v1/info" >/dev/null 2>&1; do
	attempt=$((attempt + 1))
	[ "$attempt" -lt 30 ] || { echo "mailpit did not become ready within 30s" >&2; docker logs "${RUN_ID}-mailpit" >&2 || true; exit 1; }
	sleep 1
done

# Each line is prefixed so that a probe that never ran is distinguishable
# from one that ran and printed an empty value.
PROBE="$(docker exec -i -e MAIL_SUBJECT="$MAIL_SUBJECT" "$CONTAINER" php <<'EOF' 2>&1 || true
<?php
require '/app/kirby/bootstrap.php';
$kirby = new Kirby(['roots' => ['index' => '/app/public', 'base' => '/app', 'site' => '/app/site', 'content' => '/app/content', 'storage' => '/app/storage']]);
$transport = $kirby->option('email')['transport'] ?? [];
echo 'TRANSPORT=', json_encode([$transport['type'] ?? null, $transport['username'] ?? null, $transport['auth'] ?? null, $transport['security'] ?? null]), "\n";
echo 'PRESET=', json_encode($kirby->option('email')['presets']['smoke']['from'] ?? null), "\n";
echo 'THUMBS=', json_encode($kirby->option('thumbs')['quality'] ?? null), "\n";
echo 'CACHE=', json_encode($kirby->option('cache.pages')), "\n";
echo 'METHODS=', json_encode($kirby->option('auth.methods')), "\n";
try {
	$kirby->email(['to' => 'nobody@example.com', 'subject' => 'unauthenticated', 'body' => '-', 'from' => 'kirby@example.com', 'transport' => ['type' => 'smtp', 'host' => 'mailpit', 'port' => 1025, 'security' => false]]);
	echo "UNAUTH=accepted\n";
} catch (Throwable $e) {
	echo "UNAUTH=rejected\n";
}
try {
	$kirby->email('smoke', ['to' => 'smoke@example.com', 'subject' => getenv('MAIL_SUBJECT'), 'body' => 'sent by the smoke test']);
	echo "SENT=ok\n";
} catch (Throwable $e) {
	echo 'SENT=failed ', $e->getMessage(), "\n";
}
// The login code mail takes its sender from auth.challenge.email.*, not from
// the transport. The recipient does not have to exist as an account.
try {
	Kirby\Cms\Auth\EmailChallenge::create(new Kirby\Cms\User(['email' => 'editor@example.com']), ['mode' => 'login', 'timeout' => 600]);
	echo "CHALLENGE=ok\n";
} catch (Throwable $e) {
	echo 'CHALLENGE=failed ', $e->getMessage(), "\n";
}
EOF
)"

assert_contains "$PROBE" 'TRANSPORT=["smtp","smoke",true,false]' "KIRBY_EMAIL_* reach option('email') with auth switched on"
assert_contains "$PROBE" 'PRESET="kirby@example.com"' "KIRBY_OPTIONS_JSON merges into the email group instead of replacing it"
assert_contains "$PROBE" 'THUMBS=70' "KIRBY_THUMBS_QUALITY reaches option('thumbs')"
assert_contains "$PROBE" 'CACHE={"active":true,"type":"apcu"}' "KIRBY_CACHE_PAGES* reach option('cache.pages')"
assert_contains "$PROBE" 'METHODS=["password"]' "a list in KIRBY_OPTIONS_JSON replaces the one from the environment"
assert_contains "$PROBE" 'UNAUTH=rejected' "mailpit refuses mail without SMTP auth"
assert_contains "$PROBE" 'SENT=ok' "Kirby sends mail through the configured SMTP transport"

MESSAGES="$(curl -s "http://127.0.0.1:${MAILPIT_PORT}/api/v1/messages")"
assert_contains "$MESSAGES" "\"Subject\":\"${MAIL_SUBJECT}\"" "the mail arrived in mailpit"
assert_contains "$PROBE" 'CHALLENGE=ok' "Kirby sends a login code"
assert_contains "$MESSAGES" '"From":{"Name":"Smoke Sender","Address":"login@example.com"}' "the login code comes from KIRBY_AUTH_EMAIL_FROM{,_NAME}"

docker rm -f "$CONTAINER" "${RUN_ID}-mailpit" >/dev/null
docker network rm "${RUN_ID}-net" >/dev/null
CONTAINER=""

# ---------------------------------------------------------------------------

group "result"
if [ "$FAILURES" -eq 0 ]; then
	printf '\033[32m%d/%d checks passed\033[0m\n' "$CHECKS" "$CHECKS"
	exit 0
fi

printf '\033[31m%d of %d checks failed\033[0m\n' "$FAILURES" "$CHECKS"
exit 1
