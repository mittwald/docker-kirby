---
name: release-health-check
description: Verify that what is actually published on Docker Hub matches what versions.json promises — every tag exists, is recent, is multi-arch, and runs. Use as a periodic (weekly) check, or after a failed or partially failed publish run.
---

# Release health check

The daily publish can fail quietly in ways CI does not catch: a run that never
started because the schedule was disabled on an inactive repository, a push
that succeeded for one architecture, or a tag that silently stopped being
updated. This check compares the promise in `versions.json` against reality on
Docker Hub.

## 1. What should exist

```
python3 scripts/resolve-versions.py --format json
```

Every entry in `tags` must exist on Docker Hub, and the `platforms` field says
which architectures the manifest list has to contain.

## 2. What does exist

For each tag:

```
docker buildx imagetools inspect mittwald/kirby:<tag> --raw
```

Check:

- **Presence.** A missing tag means the publish never ran or never finished.
- **Architectures.** The manifest list must contain every platform from the
  matrix entry. A single-platform manifest means the multi-arch build step
  failed while the test build succeeded.
- **Freshness.** `org.opencontainers.image.created` must be within the last
  ~48 hours, since the rebuild is daily. An older date means the schedule is
  not firing — GitHub disables scheduled workflows on repositories with no
  activity for 60 days, which is the usual cause.
- **Agreement.** `org.opencontainers.image.version` must equal the
  `kirby_version` the resolver reports for that branch. A mismatch means a
  publish is lagging behind Packagist.

Note that GitHub Actions cron is best-effort and can be delayed under load; one
late run is not a fault, three days of silence is.

## 3. Does it actually run

Pull and smoke test the published image rather than a locally built one — this
is the only check that exercises what users get:

```
docker pull mittwald/kirby:<branch>
scripts/smoke-test.sh mittwald/kirby:<branch> \
  --kirby-version <resolved version> --php-version <php>
```

Do this at least for the branch carrying `latest`.

## 4. Workflow runs

```
gh run list --workflow publish.yml --limit 10
gh run list --workflow update-versions.yml --limit 5
```

A publish run that is red for the same matrix entry several days running is
worth an issue even if the tags on Docker Hub still look fine — it means the
image has stopped receiving security updates while continuing to exist.

## Output

Report only what is actually wrong, with the command output that shows it.
If everything is healthy, say so in one line and stop — do not open an issue,
and do not re-run the publish workflow to "refresh" a healthy tag.

For a real failure, open an issue naming the affected tags, the last known good
build date, and the likely cause. Re-running the publish workflow
(`gh workflow run publish.yml`) is the right first remedy for a transient
failure; a repeated failure needs the underlying cause fixed instead.
