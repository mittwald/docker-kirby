#!/usr/bin/env python3
"""Keep versions.json in sync with upstream without a human watching.

Two things can go stale in versions.json, and neither is visible from a build
log until something breaks:

  1. Kirby publishes a new major. Nothing builds it until a branch is added.
  2. Kirby (or FrankenPHP) starts supporting a newer PHP minor than the one a
     branch is pinned to.

Patch and minor releases of Kirby are *not* handled here — the build resolves
those from Packagist on every run, so they need no commit.

The two findings are handled differently, on purpose. A PHP bump is applied and
becomes a pull request the build matrix can verify end to end. A new major is
not applied: it needs a plainkit release that may not exist yet, a decision
about the `latest` tag, a decision about the branch it replaces and a README
table that nothing generates. Instead this writes a briefing that
.github/workflows/update-versions.yml hands to the add-kirby-branch skill,
running under opencode, which does the work and opens the pull request.

Usage:
    scripts/check-updates.py                     # report only
    scripts/check-updates.py --apply             # apply the PHP bumps
    scripts/check-updates.py --apply --briefing-to brief.md --github-output
    scripts/check-updates.py --reformat          # canonicalise versions.json
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
PACKAGIST_URL = "https://repo.packagist.org/p2/getkirby/cms.json"
PLAINKIT_URL = "https://repo.packagist.org/p2/getkirby/plainkit.json"
DOCKERHUB_TAG_URL = "https://hub.docker.com/v2/repositories/dunglas/frankenphp/tags/{tag}"
STABLE_RE = re.compile(r"^v?(\d+)\.(\d+)\.(\d+)$")
PHP_MINOR_RE = re.compile(r"(\d+)\.(\d+)")

# Kirby's own floor. Anything below this is not worth considering as a target.
MINIMUM_PHP = (8, 2)


def get_json(url: str):
    request = urllib.request.Request(url, headers={"User-Agent": "mittwald-docker-kirby"})
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def fetch_releases() -> dict[tuple[int, int, int], dict]:
    """Stable getkirby/cms releases mapped to their Packagist metadata."""
    payload = get_json(PACKAGIST_URL)
    releases = {}
    for package in payload["packages"]["getkirby/cms"]:
        match = STABLE_RE.match(package["version"])
        if match:
            releases[tuple(int(part) for part in match.groups())] = package
    return releases


def supported_php_minors(release: dict) -> list[tuple[int, int]]:
    """PHP minors a Kirby release accepts, newest first.

    Kirby declares this as `~8.2.0 || ~8.3.0 || ...`, so collecting every
    major.minor pair from the constraint is enough; anything below Kirby's
    documented floor is dropped as a safety net against odd constraint syntax.
    """
    constraint = release.get("require", {}).get("php", "")
    minors = {
        (int(major), int(minor))
        for major, minor in PHP_MINOR_RE.findall(constraint)
        if (int(major), int(minor)) >= MINIMUM_PHP
    }
    return sorted(minors, reverse=True)


def frankenphp_tag_exists(tag: str, cache: dict[str, bool] = {}) -> bool:
    """Whether dunglas/frankenphp publishes this tag.

    Probing single tags beats listing them: the repository carries more than
    ten thousand.
    """
    if tag not in cache:
        try:
            get_json(DOCKERHUB_TAG_URL.format(tag=tag))
            cache[tag] = True
        except urllib.error.HTTPError as error:
            if error.code != 404:
                raise
            cache[tag] = False
    return cache[tag]


def best_php(release: dict, frankenphp: str, os_variant: str) -> str | None:
    """Highest PHP minor supported by both Kirby and the FrankenPHP images."""
    for major, minor in supported_php_minors(release):
        candidate = f"{major}.{minor}"
        if frankenphp_tag_exists(f"{frankenphp}-php{candidate}-{os_variant}"):
            return candidate
    return None


def plainkit_majors(cache: list = []) -> set[int]:
    """Major versions Kirby's plainkit has a stable release for.

    The kit supplies the site skeleton but is released on its own schedule —
    its 4.x line stopped at 4.8.0 while Kirby 4 went on — so a new CMS major
    does not imply a matching kit.
    """
    if not cache:
        payload = get_json(PLAINKIT_URL)
        cache.append(
            {
                int(match.group(1))
                for package in payload["packages"]["getkirby/plainkit"]
                if (match := STABLE_RE.match(package["version"]))
            }
        )
    return cache[0]


def newest_for_major(releases: dict, major: int) -> tuple[int, int, int] | None:
    matching = sorted((v for v in releases if v[0] == major), reverse=True)
    return matching[0] if matching else None


def check(manifest: dict, releases: dict) -> dict:
    """Split what upstream changed into what this script may finish and what it
    may not.

    A PHP bump is a version string: mechanical, verifiable by CI, safe to
    apply. A new Kirby major is not — it needs a plainkit that may not exist
    yet, a decision about the `latest` tag, a decision about the branch it
    replaces, and a README table that nothing generates. Applying three lines
    of JSON for it would produce a pull request that looks finished and is not,
    so those are handed to the add-kirby-branch skill instead.
    """
    defaults = manifest.get("defaults", {})
    findings: list[str] = []
    handoffs: list[dict] = []
    changed = False

    configured_majors = set()
    for branch in manifest["branches"]:
        match = re.match(r"^[\^~](\d+)\.", branch["constraint"])
        if match:
            configured_majors.add(int(match.group(1)))

    # --- 1. PHP bumps for existing branches ---------------------------------
    for branch in manifest["branches"]:
        if branch.get("phpPolicy", "latest") == "pinned":
            continue

        version = newest_for_major(releases, int(branch["name"].split(".")[0]))
        if version is None:
            continue

        frankenphp = branch.get("frankenphp", defaults.get("frankenphp", "1"))
        os_variant = branch.get("os", defaults.get("os", "trixie"))
        target = best_php(releases[version], frankenphp, os_variant)

        if target and target != branch["php"]:
            direction = "up" if tuple(map(int, target.split("."))) > tuple(map(int, branch["php"].split("."))) else "down"
            findings.append(
                f"- Branch `{branch['name']}`: PHP `{branch['php']}` -> `{target}` "
                f"({direction}; Kirby {'.'.join(map(str, version))} supports it and "
                f"`dunglas/frankenphp:{frankenphp}-php{target}-{os_variant}` exists)."
            )
            branch["php"] = target
            changed = True

    # --- 2. Kirby majors that nothing builds --------------------------------
    # Detected here, decided by a human or the add-kirby-branch skill. The
    # facts that skill would otherwise have to go and gather are collected now,
    # while the Packagist responses are already in hand.
    for major in sorted({version[0] for version in releases}, reverse=True):
        if major in configured_majors:
            continue
        if major < max(configured_majors, default=0):
            # Older majors were dropped deliberately; do not resurrect them.
            continue

        version = newest_for_major(releases, major)
        release = ".".join(map(str, version))
        frankenphp = defaults.get("frankenphp", "1")
        os_variant = defaults.get("os", "trixie")
        php = best_php(releases[version], frankenphp, os_variant)
        kit_available = major in plainkit_majors()
        current_latest = next(
            (b["name"] for b in manifest["branches"] if b.get("latest")), None
        )

        findings.append(
            f"- **New major**: Kirby {major} ({release}) is not built yet. "
            f"Handed to the `add-kirby-branch` skill."
        )
        handoffs.append(
            {
                "major": major,
                "title": f"Add Kirby {major}",
                "body": new_major_briefing(
                    major, release, php, kit_available, current_latest, frankenphp, os_variant
                ),
            }
        )

    return {"findings": findings, "handoffs": handoffs, "changed": changed}


def new_major_briefing(
    major: int,
    release: str,
    php: str | None,
    kit_available: bool,
    current_latest: str | None,
    frankenphp: str,
    os_variant: str,
) -> str:
    """Context handed to the agent: the facts it would otherwise go and gather,
    then the decisions that are actually open."""
    php_line = (
        f"- Highest PHP supported by both Kirby {major} and FrankenPHP: **{php}** "
        f"(`dunglas/frankenphp:{frankenphp}-php{php}-{os_variant}`)."
        if php
        else f"- **No FrankenPHP image exists for any PHP version Kirby {major} supports.** "
        f"This has to be resolved before the branch can be added at all."
    )
    kit_line = (
        f"- `getkirby/plainkit` has a matching `^{major}.0`, so the default kit constraint works."
        if kit_available
        else f"- **`getkirby/plainkit` has no `^{major}.0` release.** The build installs the site "
        f"skeleton with `composer create-project getkirby/plainkit`, so the branch needs an "
        f'explicit `"plainkit": "^{major - 1}.0"` until the matching kit ships, or '
        f"`composer create-project` fails with "
        f"`Could not find package getkirby/plainkit with version ^{major}.0`."
    )

    return f"""\
