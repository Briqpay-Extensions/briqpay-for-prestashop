#!/usr/bin/env bash
#
# Copyright since 2020 Briqpay AB
#
# Push the module to its two homes.
#
#   ./scripts/publish.sh dev    Push to Bitbucket. Day-to-day development.
#   ./scripts/publish.sh prod   Push to GitHub. This cuts a release.
#   ./scripts/publish.sh both   Dev first, then prod.
#
# Bitbucket itself now mirrors this split as branches: `dev` runs the CI
# checks on every push (bitbucket-pipelines.yml), and merging dev into `prod`
# triggers a pipeline that runs this same script (prod) unattended to push
# the release to GitHub. Running it by hand still works the same way, from
# either branch.
#
# Pushing to GitHub is what releases: the Tag workflow reads $this->version from
# the module, tags it if that version has no tag yet, and the Release workflow
# builds and publishes the archive. Bumping the version is therefore the act of
# cutting a release, so this script refuses to push prod unless the version,
# config.xml and CHANGELOG all agree and the tests pass.
#
# GitHub gets a filtered snapshot: the internal tooling listed in
# PRIVATE_PATHS below (the docker/ dev stack) is stripped before pushing, so
# it never appears in the public repository. Bitbucket keeps everything.
#
# Remote and branch names are read from git config, so they can differ per
# checkout:
#
#   git config briqpay.devRemote    origin
#   git config briqpay.prodRemote   github
#   git config briqpay.publicBranch public
#   git config briqpay.devBranch    dev      # expected local/Bitbucket branch for `dev`
#   git config briqpay.prodBranch   prod     # expected local/Bitbucket branch for `prod`
#   git config briqpay.githubBranch main     # branch name pushed to on GitHub
#
# This also runs unattended from the Bitbucket "prod" pipeline. Bitbucket sets
# CI=true on every pipeline step; when that is set, every interactive prompt
# below either resolves automatically (the confirmation the script would ask
# a human -- the pipeline being triggered on the prod branch at all is already
# that human's decision, made by merging dev into prod) or fails loudly
# instead of guessing (a branch/state mismatch, which should never happen in
# an automated run and is not something to paper over).

set -euo pipefail

readonly ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly GITHUB_BRANCH_DEFAULT="main"
readonly CI="${CI:-}"

# Paths that stay internal. They live on Bitbucket but are stripped out of
# everything pushed to GitHub, which merchants can see. RELEASING.md
# describes internal infrastructure (Bitbucket, the dev/prod pipeline, the
# GitHub deploy key) that has no reason to be visible on the public repo.
readonly PRIVATE_PATHS=(docker RELEASING.md)

cd "$ROOT"

DEV_REMOTE="$(git config --get briqpay.devRemote || echo origin)"
PROD_REMOTE="$(git config --get briqpay.prodRemote || echo github)"
PUBLIC_BRANCH="$(git config --get briqpay.publicBranch || echo public)"
DEV_BRANCH="$(git config --get briqpay.devBranch || echo dev)"
PROD_BRANCH="$(git config --get briqpay.prodBranch || echo prod)"
GITHUB_BRANCH="$(git config --get briqpay.githubBranch || echo "$GITHUB_BRANCH_DEFAULT")"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"

