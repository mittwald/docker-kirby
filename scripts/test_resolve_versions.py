"""Tests for the version resolver. No network access — the Packagist response
is stubbed so the constraint and tag logic can be checked in isolation."""

import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "resolve_versions", Path(__file__).parent / "resolve-versions.py"
)
resolve = importlib.util.module_from_spec(spec)
spec.loader.exec_module(resolve)

AVAILABLE = [
    (5, 5, 3),
    (5, 5, 0),
    (5, 0, 1),
    (4, 9, 5),
    (4, 0, 0),
    (3, 10, 1),
]


class SatisfiesTest(unittest.TestCase):
    def test_caret_pins_the_major(self):
        self.assertTrue(resolve.satisfies((5, 5, 3), "^5.0"))
        self.assertFalse(resolve.satisfies((4, 9, 5), "^5.0"))
        self.assertFalse(resolve.satisfies((6, 0, 0), "^5.0"))

    def test_caret_respects_the_floor(self):
        self.assertFalse(resolve.satisfies((5, 0, 1), "^5.5"))
        self.assertTrue(resolve.satisfies((5, 5, 0), "^5.5"))

    def test_tilde_pins_the_minor(self):
        self.assertTrue(resolve.satisfies((5, 5, 3), "~5.5"))
        self.assertFalse(resolve.satisfies((5, 6, 0), "~5.5"))

    def test_unsupported_constraint_is_rejected(self):
        with self.assertRaises(SystemExit):
            resolve.satisfies((5, 5, 3), ">=5.0")


class TagsTest(unittest.TestCase):
    def test_full_ladder_for_the_latest_branch(self):
        tags = resolve.tags_for("mittwald/kirby", {"name": "5", "latest": True}, (5, 5, 3))
        self.assertEqual(
            tags,
            [
                "mittwald/kirby:5.5.3",
                "mittwald/kirby:5.5",
                "mittwald/kirby:5",
                "mittwald/kirby:latest",
            ],
        )

    def test_non_latest_branch_has_no_latest_tag(self):
        tags = resolve.tags_for("mittwald/kirby", {"name": "4"}, (4, 9, 5))
        self.assertNotIn("mittwald/kirby:latest", tags)

    def test_branch_name_matching_a_derived_tag_is_not_duplicated(self):
        tags = resolve.tags_for("mittwald/kirby", {"name": "5.5"}, (5, 5, 3))
        self.assertEqual(tags, ["mittwald/kirby:5.5.3", "mittwald/kirby:5.5"])


class MatrixTest(unittest.TestCase):
    def manifest(self, branches):
        return {
            "image": "mittwald/kirby",
            "defaults": {"frankenphp": "1", "os": "trixie", "platforms": ["linux/amd64"]},
            "branches": branches,
        }

    def test_resolves_the_newest_matching_release_per_branch(self):
        matrix = resolve.build_matrix(
            self.manifest(
                [
                    {"name": "5", "constraint": "^5.0", "php": "8.4", "latest": True},
                    {"name": "4", "constraint": "^4.0", "php": "8.3"},
                ]
            ),
            AVAILABLE,
        )

        self.assertEqual([entry["kirby_version"] for entry in matrix], ["5.5.3", "4.9.5"])
        self.assertEqual(matrix[0]["base_image"], "dunglas/frankenphp:1-php8.4-trixie")
        self.assertEqual(matrix[1]["base_image"], "dunglas/frankenphp:1-php8.3-trixie")

    def test_plainkit_defaults_to_the_resolved_major(self):
        matrix = resolve.build_matrix(
            self.manifest([{"name": "4", "constraint": "^4.0", "php": "8.4"}]), AVAILABLE
        )

        self.assertEqual(matrix[0]["plainkit"], "^4.0")

    def test_plainkit_can_be_pinned_per_branch(self):
        # plainkit lags the CMS, so a branch may have to name an older kit than
        # its own major.
        matrix = resolve.build_matrix(
            self.manifest(
                [{"name": "6", "constraint": "^5.0", "php": "8.4", "plainkit": "^5.0"}]
            ),
            AVAILABLE,
        )

        self.assertEqual(matrix[0]["plainkit"], "^5.0")

    def test_branch_overrides_beat_defaults(self):
        matrix = resolve.build_matrix(
            self.manifest(
                [
                    {
                        "name": "5",
                        "constraint": "^5.0",
                        "php": "8.4",
                        "os": "bookworm",
                        "platforms": ["linux/amd64", "linux/arm64"],
                    }
                ]
            ),
            AVAILABLE,
        )

        self.assertEqual(matrix[0]["base_image"], "dunglas/frankenphp:1-php8.4-bookworm")
        self.assertEqual(matrix[0]["platforms"], "linux/amd64,linux/arm64")

    def test_a_branch_without_a_release_fails_the_build(self):
        with self.assertRaises(SystemExit):
            resolve.build_matrix(
                self.manifest([{"name": "9", "constraint": "^9.0", "php": "8.4"}]), AVAILABLE
            )

    def test_two_latest_branches_are_rejected(self):
        with self.assertRaises(SystemExit):
            resolve.build_matrix(
                self.manifest(
                    [
                        {"name": "5", "constraint": "^5.0", "php": "8.4", "latest": True},
                        {"name": "4", "constraint": "^4.0", "php": "8.4", "latest": True},
                    ]
                ),
                AVAILABLE,
            )


