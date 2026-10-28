#!/usr/bin/env bash
# Push main to GitHub together with the next version tag (vMAJOR.MINOR.PATCH):
#   scripts/release.sh            push the new commits and tag them
#   scripts/release.sh --dry-run  show the version and commits without pushing
#   scripts/release.sh --hook     for a Claude Code Stop hook: silent when there is nothing to push,
#                                 otherwise prints {"systemMessage": ...}; never fails the hook
# The bump comes from the conventional-commit subjects since the last tag: a breaking change ("type!:" or
# "BREAKING CHANGE") bumps major (minor before 1.0), feat bumps minor, anything else bumps patch.
# Pushing does not deploy: CI only runs the tests; a release goes live with scripts/deploy.sh <tag>.
set -euo pipefail

MODE=${1:-}
BRANCH=main
REMOTE=origin

say() {
    if [ "$MODE" = "--hook" ]; then
        jq -n --arg m "$1" '{systemMessage: $m}'
    else
        echo "$1"
    fi
}

# Hook mode reports problems as a message and lets Claude stop normally.
fail() {
    say "release: $1"
    [ "$MODE" = "--hook" ] && exit 0
    exit 1
}

cd "$(git rev-parse --show-toplevel)"

# A trace of each hook run (inside .git, never committed): shows the hook is live.
[ "$MODE" = "--hook" ] && date '+%F %T' >> "$(git rev-parse --git-dir)/release-hook.log"

if [ "$(git rev-parse --abbrev-ref HEAD)" != "$BRANCH" ]; then
    [ "$MODE" = "--hook" ] && exit 0
    fail "not on $BRANCH"
fi
GIT_DIR=$(git rev-parse --git-dir)
if [ -d "$GIT_DIR/rebase-merge" ] || [ -d "$GIT_DIR/rebase-apply" ] || [ -f "$GIT_DIR/MERGE_HEAD" ]; then
    fail "a merge or rebase is in progress"
fi

AHEAD=$(git rev-list --count "$REMOTE/$BRANCH..HEAD")
if [ "$AHEAD" -eq 0 ]; then
    [ "$MODE" = "--hook" ] && exit 0
    echo "nothing to push"
    exit 0
fi

# The repository is public: refuse to publish env files, keys or tokens.
LEAKED_FILES=$(git diff --name-only --diff-filter=AM "$REMOTE/$BRANCH..HEAD" \
    | grep -E '(^|/)\.env($|\.)|\.(pem|key|p12|pfx)$|(^|/)id_(rsa|ed25519)|(^|/)auth\.json$' \
    | grep -vE '\.example$' || true)
[ -z "$LEAKED_FILES" ] || fail "not pushed, secret-looking files: $(echo $LEAKED_FILES)"
LEAKED_LINES=$(git diff "$REMOTE/$BRANCH..HEAD" | grep -E '^\+' | grep -cE \
    -- '-----BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}|sk_live_[0-9A-Za-z]{10,}|sk-ant-[A-Za-z0-9_-]{20,}|xox[baprs]-[0-9A-Za-z-]{10,}|AIza[0-9A-Za-z_-]{35}|APP_KEY=base64:[A-Za-z0-9+/=]{20,}' \
    || true)
[ "$LEAKED_LINES" -eq 0 ] || fail "not pushed, $LEAKED_LINES added line(s) look like keys or tokens"

LAST=$(git describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' 2>/dev/null || true)
if [ -z "$LAST" ]; then
    NEXT=v0.1.0
    RANGE=HEAD
elif [ "$(git rev-list --count "$LAST..HEAD")" -eq 0 ]; then
    NEXT=""
    RANGE=""
else
    IFS=. read -r MAJOR MINOR PATCH <<< "${LAST#v}"
    RANGE="$LAST..HEAD"
    LOG=$(git log --format='%s%n%b' "$RANGE")
    if echo "$LOG" | grep -qE '^[a-z]+(\([^)]*\))?!:|BREAKING CHANGE'; then
        if [ "$MAJOR" -eq 0 ]; then MINOR=$((MINOR + 1)); PATCH=0; else MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0; fi
    elif echo "$LOG" | grep -qE '^feat(\([^)]*\))?:'; then
        MINOR=$((MINOR + 1)); PATCH=0
    else
        PATCH=$((PATCH + 1))
    fi
    NEXT="v$MAJOR.$MINOR.$PATCH"
fi

if [ "$MODE" = "--dry-run" ]; then
    echo "would push $AHEAD commit(s) to $REMOTE/$BRANCH${NEXT:+ and tag $NEXT}"
    [ -n "$RANGE" ] && git log --format='  %h %s' "$RANGE"
    exit 0
fi

REFS="HEAD:refs/heads/$BRANCH"
if [ -n "$NEXT" ]; then
    git rev-parse -q --verify "refs/tags/$NEXT" >/dev/null && fail "tag $NEXT already exists"
    git tag -a "$NEXT" -m "$NEXT" -m "$(git log --format='- %s' "$RANGE")"
    REFS="$REFS refs/tags/$NEXT"
fi

# --atomic: the branch and the tag land together or not at all.
if ! OUT=$(git push --atomic --quiet "$REMOTE" $REFS 2>&1); then
    [ -n "$NEXT" ] && git tag -d "$NEXT" >/dev/null
    fail "push failed: $(echo "$OUT" | grep -v '^hint:' | tail -n 2 | tr '\n' ' ')"
fi

say "pushed $AHEAD commit(s) to GitHub${NEXT:+ as $NEXT}"
