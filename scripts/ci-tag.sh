#!/usr/bin/env bash
# Tag a commit on main with the next version (vMAJOR.MINOR.PATCH) once CI has passed on it, so every push to main
# gets its tag, pull requests merged on GitHub included:
#   scripts/ci-tag.sh [commit]            tag the commit (default HEAD) and push the tag
#   scripts/ci-tag.sh --dry-run [commit]  print the tag it would make, push nothing
# The bump rule is the one in scripts/release.sh: a breaking change ("type!:" or "BREAKING CHANGE") bumps major
# (minor before 1.0), feat bumps minor, anything else bumps patch. A commit already covered by a tag (pushed with
# release.sh) is left alone. A tag deploys nothing: a release goes live only with scripts/deploy.sh <tag>.
set -euo pipefail

DRY=""
if [ "${1:-}" = "--dry-run" ]; then DRY=1; shift; fi
SHA=$(git rev-parse "${1:-HEAD}^{commit}")

LAST=$(git describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' "$SHA" 2>/dev/null || true)
if [ -z "$LAST" ]; then
    NEXT=v0.1.0
    RANGE=$SHA
elif [ "$(git rev-list --count "$LAST..$SHA")" -eq 0 ]; then
    echo "nothing to tag: ${SHA:0:7} is $LAST"
    exit 0
else
    IFS=. read -r MAJOR MINOR PATCH <<< "${LAST#v}"
    RANGE="$LAST..$SHA"
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

# A later push may already have taken this number (two pushes close together): its tag covers these commits.
if git rev-parse -q --verify "refs/tags/$NEXT" >/dev/null || git ls-remote --exit-code --tags origin "refs/tags/$NEXT" >/dev/null 2>&1; then
    echo "nothing to tag: $NEXT already exists"
    exit 0
fi

# The message lists the commits, not GitHub's "Merge pull request" lines.
NOTES=$(git log --no-merges --format='- %s' "$RANGE")
[ -n "$NOTES" ] || NOTES="- merged changes"

if [ -n "$DRY" ]; then
    echo "would tag ${SHA:0:7} as $NEXT (after ${LAST:-no tag})"
    echo "$NOTES"
    exit 0
fi

git tag -a "$NEXT" -m "$NEXT" -m "$NOTES" "$SHA"
git push --quiet origin "refs/tags/$NEXT"
echo "tagged ${SHA:0:7} as $NEXT"