class SplitPlatformsTest(unittest.TestCase):
    def matrix(self, platforms):
        return resolve.build_matrix(
            {
                "image": "mittwald/kirby",
                "defaults": {"frankenphp": "1", "os": "trixie", "platforms": platforms},
                "branches": [
                    {"name": "5", "constraint": "^5.0", "php": "8.4", "latest": True},
                    {"name": "4", "constraint": "^4.0", "php": "8.4"},
                ],
            },
            AVAILABLE,
        )

    def test_one_entry_per_branch_and_platform(self):
        split = resolve.split_platforms(self.matrix(["linux/amd64", "linux/arm64"]))

        self.assertEqual(
            [(entry["branch"], entry["platform"]) for entry in split],
            [
                ("5", "linux/amd64"),
                ("5", "linux/arm64"),
                ("4", "linux/amd64"),
                ("4", "linux/arm64"),
            ],
        )

    def test_each_platform_gets_a_runner_of_its_own_architecture(self):
        split = resolve.split_platforms(self.matrix(["linux/amd64", "linux/arm64"]))
        runners = {entry["platform"]: entry["runner"] for entry in split}

        self.assertEqual(runners["linux/amd64"], "ubuntu-latest")
        self.assertEqual(runners["linux/arm64"], "ubuntu-24.04-arm")

    def test_the_branch_payload_survives_the_split(self):
        split = resolve.split_platforms(self.matrix(["linux/arm64"]))

        self.assertEqual(split[0]["kirby_version"], "5.5.3")
        self.assertEqual(split[0]["base_image"], "dunglas/frankenphp:1-php8.4-trixie")
        self.assertEqual(split[0]["image"], "mittwald/kirby")
        self.assertEqual(split[0]["tags"][0], "mittwald/kirby:5.5.3")

    def test_arch_is_usable_as_an_artifact_name(self):
        split = resolve.split_platforms(self.matrix(["linux/arm64"]))

        self.assertEqual(split[0]["arch"], "arm64")

    def test_the_platform_list_is_dropped(self):
        # Left in place it would make every runner build every architecture.
        split = resolve.split_platforms(self.matrix(["linux/amd64", "linux/arm64"]))

        self.assertNotIn("platforms", split[0])

    def test_a_platform_without_a_native_runner_is_rejected(self):
        # Failing loudly beats silently emulating it on amd64, which is the
        # cost this split exists to remove.
        with self.assertRaises(SystemExit):
            resolve.split_platforms(self.matrix(["linux/riscv64"]))


if __name__ == "__main__":
    unittest.main()
