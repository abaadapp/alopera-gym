#!/usr/bin/env bash
# Release deploy on the droplet (run as the deploy user):
#   /var/www/alopera-gym/current/scripts/deploy.sh [git-ref]      deploy a version tag (vX.Y.Z) or a branch
#   scripts/rollback.sh                                            back to the previous release
# Layout: /var/www/alopera-gym/{releases/<ts>, shared/{.env,storage,backups}, current -> releases/<ts>}
# The database, shared/.env (with APP_KEY) and shared/storage (logos, uploads, chat files) are never replaced: each
# release links to them. The database is dumped to shared/backups before migrating (SKIP_BACKUP=1 to skip).
# A version tag's built pages are downloaded from its GitHub release (built by CI, so the 1 GB droplet does not run
# npm); a branch, or a tag without them, is built here as before (BUILD_ON_SERVER=0 refuses that instead).
set -euo pipefail

APP=/var/www/alopera-gym
REPO=${REPO:-https://github.com/abaadapp/alopera-gym.git}
REF=${1:-main}
TS=$(date +%Y%m%d%H%M%S)
REL=$APP/releases/$TS

# The shared files must already be there: this script never makes or replaces the production .env.
[ -f "$APP/shared/.env" ] || { echo "✗ $APP/shared/.env is missing: nothing deployed"; exit 1; }
[ -d "$APP/shared/storage" ] || { echo "✗ $APP/shared/storage is missing: nothing deployed"; exit 1; }

echo "→ cloning $REF into $REL"
git clone --depth 1 --branch "$REF" "$REPO" "$REL"
git -C "$REL" rev-parse HEAD > "$REL/REVISION"

ln -sfn "$APP/shared/.env" "$REL/.env"
rm -rf "$REL/storage" && ln -sfn "$APP/shared/storage" "$REL/storage"

cd "$REL"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# A file of the tag's GitHub release: by its public link, or, when that link fails (GitHub has answered 504 there
# while the rest of the site worked), by the API, which serves the same file without signing in. Checked by sha256.
fetch_asset() {
    local name=$1 dest=$2 api
    curl -fsSL --retry 2 -o "$dest" "${REPO%.git}/releases/download/$REF/$name" && return 0
    api=$(curl -fsSL "https://api.github.com/repos/$(basename "$(dirname "$REPO")")/$(basename "$REPO" .git)/releases/tags/$REF" \
        | php -r '$d = json_decode(stream_get_contents(STDIN), true); foreach ($d["assets"] ?? [] as $a) { if ($a["name"] === $argv[1]) { echo $a["url"]; } }' "$name") || return 1
    [ -n "$api" ] && echo "  (public link failed: $name from the GitHub API)" && curl -fsSL --retry 2 -H 'Accept: application/octet-stream' -o "$dest" "$api"
}

if [[ "$REF" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] && fetch_asset build.tar.gz /tmp/alopera-build-$TS.tar.gz \
    && fetch_asset build.tar.gz.sha256 /tmp/alopera-build-$TS.tar.gz.sha256 \
    && (cd /tmp && sed "s#build.tar.gz#alopera-build-$TS.tar.gz#" alopera-build-$TS.tar.gz.sha256 | sha256sum -c --quiet -); then
    echo "→ built pages from the $REF release"
    tar -xzf /tmp/alopera-build-$TS.tar.gz -C public
    rm -f /tmp/alopera-build-$TS.tar.gz /tmp/alopera-build-$TS.tar.gz.sha256
elif [ "${BUILD_ON_SERVER:-1}" = "1" ]; then
    echo "→ building the pages here (no release build for $REF)"
    npm ci --no-audit --no-fund
    npm run build
    rm -rf node_modules
else
    echo "✗ no release build for $REF and BUILD_ON_SERVER=0: nothing deployed"; exit 1
fi
[ -f public/build/manifest.json ] || { echo "✗ public/build/manifest.json missing: nothing deployed"; exit 1; }

if [ "${SKIP_BACKUP:-0}" != "1" ]; then
    echo "→ database backup before migrating"
    bash "$REL/scripts/backup-database.sh"
fi

php artisan migrate --force
php artisan storage:link --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Release gate: production settings and no usable demo accounts. A FAIL stops here, before the switch.
php artisan platform:preflight

ln -sfn "$REL" "$APP/current"
sudo systemctl reload php8.4-fpm
php artisan queue:restart

# keep the last 5 releases
ls -1dt $APP/releases/* | tail -n +6 | xargs -r rm -rf
echo "✓ deployed $REF ($TS, $(cut -c1-7 "$REL/REVISION"))"
