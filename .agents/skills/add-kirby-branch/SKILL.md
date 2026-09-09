---
name: add-kirby-branch
description: Add a new Kirby major version branch to the images, move the `latest` tag between branches, or retire an end-of-life branch. Use when Kirby has shipped a major that nothing builds yet, when `latest` should point at a newer major, or when a major reaches end of life.
---

# Add, promote or retire a Kirby branch

Every image this repo builds is described by one entry in `versions.json`.
Adding a version is a data change; there is no Dockerfile or workflow to touch.

Bumping the PHP version of an *existing* branch is not this skill's job.
`scripts/check-updates.py --apply` does that on its own, and its result is
verifiable by a build alone.

## Adding a branch

```jsonc
{
  "name": "6",           // becomes the mittwald/kirby:6 tag
  "constraint": "^6.0",  // resolved against Packagist on every build
  "php": "8.5"           // must exist as dunglas/frankenphp:<v>-php<php>-<os>
}
```

Rules the resolver enforces, so getting them wrong fails the build rather than
producing a broken image:

- `constraint` must be `^X.Y` or `~X.Y`. Anything else is rejected.
- At most one branch may carry `"latest": true`.
- The constraint must match at least one stable release on Packagist.

Optional per-branch keys, all falling back to `defaults`: `frankenphp`, `os`,
`platforms`, `phpPolicy` (`"pinned"` freezes the PHP version against the update
automation), and `plainkit`.

`plainkit` is the one to watch when adding a major. The site skeleton comes
from `composer create-project getkirby/plainkit`, and the kit is **not**
released in lockstep with the CMS — the 4.x line stopped at 4.8.0 while Kirby 4
went on to 4.9.x. The default constraint is `^<major>.0`, derived from the
resolved Kirby version, which is right whenever a matching kit exists.

When Kirby ships a major before plainkit does, the build fails with
`Could not find package getkirby/plainkit with version matching ^6.0`. Check
what the kit actually has:

```
curl -s https://repo.packagist.org/p2/getkirby/plainkit.json \
  | python3 -c "import sys,json;print([p['version'] for p in json.load(sys.stdin)['packages']['getkirby/plainkit']][:10])"
```

Then set `"plainkit": "^5.0"` on the new branch to use the previous kit until
the matching one appears, and check the smoke test still passes — an older kit
against a newer CMS is exactly the combination where a renamed template or
blueprint field shows up.

`os` has to stay a Debian variant — `bookworm` or `trixie`. The Dockerfile runs
`apt-get upgrade` to pick up distribution security fixes, so an Alpine base
would fail the build until that instruction is made conditional.

Order the branches newest first. It is the order the build matrix and the
README table appear in.

## Choosing the PHP version

Take the highest PHP minor that both Kirby and FrankenPHP support:

```
python3 scripts/check-updates.py
```

That is the same logic the automation uses. Kirby's requirements page marks one
version as *recommended* while supporting newer ones; when they disagree,
prefer the recommended version for a branch that carries `latest`, and say in
the PR why.

## Moving the `latest` tag

`latest` is what an unpinned `docker pull mittwald/kirby` gets, so moving it
changes the default for everyone who never pinned. Move it only once the new
major has had at least one patch release, and in a PR of its own:

1. Remove `"latest": true` from the old branch.
2. Add it to the new one.
3. Note in the PR description what an unpinned deployment will be upgraded to.

## Retiring a branch

Deleting the entry stops building that branch. Tags already on Docker Hub are
not removed and keep working — this is intentional, and it means retiring a
branch is not a breaking change for anyone who pinned.

Before removing one, check that Kirby actually declared it end of life
(<https://getkirby.com/releases>) and record that in the PR description.

## Verify before opening the PR

```
python3 scripts/resolve-versions.py                     # tags look right?
python3 -m unittest discover -s scripts -p 'test_*.py'
docker buildx build --build-arg KIRBY_VERSION=<version> \
  --build-arg PLAINKIT_CONSTRAINT=<plainkit constraint> \
  -t kirby-new:<branch> --load ./image
scripts/smoke-test.sh kirby-new:<branch> --kirby-version <version> --php-version <php>
```

A new major is the most likely place for the smoke test to fail for a real
reason — the panel URL, the error page, or a root may have moved. If it does,
fix the image rather than the assertion, and only relax an assertion when the
upstream change is deliberate and you can point at the release notes.

Finally update the supported-versions table in `README.md`; nothing generates
it, and run `python3 scripts/check-updates.py --reformat` so `versions.json`
keeps the formatting the lint job checks for.
