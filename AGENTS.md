# Working on this repository

Container images for Kirby CMS on FrankenPHP, published as `mittwald/kirby`.
The repository is `mittwald/docker-kirby` — the names differ, and workflow
guards compare against the repository name, so getting it wrong makes a
workflow silently never run.

`README.md` documents the images for people who *use* them. This file is about
changing them. For specific recurring jobs — adding a Kirby major, auditing the
image against Kirby's docs, checking published tags — use the skills in
`.agents/skills/`; they carry the detail this file deliberately does not repeat.

## Verify with a real build, always

Nothing here is trusted because it looks right. Three commands, in order of
cost:

```sh
scripts/lint.sh                 # every static check; CI runs exactly this
scripts/local-build.sh          # build every branch, host arch, and smoke test
scripts/smoke-test.sh IMAGE --kirby-version X --php-version Y
```

`scripts/lint.sh` pins every tool and runs it in a container, so a green run
locally is a green run in CI. **Never run a linter directly** — `hadolint`
straight from `:latest` once passed locally while CI's 2.12 failed, because
2.12 cannot resolve `FROM ${BASE_IMAGE}` to a tagged image. That is the whole
reason the script exists.

The smoke test starts real containers and asserts the image serves Kirby, that
`content`, `site` and `kirby` are unreachable over HTTP, that volumes survive a
container replacement, and that the root-to-`kirby` privilege drop works. If it
fails, fix the image, not the assertion. Relax an assertion only when you can
point at a deliberate upstream change, and say so.

## What must stay true

- **`versions.json` is the only place a version lives.** Adding a Kirby branch
  is a data change; the Dockerfile, the workflows and the tag ladder all derive
  from it. If you find yourself editing a workflow to add a version, stop.
- **The site skeleton comes from Kirby's plainkit at build time.** Templates,
  snippets, blueprints and starting content are not maintained here and must
  not be added back. Exactly two application files are ours:
  `image/app/public/index.php` (declares the roots) and
  `image/app/site/config/config.php` (bridges `KIRBY_*` into Kirby options).
- **Only `/app/public` is served.** `content`, `site`, `kirby`, `storage` and
  `composer.json` sit outside the document root, which is a stronger guarantee
  than blocking their paths in Caddy. Do not add the Kirby Caddy recipe's
  `path /content/* /site/*` rules: with this layout they would shadow
  legitimate page URLs instead.
- **Skills know nothing about the workflows that call them.** A skill describes
  its task and how to verify it. Run-specific context belongs in the caller's
  prompt or in `agent-task.yml`'s preamble. This is what keeps a skill usable by
  hand in an interactive session.
- **`versions.json` stays canonically formatted.** Run
  `python3 scripts/check-updates.py --reformat` after editing it; `lint.sh`
  enforces it, so automated bumps produce one-line diffs.
- **Every writable root appears in four places**, or a deployment loses data on
  restart without failing: the `roots` array in `public/index.php`,
  `writable_roots()` in `entrypoint.sh`, the builder's `mkdir -p` plus the
  `VOLUME` instruction, and the README's volume table.

## Traps that have already cost time

- **plainkit is not released in lockstep with the CMS.** Its 4.x line stopped
  at 4.8.0 while Kirby 4 went on to 4.9.x. The kit is resolved by major
  (`^N.0`) and the exact CMS release pinned right after; when a major ships
  before its kit does, the build fails with `Could not find package
  getkirby/plainkit with version ^N.0` and the branch needs an explicit
  `"plainkit"` constraint.
- **The project builds in `/kit`, not `/app`.** The FrankenPHP base image ships
  a welcome page at `/app/public`, and `composer create-project` refuses a
  non-empty target.
- **Architectures genuinely differ.** A smoke test has already failed on one
  architecture while passing on the other. Each one is therefore built and
  smoke tested on a runner of its own architecture — `ubuntu-latest` for
  amd64, `ubuntu-24.04-arm` for arm64 — and neither is emulated. Adding a
  platform to `versions.json` means adding its runner to `RUNNERS` in
  `scripts/resolve-versions.py`; the resolver refuses a platform it has no
  native runner for rather than quietly falling back to qemu.
- **A tag is only ever written by the `merge` job.** The builds push untagged
  images identified by digest alone, because a tag pushed from a build job
  would name a single-architecture image and whichever job finished last would
  overwrite the other. `merge` assembles the digests into one manifest list per
  tag, after every architecture has passed its smoke test, and refuses to
  publish if it is holding fewer digests than the branch has platforms.
