#!/bin/sh
#
# Entrypoint for the mittwald/kirby images.
#
# Prepares the writable roots, materialises the Kirby license from the
# environment and then hands over to FrankenPHP's own entrypoint. Every step is
# a no-op when there is nothing to do, so an unconfigured `docker run` still
# comes up.

set -eu

KIRBY_APP_ROOT="${KIRBY_APP_ROOT:-/app}"
KIRBY_USER="${KIRBY_USER:-kirby}"

log() {
	printf '[kirby-entrypoint] %s\n' "$*" >&2
}

fail() {
	log "error: $*"
	exit 1
}

# Roots that Kirby has to be able to write to. Kept in sync with the KIRBY_ROOT_*
# overrides in public/index.php so that relocated roots are prepared too.
writable_roots() {
	storage="${KIRBY_ROOT_STORAGE:-${KIRBY_APP_ROOT}/storage}"

	echo "${KIRBY_ROOT_CONTENT:-${KIRBY_APP_ROOT}/content}"
	echo "${KIRBY_ROOT_MEDIA:-${KIRBY_APP_ROOT}/public/media}"
	echo "${KIRBY_ROOT_ACCOUNTS:-${storage}/accounts}"
	echo "${KIRBY_ROOT_CACHE:-${storage}/cache}"
	echo "${KIRBY_ROOT_SESSIONS:-${storage}/sessions}"
	echo "${KIRBY_ROOT_LOGS:-${storage}/logs}"
}

# Running as root is not the default, but it is what happens with `-u 0` or on
# platforms that ignore the image USER. In that case, take ownership of the
# mounted paths and drop back to the unprivileged user before starting the
# server — this is what makes host bind mounts work without a manual chown.
drop_privileges_if_root() {
	[ "$(id -u)" = "0" ] || return 0

	if [ "${KIRBY_RUN_AS_ROOT:-false}" = "true" ]; then
		log "running as root because KIRBY_RUN_AS_ROOT=true"
		return 0
	fi

	uid="$(id -u "${KIRBY_USER}")"
	gid="$(id -g "${KIRBY_USER}")"

	writable_roots | while IFS= read -r path; do
		mkdir -p "${path}"
		chown -R "${uid}:${gid}" "${path}"
	done

	chown -R "${uid}:${gid}" /data/caddy /config/caddy 2>/dev/null || true

	log "dropping privileges to ${KIRBY_USER} (${uid}:${gid})"
	exec setpriv --reuid "${uid}" --regid "${gid}" --init-groups -- "$0" "$@"
}

prepare_roots() {
	writable_roots | while IFS= read -r path; do
		if [ ! -d "${path}" ] && ! mkdir -p "${path}" 2>/dev/null; then
			fail "cannot create ${path}. Mount it with write access for uid $(id -u), or start the container as root to have it fixed automatically."
		fi

		if [ ! -w "${path}" ]; then
			fail "${path} is not writable by uid $(id -u). Fix the ownership of the mounted volume, or start the container as root to have it fixed automatically."
		fi
	done
}

# The license file is JSON in Kirby 4 and 5. It is written verbatim so that the
# image does not have to know the format; paste the contents of the .license
# file you received, or point KIRBY_LICENSE_FILE at a mounted secret.
install_license() {
	license_root="${KIRBY_ROOT_SITE:-${KIRBY_APP_ROOT}/site}/config/.license"

	if [ -n "${KIRBY_LICENSE_FILE:-}" ]; then
		[ -r "${KIRBY_LICENSE_FILE}" ] || fail "KIRBY_LICENSE_FILE=${KIRBY_LICENSE_FILE} is not readable"
		cat "${KIRBY_LICENSE_FILE}" > "${license_root}"
	elif [ -n "${KIRBY_LICENSE:-}" ]; then
		printf '%s' "${KIRBY_LICENSE}" > "${license_root}"
	else
		return 0
	fi

	chmod 600 "${license_root}"
	log "installed Kirby license into ${license_root}"
}

drop_privileges_if_root "$@"
prepare_roots
install_license

exec docker-php-entrypoint "$@"