## Add Kirby {major}

Kirby **{major}.x** is on Packagist (newest stable: `{release}`) and no branch in
`versions.json` builds it.

### Already checked for you, no need to re-derive

{php_line}
{kit_line}
- The `latest` tag currently points at branch `{current_latest or "(none)"}`.

### The task

Add a branch for Kirby {major} to `versions.json` on PHP `{php}`, following the
`add-kirby-branch` skill, and update the supported-tags table in `README.md` to
match — nothing generates that table.

### Deliberately out of scope for this run

- **Do not move the `latest` tag.** It stays on branch
  `{current_latest or "(none)"}`. Moving it changes what every unpinned
  `docker pull mittwald/kirby` resolves to, and the skill's rule is to move it
  only once the new major has had at least one patch release, in a pull request
  of its own. Note in the PR body that this is still open.
- **Do not retire the oldest branch.** Dropping an end-of-life branch is a
  separate, deliberate change. Mention it in the PR body if Kirby has declared
  one end of life, but leave `versions.json` alone.
- **Do not change the PHP version of any existing branch.** A separate
  automated pull request handles those.
"""


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, default=REPO_ROOT / "versions.json")
    parser.add_argument(
        "--apply",
        action="store_true",
        help="write the changes this script may finish on its own (PHP bumps)",
    )
    parser.add_argument(
        "--briefing-to",
        type=Path,
        help="write the new-major agent briefing to this file as markdown",
    )
    parser.add_argument(
        "--reformat",
        action="store_true",
        help="rewrite versions.json in the canonical formatting and exit",
    )
    parser.add_argument("--github-output", action="store_true")
    args = parser.parse_args()

    manifest = json.loads(args.manifest.read_text())

    # Keeping the file in exactly the formatting this script emits means an
    # automated bump shows only the line it changed. The lint job runs this and
    # then checks the working tree is clean.
    if args.reformat:
        args.manifest.write_text(json.dumps(manifest, indent=2) + "\n")
        return 0

    result = check(manifest, fetch_releases())
    findings, handoffs, changed = result["findings"], result["handoffs"], result["changed"]

    if findings:
        report = "The following upstream changes are not reflected in `versions.json` yet:\n\n"
        report += "\n".join(findings)
    else:
        report = "`versions.json` is up to date with Kirby and FrankenPHP."

    print(report)

    if handoffs:
        print("\nHanded to the add-kirby-branch skill:", file=sys.stderr)
        for handoff in handoffs:
            print(f"  - {handoff['title']}", file=sys.stderr)

    if changed and args.apply:
        args.manifest.write_text(json.dumps(manifest, indent=2) + "\n")
        print(f"\nwrote {args.manifest}", file=sys.stderr)

    if args.briefing_to:
        args.briefing_to.write_text("\n".join(h["body"] for h in handoffs))

    if args.github_output and (output := os.environ.get("GITHUB_OUTPUT")):
        with open(output, "a", encoding="utf-8") as handle:
            handle.write(f"changed={'true' if changed else 'false'}\n")
            handle.write(f"handoffs={'true' if handoffs else 'false'}\n")
            handle.write("report<<EOF\n" + report + "\nEOF\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())
