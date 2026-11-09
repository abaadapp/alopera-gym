#!/usr/bin/env bash
# Production deploy, on the droplet, as deploy (never root), in the one working tree /var/www/alopera-gym/app
# (/var/www/alopera-gym/current points at it, so Nginx is unchanged).
#
# Started by GitHub Actions (Deploy Main) over SSH: the deploy key in ~deploy/.ssh/authorized_keys runs this script
# and nothing else (command=). By hand, as deploy:   bash /var/www/alopera-gym/app/scripts/deploy.sh
#
# In order: the new migrations checked before anything changes (one that drops, renames or deletes, not marked as
# reviewed, stops the deploy here) · pull main · PHP dependencies · build the pages aside, then swap them in · migrate
# (no database dump before) · caches, reload PHP-FPM and the queue worker · preflight (reported, does not stop the
# deploy; flagged in the Deploy Main run) · the site must answer 200 · tag. The last line says what runs now: "تمّ: <from> → <to> (<version>)".
# Going back is by hand, code only: scripts/rollback-code.sh <commit> (the commit is in shared/deploy-state/ROLLBACK.txt).
set -euo pipefail

# git pull rewrites this very file while bash is reading it: run a copy of it instead.
if [[ "${DEPLOY_DETACHED:-}" != "1" ]]; then
    SELF="$(mktemp "${TMPDIR:-/tmp}/alopera-deploy.XXXXXX")"
    cat "${BASH_SOURCE[0]}" > "$SELF"
    DEPLOY_DETACHED=1 exec bash "$SELF" "$@"
fi
[[ "$(basename "${BASH_SOURCE[0]}")" == alopera-deploy.* ]] && trap 'rm -f "${BASH_SOURCE[0]}"' EXIT

APP_DIR="${APP_DIR:-/var/www/alopera-gym/app}"
BRANCH="${BRANCH:-main}"
STATE_DIR="${STATE_DIR:-/var/www/alopera-gym/shared/deploy-state}"
HEALTH_URL="${HEALTH_URL:-https://app.aloperagym.om/up}"
PHP_BIN="${PHP_BIN:-php}"
RELOAD_FPM="${RELOAD_FPM:-sudo -n /usr/bin/systemctl reload php8.4-fpm}"
export npm_config_cache="${NPM_CACHE:-$HOME/.npm}"

step() { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
art()  { "$PHP_BIN" "$APP_DIR/artisan" "$@"; }

[[ "$(id -u)" != 0 ]] || die "not as root: the deploy runs as deploy, who owns $APP_DIR (docs/DEPLOY.md)"
[[ -d "$APP_DIR/.git" ]] || die "no git working tree in $APP_DIR: see the one-time setup in docs/DEPLOY.md"
[[ -O "$APP_DIR/.git" ]] || die "$(id -un) does not own $APP_DIR/.git: nothing is deployed into a tree owned by someone else"

cd "$APP_DIR"
FROM="$(git rev-parse --short HEAD)"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$STATE_DIR"
printf 'commit before the deploy of %s: %s\nback (code only): bash %s/scripts/rollback-code.sh %s\nno database dump was taken before this deploy: going back with the code does not undo its migrations\n' \
    "$STAMP" "$FROM" "$APP_DIR" "$FROM" > "$STATE_DIR/ROLLBACK.txt"

step "سحب $BRANCH"
git fetch --tags --force --quiet origin

# Before anything changes on the server: what main's new migrations will do (scripts/migration-risk.php, from main's
# own tree). Dropping or renaming a table or column, changing a type or deleting rows stops here unless the migration
# carries @deploy-destructive-reviewed, seen by the owner in its pull request.
# New since the last commit whose migrations all ran (MIGRATED.txt), not since HEAD: a deploy that pulled and then
# failed before migrating leaves HEAD at main, and its migrations would otherwise run on the next deploy unchecked.
# Before MIGRATED.txt exists: the last deployed commit (DEPLOYED.txt), then HEAD.
MIGRATED_BASE="HEAD"
for candidate in "$(head -1 "$STATE_DIR/MIGRATED.txt" 2>/dev/null || true)" "$(sed -n 2p "$STATE_DIR/DEPLOYED.txt" 2>/dev/null || true)"; do
    if [[ -n "$candidate" ]] && git cat-file -e "$candidate^{commit}" 2>/dev/null; then MIGRATED_BASE="$candidate"; break; fi
done
NEW_MIGRATIONS=()
while IFS= read -r f; do [[ -n "$f" ]] && NEW_MIGRATIONS+=("$f"); done < <(git diff --name-only --diff-filter=AR "$MIGRATED_BASE" "origin/$BRANCH" -- \
    'database/migrations/*.php' 'app/Modules/*/Database/migrations/*.php')
if (( ${#NEW_MIGRATIONS[@]} > 0 )); then
    RISK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/alopera-migrations.XXXXXX")"
    if git cat-file -e "origin/$BRANCH:scripts/migration-risk.php" 2>/dev/null; then
        git show "origin/$BRANCH:scripts/migration-risk.php" > "$RISK_DIR/migration-risk.php"
        for f in "${NEW_MIGRATIONS[@]}"; do mkdir -p "$RISK_DIR/$(dirname "$f")"; git show "origin/$BRANCH:$f" > "$RISK_DIR/$f"; done
        RISK_RC=0
        RISK_OUT="$(cd "$RISK_DIR" && "$PHP_BIN" migration-risk.php "${NEW_MIGRATIONS[@]}" 2>&1)" || RISK_RC=$?
        rm -rf "$RISK_DIR"
        sed 's/^/    /' <<<"$RISK_OUT"
        (( RISK_RC == 0 )) || die "a new migration drops, renames or deletes, and is not marked as reviewed: nothing was changed on the server
(not pulled, not migrated). Look at it; if it is meant, mark it with @deploy-destructive-reviewed in a pull request, then deploy again."
        ok "${#NEW_MIGRATIONS[@]} new migration(s) checked before pulling"
        # Read by GitHub Actions: rows rewritten are not stopped, but shown on the Deploy Main run.
        if grep -q 'rewrites rows' <<<"$RISK_OUT"; then
            echo "::warning title=migrations::a new migration rewrites existing rows (update / UPDATE … SET): it is not stopped; look that it only fills what it adds"
        fi
    else
        rm -rf "$RISK_DIR"
        warn "main has no scripts/migration-risk.php: the new migrations were not checked"
    fi
fi

git pull --ff-only --quiet origin "$BRANCH"
TO="$(git rev-parse --short HEAD)"
if [[ "$FROM" == "$TO" ]]; then
    ok "nothing new ($TO): built and cached again all the same"
else
    ok "$FROM → $TO"
    git --no-pager log --oneline "$FROM..$TO" | sed 's/^/    /'
fi

step "اعتماديات PHP"
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet
# What composer's own post-autoload-dump does first: the cached package lists may name packages no longer installed
# (removed, or dev only), and artisan could not even start to rebuild them.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php
art package:discover --quiet
ok "composer (no scripts) · package:discover"

step "بناء الواجهة"
npm ci --no-audit --no-fund --silent
rm -rf public/build.new public/build.old
npm run build -- --outDir public/build.new --emptyOutDir 2>&1 | tail -3
[[ -f public/build.new/manifest.json ]] || { rm -rf public/build.new; die "the build made no manifest: stopped, the live pages are untouched"; }
if [[ -d public/build ]]; then mv public/build public/build.old; fi
mv public/build.new public/build || { mv public/build.old public/build; die "swapping the pages failed: the old ones are back"; }
rm -rf public/build.old
ok "pages built aside, then swapped in"

step "الترحيلات"
MIGRATE_LOG="$STATE_DIR/migrate-$STAMP.log"
( umask 077; : > "$MIGRATE_LOG" )
MIGRATE_RC=0
art migrate --force > "$MIGRATE_LOG" 2>&1 || MIGRATE_RC=$?
MIGRATED="$(grep -E '^[[:space:]]*[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[^ ]+ .*(DONE|FAIL)[[:space:]]*$' "$MIGRATE_LOG" \
    | sed -E 's/^[[:space:]]*([^ ]+) .*(DONE|FAIL)[[:space:]]*$/\1  \2/' || true)"
[[ -z "$MIGRATED" ]] || sed 's/^/    /' <<<"$MIGRATED"
(( MIGRATE_RC == 0 )) || die "the migrations failed: stopped before the caches and the PHP reload.
What ran before the failure stays in the database, and no dump brings it back. The whole error: $MIGRATE_LOG"
# Every migration of this commit has run: the next deploy checks only what comes after it.
git rev-parse HEAD > "$STATE_DIR/MIGRATED.txt"
if [[ -n "$MIGRATED" ]]; then
    warn "ran with no dump before them: what a migration dropped, renamed or converted is not brought back by going back with the code"
elif grep -q 'Nothing to migrate' "$MIGRATE_LOG"; then
    ok "no new migration"
else
    warn "could not read from migrate's output what ran: see $MIGRATE_LOG"
fi

step "الذاكرة المؤقتة"
art config:cache --quiet
art route:cache --quiet
art view:cache --quiet
art event:cache --quiet
$RELOAD_FPM
art queue:restart --quiet
ok "config · route · view · event · php-fpm · queue worker"

step "فحص ما بعد النشر"
set +e
art platform:preflight
PREFLIGHT=$?
set -e
# Read by GitHub Actions from the deploy's output: the Deploy Main run shows it as a warning, not only in the log.
(( PREFLIGHT == 0 )) || echo "::warning title=platform:preflight::the release gate reports blocking issues (FAIL in the table above): the deploy goes on; they are the owner's to decide"

step "الموقع"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || true)"
if [[ "$CODE" == "200" ]]; then
    ok "$HEALTH_URL → 200"
else
    die "the site answers ${CODE:-000}. Put the code back by hand; the database is not touched:
    bash $APP_DIR/scripts/rollback-code.sh $FROM
then revert the change on main itself (Revert on GitHub), or the next deploy brings the same code back.
No dump was taken before this deploy: what its migrations changed stays, and is fixed by a new migration."
fi

step "الوسم"
if EXISTING="$(git describe --exact-match --tags --match 'v0.*' HEAD 2>/dev/null)" && [[ "$EXISTING" =~ ^v0\.[0-9]+$ ]]; then
    ok "already tagged: $EXISTING"
    VERSION="$EXISTING"
else
    LAST="$(git tag --list 'v0.*' | sed -E 's/^v0\.([0-9]+).*/\1/' | grep -E '^[0-9]+$' | sort -n | tail -1 || true)"
    VERSION="v0.$(( ${LAST:-0} + 1 ))"
    if git -c user.name='alopera-deploy' -c user.email='deploy@aloperagym.om' \
         tag -a "$VERSION" -m "نُشر على الإنتاج $(date +%Y-%m-%d\ %H:%M)" 2>/dev/null; then
        ok "$VERSION → $TO (on the droplet; Deploy Main pushes the tag to GitHub)"
    else
        warn "could not make the tag $VERSION: the deploy is done all the same"
    fi
fi

printf '%s\n%s\n%s\n' "$VERSION" "$TO" "$(date -Is)" > "$STATE_DIR/DEPLOYED.txt"
printf '\n\033[1mتمّ: %s → %s (%s)\033[0m\n' "$FROM" "$TO" "$VERSION"
[[ $PREFLIGHT -eq 0 ]] || printf '\033[33m! preflight reports blocking issues above: the deploy is done, the issues are yours to decide\033[0m\n'
printf 'to go back: %s\n' "$STATE_DIR/ROLLBACK.txt"
