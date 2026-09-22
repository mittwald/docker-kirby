#!/usr/bin/env python3
"""Resolve versions.json into a concrete GitHub Actions build matrix.

For every branch in versions.json this asks Packagist which stable release of
`getkirby/cms` currently satisfies the branch constraint, and derives the full
tag list for that build. Patch releases therefore never need a commit: the
scheduled rebuild picks them up on its own. Only a *new major* requires editing
versions.json.

Usage:
    scripts/resolve-versions.py                 # human readable summary
    scripts/resolve-versions.py --format json   # the raw matrix
    scripts/resolve-versions.py --github-output # write matrix= to $GITHUB_OUTPUT
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

PACKAGIST_URL = "https://repo.packagist.org/p2/getkirby/cms.json"
REPO_ROOT = Path(__file__).resolve().parent.parent
STABLE_RE = re.compile(r"^v?(\d+)\.(\d+)\.(\d+)$")

# Which GitHub-hosted runner builds a platform natively. A platform missing
# here is an error, not a silent fallback to emulation on amd64.
RUNNERS = {
    "linux/amd64": "ubuntu-latest",
    "linux/arm64": "ubuntu-24.04-arm",
}


def fetch_stable_versions(url: str = PACKAGIST_URL) -> list[tuple[int, int, int]]:
    """Return every stable getkirby/cms release, newest first."""
    request = urllib.request.Request(url, headers={"User-Agent": "mittwald-docker-kirby"})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            payload = json.load(response)
    except (urllib.error.URLError, TimeoutError) as error:
        raise SystemExit(f"could not reach Packagist: {error}") from error

    versions = []
    for package in payload["packages"]["getkirby/cms"]:
        match = STABLE_RE.match(package["version"])
        if match:
            versions.append(tuple(int(part) for part in match.groups()))
    return sorted(versions, reverse=True)


def satisfies(version: tuple[int, int, int], constraint: str) -> bool:
    """Support the Composer constraints we actually use in versions.json.

    `^X.Y` (same major, >= X.Y) and `~X.Y` (same major.minor, >= X.Y.0).
    Anything else is rejected loudly rather than silently mis-resolved.
    """
    match = re.match(r"^([\^~])(\d+)\.(\d+)(?:\.(\d+))?$", constraint)
    if not match:
        raise SystemExit(f"unsupported constraint {constraint!r}: use ^X.Y or ~X.Y")

    operator, major, minor, patch = match.group(1), *(int(p or 0) for p in match.groups()[1:])
    floor = (major, minor, patch)
    if version < floor:
        return False
    if operator == "^":
        return version[0] == major
    return version[:2] == (major, minor)


def tags_for(image: str, branch: dict, version: tuple[int, int, int]) -> list[str]:
    """Full tag ladder: exact patch, minor, branch, and optionally `latest`."""
    major, minor, patch = version
    names = [f"{major}.{minor}.{patch}", f"{major}.{minor}", branch["name"]]
    if branch.get("latest"):
        names.append("latest")
    # dict.fromkeys keeps order while removing the duplicate a branch name like
    # "5.5" would otherwise produce.
    return [f"{image}:{name}" for name in dict.fromkeys(names)]


def build_matrix(manifest: dict, available: list[tuple[int, int, int]]) -> list[dict]:
    image = manifest["image"]
    defaults = manifest.get("defaults", {})
    entries = []

    for branch in manifest["branches"]:
        constraint = branch["constraint"]
        matching = [v for v in available if satisfies(v, constraint)]
        if not matching:
            raise SystemExit(f"no stable getkirby/cms release satisfies {constraint!r}")
        version = matching[0]
        kirby_version = ".".join(str(part) for part in version)

        frankenphp = branch.get("frankenphp", defaults.get("frankenphp", "1"))
        php = branch["php"]
        os_variant = branch.get("os", defaults.get("os", "trixie"))
        platforms = branch.get("platforms", defaults.get("platforms", ["linux/amd64"]))

        # The site skeleton comes from Kirby's plainkit, which is released on
        # its own schedule — the 4.x line stopped at 4.8.0 while Kirby 4 kept
        # going. Resolving it by major and pinning the CMS separately is what
        # keeps the two from having to move together.
        plainkit = branch.get("plainkit", f"^{version[0]}.0")

        entries.append(
            {
                "image": image,
                "branch": branch["name"],
                "kirby_version": kirby_version,
                "plainkit": plainkit,
                "php": php,
                "frankenphp": frankenphp,
                "os": os_variant,
                "base_image": f"dunglas/frankenphp:{frankenphp}-php{php}-{os_variant}",
                "platforms": ",".join(platforms),
                "tags": tags_for(image, branch, version),
            }
        )

    if sum(1 for branch in manifest["branches"] if branch.get("latest")) > 1:
        raise SystemExit("at most one branch may be marked as latest")

    return entries


def split_platforms(matrix: list[dict]) -> list[dict]:
    """Expand a branch matrix into one entry per branch and platform.

    Each entry carries the single `platform` it builds and the `runner` that
    builds it natively. `platforms` is dropped: an entry still carrying the
    full list would build every architecture on every runner.
    """
    entries = []
    for entry in matrix:
        for platform in entry["platforms"].split(","):
            if platform not in RUNNERS:
                raise SystemExit(
                    f"no native runner known for {platform!r}: add one to RUNNERS "
                    "in scripts/resolve-versions.py, or the build would emulate it"
                )
            split = {key: value for key, value in entry.items() if key != "platforms"}
            # `linux/arm64` is not a legal artifact name, and the digest
            # artifacts are named after it.
            split["platform"] = platform
            split["arch"] = platform.split("/", 1)[1]
            split["runner"] = RUNNERS[platform]
            entries.append(split)
    return entries


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, default=REPO_ROOT / "versions.json")
    parser.add_argument("--format", choices=("text", "json"), default="text")
    parser.add_argument(
        "--split-platforms",
        action="store_true",
        help="emit one entry per branch and platform, each with its native runner",
    )
    parser.add_argument(
        "--github-output",
        action="store_true",
        help="append matrix=<json> and build-matrix=<json> to $GITHUB_OUTPUT",
    )
    args = parser.parse_args()

    manifest = json.loads(args.manifest.read_text())
    matrix = build_matrix(manifest, fetch_stable_versions())

    if args.format == "json":
        emit = split_platforms(matrix) if args.split_platforms else matrix
        print(json.dumps({"include": emit}, indent=2))
    else:
        for entry in matrix:
            print(
                f"branch {entry['branch']}: kirby {entry['kirby_version']} on php {entry['php']}"
                f", plainkit {entry['plainkit']}"
            )
            for tag in entry["tags"]:
                print(f"  -> {tag}")
            for split in split_platforms([entry]):
                print(f"  build {split['platform']} on {split['runner']}")

    # `build-matrix` drives the per-architecture builds, `matrix` the per-branch
    # tag assembly.
    if args.github_output and (output := os.environ.get("GITHUB_OUTPUT")):
        with open(output, "a", encoding="utf-8") as handle:
            handle.write(f"matrix={json.dumps({'include': matrix})}\n")
            handle.write(
                f"build-matrix={json.dumps({'include': split_platforms(matrix)})}\n"
            )

    return 0


if __name__ == "__main__":
    sys.exit(main())
