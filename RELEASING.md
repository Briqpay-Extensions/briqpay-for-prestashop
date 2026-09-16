# Releasing

The module lives in two places:

| Remote | Host | Purpose |
| --- | --- | --- |
| `origin` | Bitbucket | Day-to-day development. Push freely. Holds everything. |
| `github` | GitHub | Public releases. Pushing `main` here cuts a release. |

**GitHub gets a filtered copy.** The `docker/` development stack is internal
tooling and is stripped from everything published, so it never appears in the
public repository. The list lives in `PRIVATE_PATHS` at the top of
[`scripts/publish.sh`](scripts/publish.sh); add to it if anything else should
stay in-house.

The filtering is done with git plumbing against a scratch index, so your working
tree and current branch are never touched — the local Docker stack keeps running
throughout. Each published commit is parented on whatever GitHub already has, so
pushes fast-forward rather than needing `--force`.

## One-time setup

Bitbucket is already wired up as `origin`, and the repository has `dev` and
`prod` branches:

- **`dev`** — day-to-day development. Every push runs
  [`bitbucket-pipelines.yml`](bitbucket-pipelines.yml)'s checks (lint,
  PHPStan, tests) — the same ones GitHub's CI runs, so a regression is caught
  immediately rather than only when a release is cut.
- **`prod`** — merging `dev` into `prod` (a pull request, reviewable before it
  happens) *is* the deliberate act of cutting a release. The pipeline then
  runs `scripts/publish.sh prod` unattended, pushing a `docker/`-stripped
  snapshot to GitHub.

To run `publish.sh prod` by hand as well (for local dry-runs, or if you'd
rather not rely on the pipeline), add GitHub as a second remote:

```bash
git remote add github git@github.com:Briqpay-Extensions/briqpay-for-prestashop.git
```

If you prefer different remote or branch names, tell the publish script about
them:

```bash
git config briqpay.devRemote    origin
git config briqpay.prodRemote   github
git config briqpay.devBranch    dev
git config briqpay.prodBranch   prod
git config briqpay.githubBranch main
```

### Letting the `prod` pipeline push to GitHub

The pipeline needs its own credential to push — it doesn't inherit your local
SSH keys. Two things, both one-time:

1. **Bitbucket → this repo → Repository settings → Pipelines → SSH keys.**
   Click **Generate keys** (or paste your own). Bitbucket makes the private
   half available to every pipeline step automatically — nothing to add as a
   separate "secret" or repository variable. On the same page, use **Fetch**
   next to *Known hosts* for `github.com` (the pipeline script also runs
   `ssh-keyscan` itself as a fallback, so this step is optional but tidier).

2. **GitHub → `Briqpay-Extensions/briqpay-for-prestashop` → Settings → Deploy
   keys → Add deploy key.** Paste the *public* half of the key from step 1.
   Check **Allow write access** — required, since the pipeline pushes, not
   just fetches.

A deploy key is scoped to this one repository, unlike a personal access
token, so nothing else in the GitHub org is reachable with it. No other
secrets are needed: the release gate (version/CHANGELOG checks) reads the
repository's own files, and GitHub's Actions (tagging, building the release
archive) run entirely on GitHub's side once the push lands.

Then update the CI badge at the top of [`README.md`](README.md) to point at the
real repository, if it doesn't already.

## Day-to-day

Push to `dev` as normal — `git push origin dev`, or `./scripts/publish.sh dev`
if you want its safety checks (clean tree, correct branch). Nothing beyond the
pipeline's checks happens — no tags, no builds, no published artifacts.

## Cutting a release

Releases are driven by the module's own version number. Bumping it *is* the
release.

1. **Bump the version** in both places — they must match, and CI fails the build
   if they drift:
   - `briqpay_payment_module/briqpay_payment_module.php` → `$this->version`
   - `briqpay_payment_module/config.xml` → `<version>`

2. **Add a CHANGELOG section** headed `## [X.Y.Z]`. The release is refused
   without one.

3. **Add an upgrade script** if the release changes the schema, hooks or
   settings: `briqpay_payment_module/upgrade/upgrade-X.Y.Z.php`. PrestaShop runs
   it automatically when a merchant installs over an older version.

4. **Commit to `dev`, then open a pull request into `prod` and merge it.**
   That merge triggers the Bitbucket pipeline, which re-runs the checks and
   then `scripts/publish.sh prod` unattended — no prompts, since the merge
   itself was the confirmation. Watch the pipeline in Bitbucket; if it fails
   on the version/CHANGELOG/test gate, nothing was pushed to GitHub.

   To publish by hand instead (skipping the pipeline; useful for a dry run):

   ```bash
   ./scripts/publish.sh prod
   ```

   The script checks the working tree is clean, you're on `prod`, the two
   version numbers agree, the CHANGELOG has a matching section, and the tests
   pass — then asks for confirmation before pushing.

   Use `./scripts/publish.sh both` to push `dev` and `prod` in one go.

## What happens on GitHub

```
./scripts/publish.sh prod
      │
      ├─ builds a commit = current tree minus docker/
      │
      ▼
push → github/main
      │
      ▼
  Tag workflow  (.github/workflows/tag.yml)
    reads $this->version
    verifies config.xml matches and the CHANGELOG has a section
    creates tag vX.Y.Z  ── already exists? stop here, nothing to do
      │
      ▼
  Release workflow  (.github/workflows/release.yml)
    re-checks tag == module version == config.xml
    runs the test suite
    builds briqpay_payment_module-vX.Y.Z.zip
    publishes a GitHub release with generated notes
```

Pushing `main` again without a version bump does nothing — the tag already
exists, so the Tag workflow stops and no release is published. That makes it
safe to push documentation or CI changes to GitHub at any time.

The CI workflow (tests, PHPStan, php-cs-fixer, structure checks) runs on every
push and pull request, independently of this.

### Tagging by hand

If you'd rather tag yourself, that works too — a tag pushed by a person triggers
the Release workflow directly:

```bash
git tag -a v2.1.0 -m "Briqpay for PrestaShop v2.1.0"
git push github v2.1.0
```

> The Tag workflow has to *call* the Release workflow rather than relying on the
> tag push to trigger it: GitHub deliberately suppresses workflow runs for tags
> pushed with `GITHUB_TOKEN`, to stop workflows triggering themselves. That is
> why `release.yml` accepts both `push: tags` and `workflow_call`.

## Rolling back

A release is a tag plus a GitHub release. To withdraw one:

```bash
gh release delete v2.1.0 --yes
git push github :refs/tags/v2.1.0
git tag -d v2.1.0
```

Then fix forward with a new patch version rather than re-pointing the old tag —
merchants may already have downloaded the archive.
