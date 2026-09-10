---
name: kirby-docs-audit
description: Audit the container image against Kirby's current upstream documentation — system requirements, writable roots, folder setup and the Caddy recipe — and fix anything that has drifted. Use this periodically, after a new Kirby major, or whenever a build starts behaving oddly for reasons the smoke test does not explain.
---

# Kirby docs audit

`scripts/check-updates.py` keeps version numbers current. It cannot notice that
Kirby changed *what an installation needs* — a new required PHP extension, a
renamed root, a new directory that has to be writable. That is what this audit
is for, and it is the one maintenance task on this repo that genuinely needs a
reader rather than a script.

## What to compare against

Fetch these four pages and treat them as the source of truth:

| Page | What the image derives from it |
| --- | --- |
| <https://getkirby.com/docs/reference/system/requirements> | PHP versions, required and recommended extensions |
| <https://getkirby.com/docs/guide/configuration/custom-folder-setup> | The roots in `image/app/public/index.php` |
| <https://getkirby.com/docs/cookbook/development-deployment/caddy> | The blocking rules in `image/Caddyfile` |
| <https://getkirby.com/docs/reference/system/options> | The option names in `image/share/env-options.php` |
| <https://github.com/getkirby/plainkit> | The site skeleton, and the layout the build rearranges |

Check the docs for the **oldest** branch in `versions.json` as well, not just
the newest — `v4.getkirby.com` mirrors the Kirby 4 documentation.

## Checks

1. **Extensions.** Every extension the requirements page lists as *required*
   must be in the image. Compare against the real thing rather than the
   Dockerfile, because the base image already provides most of them:

   ```
   docker run --rm mittwald/kirby:5 php -m
   ```

   The Dockerfile only installs what the base image lacks. If an extension
   moved from *recommended* to *required*, add it to the `install-php-extensions`
   call and say so in the PR — it grows the image, which is a decision worth
   naming.

2. **Writable roots.** Every directory the docs say must be writable has to
   appear in all four of these places, or a deployment will lose data on
   restart without failing:
   - the `roots` array in `image/app/public/index.php`
   - `writable_roots()` in `image/entrypoint.sh`
   - the builder's `mkdir -p` list and the `VOLUME` instruction in
     `image/Dockerfile`
   - the volume table in `README.md`

   A root that is writable but *not* under `VOLUME` is the failure mode to
   look for. Add a smoke test assertion for any root you add.

3. **Caddy rules.** The image serves `/app/public` and keeps `content`,
   `site`, `kirby` and `storage` outside the document root, so it deliberately
   does *not* carry the recipe's `path /content/* /site/* /kirby/*` block —
   those paths would otherwise shadow legitimate page URLs. If the recipe grows
   a rule that is *not* about hiding those directories, it probably belongs in
   `image/Caddyfile`.

4. **The plainkit layout.** The site skeleton is installed with
   `composer create-project getkirby/plainkit`, and the builder then moves a
   few things into the public/private layout: `media` under `public/`, the
   writable directories into `storage/`, and plainkit's own `index.php` and
   `.htaccess` deleted. That rearrangement encodes assumptions about what
   plainkit ships.

   Compare the current kit against them:

   ```
   composer create-project getkirby/plainkit /tmp/kit "^5.0" --no-interaction
   ls -a /tmp/kit
   ```

   A **new top-level directory** is the case to think about. Anything private
   needs no action — it stays under `/app` and is unreachable over HTTP, which
   is the safe default. Anything that has to be *served*, an `assets/`
   directory for instance, has to be moved under `public/` in the builder or it
   will silently 404. A directory that has to be *writable* also needs adding
   to the roots, the entrypoint and the volumes, per check 2.

5. **Option names.** Kirby options are case sensitive and some are camelCase
   (`api.allowImpersonation`). Verify that every key in the `$map` array in
   `image/share/env-options.php` still exists in the options reference, and
   that none was renamed. A silently ignored option is worse than a missing
   one.

## Verifying a change

Never hand back an untested edit. For each branch in `versions.json`:

```
scripts/lint.sh
docker buildx build \
  --build-arg KIRBY_VERSION=<resolved version> \
  --build-arg PLAINKIT_CONSTRAINT=<plainkit constraint> \
  -t kirby-audit:<branch> --load ./image
scripts/smoke-test.sh kirby-audit:<branch> --kirby-version <resolved version> --php-version <php>
```

`python3 scripts/resolve-versions.py` prints the resolved Kirby version and the
plainkit constraint for each branch.

## Output

Open a pull request for the drift you confirmed, citing the documentation URL
that motivated each change in the body. Finding nothing is the expected result
most of the time: say so, open no pull request, and stop.

If a finding needs a judgement call the docs cannot settle — dropping an
end-of-life branch, moving the `latest` tag — do not pick one. Leave the code
alone and describe the options in the body of whatever pull request you are
already opening, or in your final message if there is none.
