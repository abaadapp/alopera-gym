#!/usr/bin/env bash
# Deploys the newest version tag of main on this droplet, by itself. Run as root every two minutes by
# alopera-auto-deploy.timer (deploy/systemd); installed as /usr/local/sbin/alopera-auto-deploy.
#
#   alopera-auto-deploy              deploy when main has a newer tag that is ready
#   alopera-auto-deploy --dry-run    say what it would do; changes nothing
#
# A tag is deployed only when it is on main and CI has published its release with the built pages; CI does that only
# after the tests pass. For each deploy:
#   1. the database is dumped (pg_dump -Fc, checked with pg_restore --list) to shared/backups — no dump, no deploy;
#   2. the tag's own scripts/deploy.sh runs as deploy (it migrates, checks the release gate and switches);
#   3. root reloads PHP-FPM, then the website and the panel must answer (CHECK_URLS);
#   4. if they do not, the previous release is put back. The database stays as migrated; the log names the dump.
# A tag that failed is not tried again: the next tag is. Only newer tags are deployed, never older ones.
#
# Settings: /etc/alopera-deploy.conf (REPO, CHECK_URLS). Pause: touch /var/www/alopera-gym/shared/auto-deploy.off
# Log: /var/log/alopera-deploy.log    Status: systemctl status alopera-auto-deploy.timer
set -euo pipefail

