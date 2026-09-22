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
- **Architectures genuinely differ.** CI's test build is `linux/amd64`; an
  Apple Silicon machine builds arm64. A smoke test has already failed on one
  architecture while passing on the other. Note that the pushed image covers
  both but only the runner's architecture is smoke tested — a regression on the
  other one would ship silently. A pull request does not build arm64 at all:
  it is compiled under qemu, which costs more than the entire native build, so
  only `publish.yml` pays for it. An arm64-only break therefore lands on main
  before anyone sees it. It cannot ship — build and push are one step, and a
  failed architecture pushes nothing — but it does stop the publish until it is
  fixed.
- **The daily rebuild runs with the layer cache disabled** so distribution
  security updates are actually installed. Do not "optimise" that away. Both
  builds in `build.yml` take `no-cache`, not just the test build: the
  multi-architecture build keeps a layer cache of its own now, so leaving it
  out would let the nightly reuse last night's arm64 layers and quietly install
  nothing.

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
.github/workflows/      ci, publish, lint, update-versions, docs-audit;
                        build.yml and agent-task.yml are reusable
.agents/skills/         tasks needing judgement, run by agents or by hand
opencode.json           model config for the agent tasks
```

## If you are running unattended

`agent-task.yml` runs these skills under opencode on a schedule. In that mode:
never stop to ask a question, leave anything genuinely ambiguous unchanged and
say so in the pull request body, and open no pull request at all when there is
nothing to change — a clean run that produces nothing is a successful run.
Target `main`, never another base.
