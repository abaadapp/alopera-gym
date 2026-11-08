#!/usr/bin/env bash
# Going back by hand, code only, on the droplet, as deploy:
#   bash /var/www/alopera-gym/app/scripts/rollback-code.sh <commit>
# The commit to go back to is in /var/www/alopera-gym/shared/deploy-state/ROLLBACK.txt ("commit before the deploy").
# The database is not touched and no dump is restored. If migrations came in between, it stops: older code may not
# work on the newer tables, and tables are not put back by a command. Name the ones you have checked (added only,
# not read by the older code) in ALLOW_MIGRATIONS="file.php other.php" to go on.
# Afterwards revert the change on main itself (Revert on GitHub), or the next deploy brings the same code back.
set -euo pipefail

# git reset rewrites this very file while bash is reading it: run a copy of it instead.
if [[ "${ROLLBACK_DETACHED:-}" != "1" ]]; then
    SELF="$(mktemp "${TMPDIR:-/tmp}/alopera-rollback.XXXXXX")"
    cat "${BASH_SOURCE[0]}" > "$SELF"
    ROLLBACK_DETACHED=1 exec bash "$SELF" "$@"
fi
[[ "$(basename "${BASH_SOURCE[0]}")" == alopera-rollback.* ]] && trap 'rm -f "${BASH_SOURCE[0]}"' EXIT

APP_DIR="${APP_DIR:-/var/www/alopera-gym/app}"
STATE_DIR="${STATE_DIR:-/var/www/alopera-gym/shared/deploy-state}"
HEALTH_URL="${HEALTH_URL:-https://app.aloperagym.om/up}"
PHP_BIN="${PHP_BIN:-php}"
RELOAD_FPM="${RELOAD_FPM:-sudo -n /usr/bin/systemctl reload php8.4-fpm}"
export npm_config_cache="${NPM_CACHE:-$HOME/.npm}"

step() { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
art()  { "$PHP_BIN" "$APP_DIR/artisan" "$@"; }

TARGET="${1:-}"
[[ -n "$TARGET" ]] || die "name the commit to go back to: \"commit before the deploy\" in $STATE_DIR/ROLLBACK.txt"
[[ "$(id -u)" != 0 ]] || die "not as root: going back runs as deploy, who owns $APP_DIR"
[[ -d "$APP_DIR/.git" && -O "$APP_DIR/.git" ]] || die "$(id -un) does not own a git working tree in $APP_DIR"

cd "$APP_DIR"
git cat-file -e "$TARGET^{commit}" 2>/dev/null || die "no commit \"$TARGET\" in $APP_DIR: git fetch first"
FROM="$(git rev-parse --short HEAD)"
TO="$(git rev-parse --short "$TARGET^{commit}")"
[[ "$FROM" != "$TO" ]] || die "the code is on $TO already: nothing to go back to"
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || die "uncommitted changes in the tree would be wiped out: see git status"

# Migrations between the two: each module keeps its own (app/Modules/*/Database/migrations) beside database/migrations.
MIGRATIONS="$(git diff --name-only "$TO" "$FROM" -- database/migrations 'app/Modules/*/Database/migrations')"
UNLISTED=""
while IFS= read -r m; do
    [[ -z "$m" ]] && continue
    [[ " ${ALLOW_MIGRATIONS:-} " == *" $(basename "$m") "* ]] || UNLISTED+="$m"$'\n'
done <<<"$MIGRATIONS"
[[ -z "$UNLISTED" ]] || die "migrations came in between $TO and $FROM: the older code may not work on the newer tables, and tables are not put back by a command:
$(sed '/^$/d; s/^/    /' <<<"$UNLISTED")
Stop here, and do not restore a dump over the live database: it would wipe out everything since.
If you checked them and they only add what the older code never reads, name them in ALLOW_MIGRATIONS and run again."

step "الكود: $FROM → $TO"
git reset --hard --quiet "$TO"
ok "$TO"

step "اعتماديات PHP"
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet
art package:discover --quiet
ok "composer · package:discover"

step "بناء الواجهة"
npm ci --no-audit --no-fund --silent
rm -rf public/build.new public/build.old
npm run build -- --outDir public/build.new --emptyOutDir 2>&1 | tail -3
[[ -f public/build.new/manifest.json ]] || { rm -rf public/build.new; die "the build made no manifest: the code is back on $TO, the pages are still the newer ones"; }
if [[ -d public/build ]]; then mv public/build public/build.old; fi
mv public/build.new public/build || { mv public/build.old public/build; die "swapping the pages failed: the newer ones are back"; }
rm -rf public/build.old
ok "pages"

step "الذاكرة المؤقتة"
art config:cache --quiet
art route:cache --quiet
art view:cache --quiet
art event:cache --quiet
$RELOAD_FPM
art queue:restart --quiet
ok "config · route · view · event · php-fpm · queue worker"

step "الموقع"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || true)"
[[ "$CODE" == "200" ]] || die "the site answers ${CODE:-000} on $TO as well"
ok "$HEALTH_URL → 200"

mkdir -p "$STATE_DIR"
printf '%s\n%s\n%s\n' "rollback" "$TO" "$(date -Is)" > "$STATE_DIR/DEPLOYED.txt"
printf '\n\033[1mرجع: %s → %s\033[0m\n' "$FROM" "$TO"
printf 'now revert the change on main (Revert on GitHub), or the next deploy brings %s back\n' "$FROM"
