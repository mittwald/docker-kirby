#!/usr/bin/env python3
"""Keep versions.json in sync with upstream without a human watching.

Two things can go stale in versions.json, and neither is visible from a build
log until something breaks:

  1. Kirby publishes a new major. Nothing builds it until a branch is added.
  2. Kirby (or FrankenPHP) starts supporting a newer PHP minor than the one a
     branch is pinned to.

Patch and minor releases of Kirby are *not* handled here — the build resolves
those from Packagist on every run, so they need no commit.

Run with --apply in CI and open a pull request when anything changed; the
normal build matrix then smoke tests the proposal before a human merges it.

Usage:
    scripts/check-updates.py                     # report only
    scripts/check-updates.py --apply             # rewrite versions.json
    scripts/check-updates.py --apply --github-output
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
DOCKERHUB_TAG_URL = "https://hub.docker.com/v2/repositories/dunglas/frankenphp/tags/{tag}"
STABLE_RE = re.compile(r"^v?(\d+)\.(\d+)\.(\d+)$")
PHP_MINOR_RE = re.compile(r"(\d+)\.(\d+)")

# Kirby's own floor. Anything below this is not worth considering as a target.
MINIMUM_PHP = (8, 2)


def get_json(url: str):
    request = urllib.request.Request(url, headers={"User-Agent": "mittwald-kirby-docker"})
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


def newest_for_major(releases: dict, major: int) -> tuple[int, int, int] | None:
    matching = sorted((v for v in releases if v[0] == major), reverse=True)
    return matching[0] if matching else None


def check(manifest: dict, releases: dict) -> tuple[list[str], bool]:
    """Returns the report lines and whether the manifest was modified."""
    defaults = manifest.get("defaults", {})
    findings: list[str] = []
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
    for major in sorted({version[0] for version in releases}, reverse=True):
        if major in configured_majors:
            continue
        if major < max(configured_majors, default=0):
            # Older majors were dropped deliberately; do not resurrect them.
            continue

        version = newest_for_major(releases, major)
        frankenphp = defaults.get("frankenphp", "1")
        os_variant = defaults.get("os", "trixie")
        php = best_php(releases[version], frankenphp, os_variant)

        if php is None:
            findings.append(
                f"- Kirby {major} is released ({'.'.join(map(str, version))}) but no "
                f"FrankenPHP image exists for any PHP version it supports. Needs a look."
            )
            continue

        findings.append(
            f"- **New major**: Kirby {major} ({'.'.join(map(str, version))}) is not built yet. "
            f"Added a branch on PHP `{php}`. The `latest` tag was left where it is — "
            f"move it deliberately once you are ready to make Kirby {major} the default."
        )
        manifest["branches"].insert(
            0,
            {"name": str(major), "constraint": f"^{major}.0", "php": php},
        )
        changed = True

    return findings, changed


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, default=REPO_ROOT / "versions.json")
    parser.add_argument("--apply", action="store_true", help="write the proposed changes")
    parser.add_argument("--github-output", action="store_true")
    args = parser.parse_args()

    manifest = json.loads(args.manifest.read_text())
    findings, changed = check(manifest, fetch_releases())

    if findings:
        report = "The following upstream changes are not reflected in `versions.json` yet:\n\n"
        report += "\n".join(findings)
    else:
        report = "`versions.json` is up to date with Kirby and FrankenPHP."

    print(report)

    if changed and args.apply:
        args.manifest.write_text(json.dumps(manifest, indent=2) + "\n")
        print(f"\nwrote {args.manifest}", file=sys.stderr)

    if args.github_output and (output := os.environ.get("GITHUB_OUTPUT")):
        with open(output, "a", encoding="utf-8") as handle:
            handle.write(f"changed={'true' if changed else 'false'}\n")
            handle.write("report<<EOF\n" + report + "\nEOF\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())