CONF=${CONF:-/etc/alopera-deploy.conf}
# shellcheck disable=SC1090
[ -f "$CONF" ] && . "$CONF"
APP=${APP:-/var/www/alopera-gym}
REPO=${REPO:?set REPO=https://github.com/<owner>/alopera-gym.git in /etc/alopera-deploy.conf}
MIRROR=${MIRROR:-/var/cache/alopera-gym.git}
LOG=${LOG:-/var/log/alopera-deploy.log}
LOCK=${LOCK:-/run/alopera-auto-deploy.lock}
STATE=$APP/shared/deploy-state
OWNER_REPO="$(basename "$(dirname "$REPO")")/$(basename "$REPO" .git)"
DRY=""
[ "${1:-}" = "--dry-run" ] && DRY=1

say() { if [ -n "$DRY" ]; then echo "$*"; else echo "$(date '+%F %T') $*" >> "$LOG"; fi; }

exec 9>"$LOCK"
flock -n 9 || exit 0
if [ -f "$APP/shared/auto-deploy.off" ]; then [ -n "$DRY" ] && echo "paused: $APP/shared/auto-deploy.off"; exit 0; fi

# What main has: a bare mirror, updated each run (small after the first clone).
[ -d "$MIRROR" ] || git clone -q --bare "$REPO" "$MIRROR"
git -C "$MIRROR" fetch -q --prune origin '+refs/heads/main:refs/heads/main' '+refs/tags/*:refs/tags/*'
latest=$(git -C "$MIRROR" tag -l 'v[0-9]*.[0-9]*.[0-9]*' --merged main | sort -V | tail -n 1)
[ -n "$latest" ] || { say "no version tag on main"; exit 0; }

# What runs here: the last tag this script deployed, else the release folder's name (v0.20.0), else its REVISION.
deployed=$(cat "$STATE/deployed" 2>/dev/null || true)
if [ -z "$deployed" ]; then
    dir=$(basename "$(readlink -f "$APP/current")")
    if [[ "$dir" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        deployed=$dir
    elif [ -f "$APP/current/REVISION" ]; then
        deployed=$(git -C "$MIRROR" tag --points-at "$(cat "$APP/current/REVISION")" | sort -V | tail -n 1)
    fi
fi

if [ "$latest" = "$deployed" ]; then [ -n "$DRY" ] && echo "up to date: $deployed"; exit 0; fi
if [ -n "$deployed" ] && [ "$(printf '%s\n%s\n' "$deployed" "$latest" | sort -V | tail -n 1)" != "$latest" ]; then
    say "running $deployed is newer than main's $latest: nothing done"; exit 0
fi
if grep -qx "$latest" "$STATE/failed" 2>/dev/null; then [ -n "$DRY" ] && echo "$latest failed before: waiting for a newer tag"; exit 0; fi

# Ready when CI has published the release with the built pages (it does so only after the tests pass).
assets=$(curl -fsS --max-time 20 "https://api.github.com/repos/$OWNER_REPO/releases/tags/$latest" 2>/dev/null \
    | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode(" ", array_column($d["assets"] ?? [], "name"));' || true)
case " $assets " in
    *" build.tar.gz "*) ;;
    *) [ -n "$DRY" ] && echo "$latest found, its release is not ready yet (CI still building)"; exit 0 ;;
esac

if [ -n "$DRY" ]; then
    echo "would deploy ${deployed:-?} → $latest (release ready); current release: $(readlink -f "$APP/current")"
    exit 0
fi

fail() { say "✗ $latest: $*"; mkdir -p "$STATE"; echo "$latest" >> "$STATE/failed"; exit 1; }
mkdir -p "$STATE" "$APP/shared/backups"
prev=$(readlink -f "$APP/current")
say "→ deploying ${deployed:-?} → $latest"

# 1. The database, dumped and checked before anything changes.
db=$(grep -E '^DB_DATABASE=' "$APP/shared/.env" | tail -n 1 | cut -d= -f2- | sed -E 's/[[:space:]]+#.*$//; s/^["'\'']//; s/["'\'']$//')
[ -n "$db" ] || fail "DB_DATABASE not found in shared/.env"
dump=$APP/shared/backups/predeploy-$latest-$(date +%Y%m%d-%H%M%S).dump
sudo -u postgres pg_dump -Fc "$db" > "$dump" || fail "database dump failed"
chmod 600 "$dump"
tables=$(sudo -u postgres pg_restore --list < "$dump" | grep -c 'TABLE DATA' || true)
[ "$tables" -gt 0 ] || fail "database dump is empty"
say "  dump: $dump ($tables tables)"
ls -1t "$APP"/shared/backups/predeploy-*.dump 2>/dev/null | tail -n +11 | xargs -r rm -f

# 2. The tag's own deploy script, as deploy.
script=$(mktemp /tmp/alopera-deploy-XXXXXX.sh)
curl -fsSL --retry 2 "https://raw.githubusercontent.com/$OWNER_REPO/$latest/scripts/deploy.sh" -o "$script" || fail "deploy.sh not downloaded"
chmod 644 "$script"
if ! (cd "$APP" && sudo -u deploy env SKIP_BACKUP=1 BUILD_ON_SERVER=0 RELOAD_FPM=0 bash "$script" "$latest") >> "$LOG" 2>&1; then
    rm -f "$script"
    if [ "$(readlink -f "$APP/current")" != "$prev" ]; then
        ln -sfn "$prev" "$APP/current"; systemctl reload php8.4-fpm
        sudo -u deploy php "$APP/current/artisan" queue:restart >> "$LOG" 2>&1 || true
    fi
    fail "deploy.sh failed (see above); still on $(basename "$prev"); database dump: $dump"
fi
rm -f "$script"
systemctl reload php8.4-fpm

# 3. Both addresses must answer; otherwise the previous release goes back.
urls=${CHECK_URLS:-"$(grep -E '^APP_URL=' "$APP/shared/.env" | tail -n 1 | cut -d= -f2- | tr -d '"'\''')/up"}
healthy() {
    local u
    for u in $urls; do curl -fsS -o /dev/null --max-time 15 "$u" || { say "  check failed: $u"; return 1; }; done
}
ok=""
for _ in 1 2 3; do sleep 5; if healthy; then ok=1; break; fi; done
if [ -z "$ok" ]; then
    ln -sfn "$prev" "$APP/current"; systemctl reload php8.4-fpm
    sudo -u deploy php "$APP/current/artisan" queue:restart >> "$LOG" 2>&1 || true
    fail "checks failed after the switch: back on $(basename "$prev"); the database stays migrated, dump: $dump"
fi

echo "$latest" > "$STATE/deployed"
# The script updates itself from the release it just deployed.
[ -f "$APP/current/scripts/auto-deploy.sh" ] && install -m 755 "$APP/current/scripts/auto-deploy.sh" /usr/local/sbin/alopera-auto-deploy
say "✓ $latest live ($(cut -c1-7 "$APP/current/REVISION" 2>/dev/null || echo '?')); previous: $(basename "$prev")"
