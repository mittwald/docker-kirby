# syntax=docker/dockerfile:1
#
# mittwald/kirby — Kirby CMS on FrankenPHP.
#
# Every variable part of the build comes from versions.json via the build
# matrix, so a new Kirby branch is a data change, never a Dockerfile change.

ARG FRANKENPHP_VERSION=1
ARG PHP_VERSION=8.4
ARG OS_VARIANT=trixie
ARG BASE_IMAGE=dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-${OS_VARIANT}


# ---------------------------------------------------------------------------
# Shared base: the PHP runtime Kirby needs. Both the Composer stage and the
# final image derive from it so the extensions are compiled exactly once.
# ---------------------------------------------------------------------------
# The base image is tagged — BASE_IMAGE above resolves to a pinned
# dunglas/frankenphp tag, and the build matrix overrides it with an explicit
# one. hadolint before 2.13 cannot follow the ARG and reports it as untagged.
# hadolint ignore=DL3006
FROM ${BASE_IMAGE} AS php-base

# Picks up distribution security fixes on the scheduled rebuilds even when the
# upstream FrankenPHP tag itself has not moved. This ties the image to the
# Debian base variants (`bookworm`, `trixie`); an Alpine base would need apk
# here instead.
RUN apt-get update \
	&& apt-get upgrade -y --no-install-recommends \
	&& rm -rf /var/lib/apt/lists/*

# ctype, curl, dom, filter, hash, iconv, json, libxml, mbstring, openssl,
# SimpleXML and fileinfo are already part of the base image. These are the ones
# Kirby additionally requires (gd) or recommends (exif, intl), plus zip for
# Composer-installed plugins and apcu for Kirby's apcu cache driver.
# https://getkirby.com/docs/reference/system/requirements
#
# opcache is listed even though the base image usually already has it: FrankenPHP
# does not enable it itself, it comes from the upstream PHP image, and it turned
# out to be present on arm64 but not on amd64 for the same image tag. This image
# configures opcache in php/kirby.ini and documents it as enabled, so it asks for
# it explicitly instead of inheriting it by luck. Installing an extension that is
# already there is a no-op.
RUN install-php-extensions \
	apcu \
	exif \
	gd \
	intl \
	opcache \
	zip


# ---------------------------------------------------------------------------
# Composer stage: installs Kirby's own plainkit as the site skeleton and pins
# the CMS to the release the build matrix resolved.
# ---------------------------------------------------------------------------
FROM php-base AS builder

ARG KIRBY_VERSION
ARG PLAINKIT_CONSTRAINT

RUN install-php-extensions @composer-2

ENV COMPOSER_ALLOW_SUPERUSER=1

# The skeleton is Kirby's plainkit rather than something maintained here, so
# templates, blueprints and the starting content stay upstream's business.
#
# plainkit is *not* released in lockstep with the CMS — its 4.x line stopped at
# 4.8.0 while Kirby 4 kept going — so the kit is resolved by major version and
# the exact CMS release is pinned right after. The version check makes a
# resolution that ignores the pin fail the build instead of shipping.
# Built in /kit rather than /app because the FrankenPHP base image already
# ships a welcome page at /app/public, and create-project refuses a non-empty
# target directory.
RUN --mount=type=cache,target=/tmp/composer,sharing=locked \
	COMPOSER_HOME=/tmp/composer composer create-project \
		getkirby/plainkit /kit "${PLAINKIT_CONSTRAINT:?PLAINKIT_CONSTRAINT build argument is required}" \
		--no-interaction \
		--no-progress \
		--remove-vcs \
	&& COMPOSER_HOME=/tmp/composer composer require --working-dir=/kit \
		"getkirby/cms:${KIRBY_VERSION:?KIRBY_VERSION build argument is required}" \
		--no-interaction \
		--no-progress \
		--no-audit \
		--optimize-autoloader \
	&& test -f /kit/kirby/bootstrap.php \
	&& test "$(php -r 'require "/kit/kirby/bootstrap.php"; echo Kirby::version();')" = "${KIRBY_VERSION}"

# plainkit ships the flat layout, with index.php in the document root. This
# image serves /app/public instead, so content, site, kirby and vendor cannot
# be requested over HTTP at all rather than relying on the web server to hide
# them. Only the front controller and the writable roots move; everything else
# plainkit ships stays exactly where it put it.
RUN set -eux; \
	mkdir -p /kit/public /kit/site/config /kit/site/plugins; \
	mv /kit/media /kit/public/media; \
	rm -f /kit/index.php /kit/.htaccess; \
	mkdir -p /kit/storage/accounts /kit/storage/cache /kit/storage/sessions /kit/storage/logs; \
	rm -rf /kit/site/accounts /kit/site/cache /kit/site/sessions


# ---------------------------------------------------------------------------
# Final image.
# ---------------------------------------------------------------------------
FROM php-base AS runtime

ARG KIRBY_VERSION
ARG PHP_VERSION
ARG FRANKENPHP_VERSION

# Composer stays in the final image so that derived images can install Kirby
# plugins with a single RUN instruction.
RUN install-php-extensions @composer-2

# Unprivileged runtime user with fixed ids, so that host bind mounts and
# Kubernetes securityContexts can rely on 1000:1000. frankenphp already carries
# cap_net_bind_service, so binding :80 works without root.
RUN groupadd --gid 1000 kirby \
	&& useradd --uid 1000 --gid 1000 --create-home --home-dir /home/kirby --shell /usr/sbin/nologin kirby

COPY php/kirby.ini /usr/local/etc/php/conf.d/zz-kirby.ini
COPY Caddyfile /etc/caddy/Caddyfile
COPY Caddyfile.d/ /etc/caddy/Caddyfile.d/
COPY share/ /usr/local/share/kirby/
COPY entrypoint.sh /usr/local/bin/kirby-entrypoint

WORKDIR /app

# Drop the base image's welcome page so nothing from it leaks into the app.
RUN rm -rf /app

# The whole tree as plainkit and Composer left it, then the two files this
# image owns: the front controller, and the config that bridges KIRBY_*
# environment variables into Kirby options.
COPY --from=builder --chown=kirby:kirby /kit/ /app/
COPY --chown=kirby:kirby app/ /app/

# `frankenphp validate` at the end turns a broken Caddyfile into a failed build
# instead of a failed deployment.
RUN set -eux; \
	chmod +x /usr/local/bin/kirby-entrypoint; \
	mkdir -p /app/site/plugins; \
	test -f /app/public/index.php; \
	test -f /app/site/config/config.php; \
	test -d /app/site/templates; \
	frankenphp validate --config /etc/caddy/Caddyfile --adapter caddyfile; \
	chown -R kirby:kirby /app /data/caddy /config/caddy

ENV SERVER_NAME=":80" \
	SERVER_ROOT="/app/public" \
	HEALTH_PORT="8090" \
	HEALTH_PATH="/healthz" \
	KIRBY_APP_ROOT="/app" \
	KIRBY_VERSION="${KIRBY_VERSION}" \
	COMPOSER_HOME="/tmp/composer" \
	COMPOSER_ALLOW_SUPERUSER="1"

# Kirby's runtime state. `storage` holds accounts, sessions, cache and logs;
# `media` is derived from content but expensive to regenerate.
# https://getkirby.com/docs/guide/configuration/custom-folder-setup
VOLUME ["/app/content", "/app/storage", "/app/public/media"]

EXPOSE 80 443 443/udp 8090

USER kirby

# Shell form on purpose: HEALTH_PORT and HEALTH_PATH are configurable, and the
# exec form would not expand them.
# hadolint ignore=DL3025
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
	CMD curl -fsS "http://127.0.0.1:${HEALTH_PORT}${HEALTH_PATH}" >/dev/null || exit 1

ENTRYPOINT ["kirby-entrypoint"]
CMD ["--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]

# Last on purpose: VCS_REF and BUILD_DATE change on every single build, so the
# only layer they can invalidate is this metadata-only one.
ARG VCS_REF=unknown
ARG BUILD_DATE=unknown

LABEL org.opencontainers.image.title="Kirby CMS" \
	org.opencontainers.image.description="Kirby CMS ${KIRBY_VERSION} served by FrankenPHP ${FRANKENPHP_VERSION} on PHP ${PHP_VERSION}" \
	org.opencontainers.image.version="${KIRBY_VERSION}" \
	org.opencontainers.image.revision="${VCS_REF}" \
	org.opencontainers.image.created="${BUILD_DATE}" \
	org.opencontainers.image.vendor="Mittwald CM Service GmbH & Co. KG" \
	org.opencontainers.image.licenses="MIT" \
	org.opencontainers.image.source="https://github.com/mittwald/docker-kirby" \
	org.opencontainers.image.documentation="https://github.com/mittwald/docker-kirby#readme" \
	de.mittwald.kirby.php-version="${PHP_VERSION}" \
	de.mittwald.kirby.frankenphp-version="${FRANKENPHP_VERSION}"
