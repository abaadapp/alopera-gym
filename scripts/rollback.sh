#!/usr/bin/env bash
# Back to an earlier release on the droplet (run as the deploy user):
#   /var/www/alopera-gym/current/scripts/rollback.sh             the release before the current one
#   /var/www/alopera-gym/current/scripts/rollback.sh <ts>        a given one (ls /var/www/alopera-gym/releases)
# Only the code is switched: the database, shared/.env and shared/storage stay as they are. Migrations are not
# undone; a release whose migrations must be reversed is restored from the dump deploy.sh made in shared/backups.
set -euo pipefail

APP=/var/www/alopera-gym
CURRENT=$(readlink -f "$APP/current")

if [ -n "${1:-}" ]; then
    TARGET=$APP/releases/$1
else
    TARGET=$(ls -1dt "$APP"/releases/* | grep -vx "$CURRENT" | head -n 1 || true)
fi
[ -n "$TARGET" ] && [ -d "$TARGET" ] || { echo "✗ no release to go back to"; exit 1; }
[ "$(readlink -f "$TARGET")" != "$CURRENT" ] || { echo "✗ $TARGET is already current"; exit 1; }

echo "→ $(basename "$CURRENT") → $(basename "$TARGET") ($(cut -c1-7 "$TARGET/REVISION" 2>/dev/null || echo '?'))"
ln -sfn "$TARGET" "$APP/current"
[ "${RELOAD_FPM:-1}" = "0" ] || sudo systemctl reload php8.4-fpm   # RELOAD_FPM=0 when root reloads it
php "$APP/current/artisan" queue:restart
echo "✓ rolled back to $(basename "$TARGET")"