info()  { printf '\033[0;36m›\033[0m %s\n' "$*"; }
ok()    { printf '\033[0;32m✓\033[0m %s\n' "$*"; }
warn()  { printf '\033[0;33m!\033[0m %s\n' "$*"; }
fail()  { printf '\033[0;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    sed -n '3,43p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

require_remote() {
    local remote="$1" role="$2"
    if ! git remote get-url "$remote" >/dev/null 2>&1; then
        fail "No '$remote' remote for $role.
  Add it once:    git remote add $remote <url>
  Or point at an existing remote:
                  git config briqpay.${role}Remote <name>"
    fi
}

module_version() {
    grep -oE "\\\$this->version = '[^']+" \
        briqpay_payment_module/briqpay_payment_module.php | sed "s/.*'//"
}

config_version() {
    grep -oE '<version><!\[CDATA\[[^]]+' \
        briqpay_payment_module/config.xml | sed 's/.*\[//'
}

check_clean_tree() {
    if [ -n "$(git status --porcelain)" ]; then
        fail "Working tree is not clean. Commit or stash first."
    fi
    ok "working tree clean"
}

check_branch() {
    local expected="$1"

    if [ "$BRANCH" = "$expected" ]; then
        return
    fi

    if [ -n "$CI" ]; then
        fail "Running non-interactively on branch '$BRANCH', expected '$expected'. Not pushing."
    fi

    warn "On branch '$BRANCH', not '$expected'."
    read -r -p "  Push anyway? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || fail "Aborted."
}

check_versions() {
    local main config
    main="$(module_version)"
    config="$(config_version)"

    [ -n "$main" ] || fail "Could not read the version from the module file."

    if [ "$main" != "$config" ]; then
        fail "Version mismatch: module is $main, config.xml is $config."
    fi
    ok "version $main matches config.xml"

    if ! grep -q "^## \[$main\]" CHANGELOG.md; then
        fail "CHANGELOG.md has no [$main] section. Add one before releasing."
    fi
    ok "CHANGELOG has a [$main] section"

    if git rev-parse "v$main" >/dev/null 2>&1; then
        warn "Tag v$main already exists locally; GitHub will skip tagging."
    else
        info "GitHub will tag this push as v$main"
    fi

    VERSION="$main"
}

run_tests() {
    if [ ! -x vendor/bin/phpunit ]; then
        if [ -n "$CI" ]; then
            fail "vendor/bin/phpunit is missing. 'composer install' must run before this step."
        fi
        warn "vendor/bin/phpunit is missing; run 'composer install' to test before releasing."
        read -r -p "  Push without running tests? [y/N] " reply
        [[ "$reply" =~ ^[Yy]$ ]] || fail "Aborted."
        return
    fi

    info "running tests"
    local output
    if ! output="$(vendor/bin/phpunit 2>&1)"; then
        echo "$output"
        fail "Tests failed. Not pushing."
    fi
    ok "tests pass"
}

push_dev() {
    require_remote "$DEV_REMOTE" dev
    check_branch "$DEV_BRANCH"
    info "pushing $BRANCH → $DEV_REMOTE ($(git remote get-url "$DEV_REMOTE"))"
    git push "$DEV_REMOTE" "$BRANCH"
    ok "pushed to $DEV_REMOTE"
}

# Build a commit holding the current tree minus the internal paths.
#
# Done with plumbing against a scratch index rather than by checking out a
# branch and deleting files: the working tree is never touched, so the local
# docker stack keeps running and there is no way to strand the user on another
# branch if something fails part way.
#
# The commit is parented on whatever GitHub already has, so the push is an
# ordinary fast-forward rather than a force-push.
build_public_commit() {
    local present=()
    local path

    for path in "${PRIVATE_PATHS[@]}"; do
        if git ls-files --error-unmatch "$path" >/dev/null 2>&1; then
            present+=("$path")
        fi
    done

    local scratch_index="$ROOT/.git/briqpay-publish-index"
    rm -f "$scratch_index"

    local tree
    tree="$(
        export GIT_INDEX_FILE="$scratch_index"
        git read-tree HEAD
        if [ ${#present[@]} -gt 0 ]; then
            git rm -r -q --cached --ignore-unmatch "${present[@]}"
        fi
        git write-tree
    )"

    rm -f "$scratch_index"

    [ -n "$tree" ] || fail "Could not build the public tree."

    # Chain onto the existing public history when there is any.
    local parent=()
    if git fetch -q "$PROD_REMOTE" "$GITHUB_BRANCH" 2>/dev/null; then
        parent=(-p FETCH_HEAD)
        info "continuing from the existing $PROD_REMOTE/$GITHUB_BRANCH"
    else
        info "no existing $PROD_REMOTE/$GITHUB_BRANCH; starting the public history"
    fi

    local message="Release $VERSION"
    if [ ${#present[@]} -gt 0 ]; then
        message="$message

Internal tooling not published: ${present[*]}"
    fi

    PUBLIC_COMMIT="$(git commit-tree "$tree" ${parent[@]+"${parent[@]}"} -m "$message")"

    [ -n "$PUBLIC_COMMIT" ] || fail "Could not create the public commit."

    if [ ${#present[@]} -gt 0 ]; then
        ok "excluded from the public repository: ${present[*]}"
    else
        info "nothing internal to strip"
    fi
}

push_prod() {
    require_remote "$PROD_REMOTE" prod

    check_clean_tree
    check_branch "$PROD_BRANCH"
    check_versions
    run_tests

    local url
    url="$(git remote get-url "$PROD_REMOTE")"

    echo
    warn "About to release $VERSION to $url"
    info "Internal paths not published: ${PRIVATE_PATHS[*]}"
    if [ -n "$CI" ]; then
        info "non-interactive (CI); proceeding -- the merge into '$PROD_BRANCH' was the confirmation"
    else
        read -r -p "  Continue? [y/N] " reply
        [[ "$reply" =~ ^[Yy]$ ]] || fail "Aborted."
    fi

    build_public_commit

    info "pushing → $PROD_REMOTE/$GITHUB_BRANCH"
    git push "$PROD_REMOTE" "$PUBLIC_COMMIT:refs/heads/$GITHUB_BRANCH"
    ok "pushed to $PROD_REMOTE"

    # Keep a local ref so the last published snapshot is inspectable.
    git update-ref "refs/heads/$PUBLIC_BRANCH" "$PUBLIC_COMMIT"
    info "local ref '$PUBLIC_BRANCH' now points at what was published"

    echo
    ok "Done. GitHub Actions will now tag v$VERSION and publish the release."
    info "Watch it: ${url%.git}/actions"
}

case "${1:-}" in
    dev)  push_dev ;;
    prod) push_prod ;;
    both) push_dev; echo; push_prod ;;
    -h|--help|"") usage 0 ;;
    *) fail "Unknown target '${1}'. Use dev, prod or both." ;;
esac
