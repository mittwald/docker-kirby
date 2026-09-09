# mittwald/kirby

Container images for [Kirby CMS](https://getkirby.com), served by [FrankenPHP](https://frankenphp.dev). One process, no PHP-FPM, no separate web server, and no configuration needed to get a running site.

The images are rebuilt every day, so a `docker pull` picks up Kirby patch releases and distribution security updates without anything in this repository changing.

## Supported tags

| Tag | Kirby | PHP | Notes |
| --- | --- | --- | --- |
| `5`, `5.x`, `5.x.y`, `latest` | Kirby 5 | 8.4 | Current release |
| `4`, `4.x`, `4.x.y` | Kirby 4 | 8.4 | Previous release |

Every branch also publishes the exact patch version, so `mittwald/kirby:5.5.3` pins one Kirby release while `mittwald/kirby:5` follows the branch. Images are built for `linux/amd64` and `linux/arm64`.

## Quick start

```sh
docker run --rm -p 8080:80 mittwald/kirby:5
```

That serves a minimal Kirby site on <http://localhost:8080>. It is meant as a starting point, not as something to deploy — for a real site you either mount your own content or build an image on top.

With persistent data:

```sh
docker run -d --name kirby -p 8080:80 \
  -v kirby-content:/app/content \
  -v kirby-storage:/app/storage \
  -v kirby-media:/app/public/media \
  mittwald/kirby:5
```

## What is inside

```
/app
├── composer.json      from plainkit, with the CMS pinned at build time
├── kirby/             the CMS, installed by Composer
├── vendor/            Composer autoloader and plugin dependencies
├── content/           your pages                        (volume)
├── site/              templates, snippets, blueprints, plugins, config
├── storage/           accounts, cache, sessions, logs    (volume)
└── public/            the only directory the web server serves
    ├── index.php      front controller
    └── media/         generated thumbnails               (volume)
```

The site skeleton — templates, snippets, blueprints and the starting content — is Kirby's own [plainkit](https://github.com/getkirby/plainkit), installed with `composer create-project` during the build. Nothing about it is maintained in this repository, so it tracks whatever upstream ships. Exactly two files are this image's own: `public/index.php`, which declares the roots, and `site/config/config.php`, which bridges the `KIRBY_*` variables into Kirby options.

plainkit is not released in lockstep with the CMS — its 4.x line stopped at 4.8.0 while Kirby 4 kept going — so the kit is resolved by major version and the exact CMS release is pinned right afterwards. The build fails if the installed version does not match the pin.

The image uses Kirby's [public/private folder setup](https://getkirby.com/docs/guide/configuration/custom-folder-setup): only `/app/public` is reachable over HTTP. `content`, `site`, `kirby`, `storage` and `composer.json` are not below the document root and therefore cannot be served at all, which is a stronger guarantee than blocking their paths in the web server. plainkit ships the flat layout instead, so the build moves `media` under `public/`, drops plainkit's `index.php` and `.htaccess`, and collects the writable directories in `storage/`.

The container runs as the unprivileged user `kirby` (uid/gid `1000:1000`).

## Persistent data

Three paths hold state and are declared as volumes:

| Path | Contents | Losing it means |
| --- | --- | --- |
| `/app/content` | All pages, files and site content | Losing the site |
| `/app/storage` | Panel accounts, sessions, cache, logs | Users logged out, accounts gone |
| `/app/public/media` | Thumbnails generated from content | Nothing permanent; regenerated on demand, at a cost |

`site/plugins` is deliberately **not** a volume. Plugins are code: they belong in your image, either committed under `site/plugins` or installed with `composer require`. A plugin directory that lives in a volume never gets updated when the image is rebuilt, which is the kind of drift that only shows up during an incident.

Because the paths are declared with `VOLUME`, a plain `docker run` creates anonymous volumes for them. Name them, as in the quick start above, or `docker run --rm` and let them be discarded.

### Relocating the roots

Every root can be moved, which is what you want when a platform gives you exactly one mount. Point content and media inside the storage volume:

```sh
docker run -d -p 8080:80 \
  -v kirby-data:/app/storage \
  -e KIRBY_ROOT_CONTENT=/app/storage/content \
  -e KIRBY_ROOT_MEDIA=/app/storage/media \
  mittwald/kirby:5
```

Two things to know before you do this. The relocated content root starts out **empty** — the seeded content stays behind at `/app/content` and is no longer read, so the site is blank until you add pages. And the mount point has to be writable by uid `1000`: `/app/storage` already is, which is why the example nests below it. Mounting a volume somewhere that does not exist in the image, `/data` for instance, creates it owned by root and the container will refuse to start with an explicit error. Either nest below `/app`, or start the container as root once and let the entrypoint fix the ownership.

| Variable | Default |
| --- | --- |
| `KIRBY_ROOT_BASE` | `/app` |
| `KIRBY_ROOT_CONTENT` | `/app/content` |
| `KIRBY_ROOT_SITE` | `/app/site` |
| `KIRBY_ROOT_MEDIA` | `/app/public/media` |
| `KIRBY_ROOT_STORAGE` | `/app/storage` |
| `KIRBY_ROOT_ACCOUNTS` | `<storage>/accounts` |
| `KIRBY_ROOT_CACHE` | `<storage>/cache` |
| `KIRBY_ROOT_SESSIONS` | `<storage>/sessions` |
| `KIRBY_ROOT_LOGS` | `<storage>/logs` |

## Configuration

Everything below has a working default. Setting nothing at all gives you a production-shaped configuration: OPcache on with timestamp validation off, errors logged but not displayed, and no automatic HTTPS.

### Server

| Variable | Default | Description |
| --- | --- | --- |
| `SERVER_NAME` | `:80` | Caddy site address. Set it to a hostname (`example.com`) and Caddy obtains a Let's Encrypt certificate automatically. Use `:8080` for an unprivileged port. |
| `SERVER_ROOT` | `/app/public` | Document root. |
| `TRUSTED_PROXIES` | `private_ranges` | Which proxies may set `X-Forwarded-*`. Required for correct client IPs and HTTPS detection behind an ingress. |
| `HEALTH_PORT` | `8090` | Port of the plain-HTTP health listener. |
| `HEALTH_PATH` | `/healthz` | Path of the health endpoint. |
| `CADDY_ADMIN` | `off` | Caddy admin API address. |
| `CADDY_GLOBAL_OPTIONS` | — | Extra directives in Caddy's global block, e.g. `auto_https off`. |
| `CADDY_SERVER_EXTRA_DIRECTIVES` | — | Extra directives inside the site block. |
| `CADDY_EXTRA_CONFIG` | — | Extra top-level Caddyfile content. |
| `FRANKENPHP_CONFIG` | — | Extra directives in the `frankenphp` block. |

### PHP

| Variable | Default |
| --- | --- |
| `PHP_MEMORY_LIMIT` | `256M` |
| `PHP_UPLOAD_MAX_FILESIZE` | `128M` |
| `PHP_POST_MAX_SIZE` | `128M` |
| `PHP_MAX_EXECUTION_TIME` | `60` |
| `PHP_MAX_INPUT_VARS` | `3000` |
| `PHP_MAX_FILE_UPLOADS` | `50` |
| `PHP_TIMEZONE` | `UTC` |
| `PHP_DISPLAY_ERRORS` | `Off` |
| `PHP_ERROR_REPORTING` | `E_ALL & ~E_DEPRECATED` |
| `PHP_OPCACHE_MEMORY_CONSUMPTION` | `192` |
| `PHP_OPCACHE_VALIDATE_TIMESTAMPS` | `0` |

Set `PHP_OPCACHE_VALIDATE_TIMESTAMPS=1` when you bind-mount source code during development, otherwise your edits are invisible until the container restarts.

Installed extensions beyond the PHP defaults: `gd`, `intl`, `exif`, `zip`, `apcu`, `opcache`. That covers everything Kirby requires and recommends.

### Kirby options

These map onto [Kirby config options](https://getkirby.com/docs/reference/system/options). Only the variables you actually set are applied; the rest keep Kirby's own defaults.

| Variable | Option |
| --- | --- |
| `KIRBY_URL` | `url` |
| `KIRBY_DEBUG` | `debug` |
| `KIRBY_PANEL` | `panel` — set to `false` to disable the panel entirely |
| `KIRBY_PANEL_INSTALL` | `panel.install` |
| `KIRBY_PANEL_SLUG` | `panel.slug` |
| `KIRBY_LANGUAGES` | `languages` |
| `KIRBY_SMARTYPANTS` | `smartypants` |
| `KIRBY_DATE_HANDLER` | `date.handler` |
| `KIRBY_CONTENT_LOCKING` | `content.locking` |
| `KIRBY_CACHE_PAGES` | `cache.pages.active` |
| `KIRBY_CACHE_PAGES_TYPE` | `cache.pages.type` |
| `KIRBY_THUMBS_DRIVER` | `thumbs.driver` |
| `KIRBY_THUMBS_QUALITY` | `thumbs.quality` |
| `KIRBY_API_BASIC_AUTH` | `api.basicAuth` |
| `KIRBY_API_ALLOW_INSECURE` | `api.allowInsecure` |
| `KIRBY_AUTH_METHODS` | `auth.methods` (comma separated) |
| `KIRBY_AUTH_TRIALS` | `auth.trials` |
| `KIRBY_EMAIL_TRANSPORT` | `email.transport.type` |
| `KIRBY_EMAIL_HOST`, `KIRBY_EMAIL_PORT`, `KIRBY_EMAIL_USER`, `KIRBY_EMAIL_SECURITY` | `email.transport.*` |
| `KIRBY_EMAIL_PASSWORD`, `KIRBY_EMAIL_PASSWORD_FILE` | `email.transport.password` |
| `KIRBY_OPTIONS_JSON` | Any option, as a JSON object. Merged last, so it wins. |

`KIRBY_OPTIONS_JSON` is the escape hatch for anything without its own variable:

```sh
-e KIRBY_OPTIONS_JSON='{"thumbs.presets.default":{"width":1200},"routes":[]}'
```

### License

Paste the contents of the `.license` file Kirby sent you:

```sh
-e KIRBY_LICENSE='{"license":"K5-...","order":"...","email":"...","date":"...","domain":"...","signature":"..."}'
```

Or mount it as a secret and point at the file, which keeps it out of `docker inspect`:

```sh
-v /run/secrets/kirby-license:/run/secrets/kirby-license:ro \
-e KIRBY_LICENSE_FILE=/run/secrets/kirby-license
```

The entrypoint writes it to `site/config/.license` with mode `600` on every start, so the license does not have to be persisted in a volume.

## Building your own site on top

This is the intended way to use the image. Your content and code live in your repository and become an immutable image; only runtime state lives in volumes.

```dockerfile
FROM mittwald/kirby:5

# Plugins are code, so they belong in the image.
RUN composer require --no-interaction --no-progress \
      getkirby/staticache

COPY --chown=kirby:kirby site/ /app/site/
COPY --chown=kirby:kirby content/ /app/content/
COPY --chown=kirby:kirby assets/ /app/public/assets/
```

Replacing `site/config/config.php` is fine — keep the `require` so the `KIRBY_*` variables above keep working:

```php
<?php

return array_replace_recursive(
    require '/usr/local/share/kirby/env-options.php',
    [
        'smartypants' => true,
        'thumbs' => ['quality' => 85],
    ]
);
```

A compose file for local development, with sources bind-mounted and OPcache revalidating:

```yaml
services:
  kirby:
    image: mittwald/kirby:5
    ports:
      - "8080:80"
    environment:
      KIRBY_DEBUG: "true"
      KIRBY_PANEL_INSTALL: "true"
      PHP_OPCACHE_VALIDATE_TIMESTAMPS: "1"
    volumes:
      - ./site:/app/site
      - ./content:/app/content
      - kirby-storage:/app/storage
      - kirby-media:/app/public/media

volumes:
  kirby-storage:
  kirby-media:
```

## Notes for production

**Creating the first panel user.** Kirby refuses to run its installer on a non-local host unless you allow it. Start once with `KIRBY_PANEL_INSTALL=true`, create the account, then remove the variable — leaving it on means anyone reaching `/panel` can create an admin user.

**Behind a TLS-terminating proxy.** Keep `SERVER_NAME` on a bare port, set `TRUSTED_PROXIES` if your proxy is outside the private ranges, and set `KIRBY_URL` to the public URL so Kirby generates correct links.

**Terminating TLS in the container.** Set `SERVER_NAME` to your hostname and publish ports 80 and 443. Caddy handles certificates on its own, but needs `/data` to be a volume — otherwise it re-requests a certificate on every restart and will hit Let's Encrypt rate limits.

**Health checks.** Port `8090` serves `/healthz` over plain HTTP, independent of virtual hosts, TLS and PHP. Use it for liveness and readiness probes.

**Running as root.** Not the default, and not necessary. If you do start the container as root — typically to fix ownership of a host bind mount — the entrypoint takes ownership of the writable roots and drops back to `kirby` before starting the server. `KIRBY_RUN_AS_ROOT=true` skips that, which you should not need.

**Worker mode.** FrankenPHP can keep the application in memory between requests. Kirby is not written for that and will leak state across requests, so it is off. If you want to experiment: `FRANKENPHP_CONFIG="worker /app/public/index.php"`.

## Repository layout

```
versions.json           every image that gets built, as data
image/                  build context (Dockerfile, Caddyfile, php.ini, entrypoint)
  app/                  the only two application files this repo owns
scripts/
  resolve-versions.py   versions.json + Packagist -> build matrix
  check-updates.py      finds new Kirby majors and PHP bumps
  smoke-test.sh         starts a built image and asserts it serves Kirby
.github/workflows/      ci, publish, lint, update-versions
.agents/skills/         maintenance tasks that need judgement, for AI agents
```

## How this repository maintains itself

The design goal was that routine upkeep needs no commits.

- **Kirby patch and minor releases** are resolved from Packagist on every build. The daily publish picks up a new `5.5.4` the day it appears, with no change here.
- **The site skeleton** is installed from Kirby's plainkit during the build, so templates, blueprints and starting content are never something this repository has to keep in step with upstream.
- **Security updates** in PHP, FrankenPHP and Debian arrive through the same daily rebuild, which runs with the layer cache disabled so updated packages are actually installed.
- **New Kirby majors and PHP bumps** are detected weekly by `scripts/check-updates.py`, which opens a pull request. CI builds and smoke tests it; a human decides whether `latest` moves.
- **GitHub Actions versions** are updated by Dependabot.
- **Everything else** — a new required extension, a renamed Kirby root, a changed recommendation in the docs — is covered by the skills in `.agents/skills/`, written for an agent to run periodically.

Every build runs `scripts/smoke-test.sh` against the loaded image before anything is pushed. It starts real containers and checks that Kirby renders, that environment variables reach the CMS, that `content`, `site` and `kirby` are not web-reachable, that volumes survive a container replacement, and that the root-to-`kirby` privilege drop works.

### Repository setup

Three things have to be configured once, or the automation silently does nothing:

- Repository secrets `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` (an access token with write scope). Without them the publish workflow fails at the login step; CI builds are unaffected because they never push.
- **Settings → Actions → General → Allow GitHub Actions to create and approve pull requests**, so `update-versions.yml` can open its PR.
- Scheduled workflows are disabled automatically on repositories with no activity for 60 days. The daily rebuild is the thing that delivers security updates, so if the repository goes quiet, check that the schedule still fires — the `release-health-check` skill looks for exactly this.

## Adding a Kirby version

Add an entry to `versions.json`:

```jsonc
{
  "name": "6",
  "constraint": "^6.0",
  "php": "8.5"
}
```

Everything else follows: the build matrix, the tag ladder (`6`, `6.x`, `6.x.y`), the smoke test and the push. See `.agents/skills/add-kirby-branch/SKILL.md` for the decisions that are not automated — moving `latest`, and retiring an end-of-life branch.

Locally:

```sh
python3 scripts/resolve-versions.py
docker buildx build --build-arg KIRBY_VERSION=5.5.3 --build-arg PLAINKIT_CONSTRAINT='^5.0' \
  -t kirby:dev --load ./image
scripts/smoke-test.sh kirby:dev --kirby-version 5.5.3 --php-version 8.4
```

## License

The contents of this repository are MIT licensed. Kirby itself is **not** free software: it is free to try, but a [license](https://getkirby.com/buy) is required to run it in public. The image ships Kirby under its own license terms.

The published images also contain [plainkit](https://github.com/getkirby/plainkit), which carries no license file of its own and whose README points at the same Kirby license agreement. Redistributing it in an image is covered by a separate agreement with Kirby rather than by anything in this repository.