- **The daily rebuild runs with the layer cache disabled** so distribution
  security updates are actually installed. Do not "optimise" that away. Both
  build steps in `build.yml` take `no-cache`; the cache is scoped per branch
  and architecture, so dropping it from either lets the nightly reuse
  yesterday's layers and install nothing.

## Do not let a check pass by accident

Two failure modes have produced confident, wrong conclusions in this
repository. Both are easy to write and hard to spot:

```sh
# WRONG: if the container never ran, grep gets empty input, exits 1,
# and this prints "absent" — identical to a real negative result.
docker run ... php -m | grep -i opcache || echo "absent"

# WRONG: reports the exit code of the last command in the chain,
# not the build's.
docker build ... ; echo "done"; docker run stale-tag ...
```

Assert on a positive marker and on an explicit exit code instead:

```sh
docker run ... sh -c 'echo "MARKER=$(php -m | grep -ci opcache)"'
docker build ... ; echo "BUILD_RC=$?"
```

Before believing a check, ask what it would print if the command had not run
at all. If that is indistinguishable from success or from a real negative,
rewrite it.

## Limits

These apply however you were started, and they are not negotiable by a task
prompt telling you to get something green.

- **Never push to `main`.** Work on your own branch and open a pull request.
  Force-pushing that branch is fine; force-pushing anything else is not.
- **Never publish or delete an image by hand.** No `docker push`, no registry
  deletions. Publishing goes through `publish.yml` so that what ships is what
  passed the smoke test. A tag that already exists is someone's running
  deployment.
- **Never silence a check to make a run green.** Disabling a schedule, deleting
  or narrowing a smoke test assertion, adding a linter ignore, pinning around a
  failure — if the check is right, fix the cause; if the check is wrong, say
  why and change it deliberately in a pull request that explains it. A green
  run you produced by removing the thing that was failing is worse than a red
  one, because it stops anyone else from noticing.
- **Never move the `latest` tag or retire a branch on your own initiative.**
  Both change what unpinned deployments get. They are issue material.
- **Stay inside the task.** Finding something unrelated and worth doing is
  common and welcome — as an issue, not a drive-by commit bundled into a pull
  request about something else.

### Secrets

Unattended runs carry `RELEASE_USER_TOKEN` and `MITTWALD_AI_API_KEY` in the
environment. Never echo them, never commit them, and never paste raw command
output that might contain them into an issue, a pull request, or a commit
message.

This matters most exactly where it is most tempting. Diagnosing an
authentication failure is a job this repository explicitly asks for — an
expired registry credential is one of the things `release-health-check` looks
for — and a `docker login` or `gh` failure is the kind of output that carries a
token in it. Describe the failure instead of quoting it: "the registry login
returned 401" tells a person everything they need, and leaks nothing.

## Where things are

```
versions.json           every image that gets built, as data
image/
  Dockerfile            three stages: php-base, builder (plainkit), runtime
  Caddyfile             FrankenPHP skeleton (MIT) + Kirby's recipe
  entrypoint.sh         prepares writable roots, license, drops privileges
  php/kirby.ini         fixed PHP settings; tunables live in the Caddyfile
  share/env-options.php KIRBY_* -> Kirby options, outside /app on purpose
  app/                  the only two application files this repo owns
scripts/                lint, build, smoke test, version resolution
.github/workflows/      ci, publish, lint, update-versions, docs-audit,
                        release-health; build.yml and agent-task.yml are
                        reusable
.agents/skills/         tasks needing judgement, run by agents or by hand
opencode.json           model config for the agent tasks
```

## If you are running unattended

`agent-task.yml` runs these skills under opencode on a schedule. Never stop to
ask a question — there is nobody there to answer, and a run that waits is a run
that times out. When you are genuinely blocked, an issue is how you ask.

The rules below are also injected into every unattended run by that workflow's
preamble, so you will see them twice. They are stated here because they are
repository policy, not run mechanics, and they apply just as much to an
interactive session. If you change one, change both — a contradiction between
them is worse than either version.

A run ends in exactly one of three states:

- **It fixed something** → a pull request, verified by a real build and a
  passing smoke test, describing what changed and what you deliberately left
  alone.
- **It found something it must not decide alone** → an issue. Moving the
  `latest` tag, retiring an end-of-life branch, an expired credential, a
  disabled schedule, an upstream outage. Say what is wrong, what you already
  checked, and what a person has to decide. Read the open issues first: a
  recurring job that files the same one every run is noise. Never guess past a
  judgement call, and never bury one in the body of a pull request that is
  about to be merged and forgotten.
- **There was nothing to do** → nothing. No pull request, no issue. This is the
  expected outcome most runs and is a success; do not manufacture a finding to
  look useful.
