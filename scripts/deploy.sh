#!/usr/bin/env bash
# Release deploy on the droplet (run as the deploy user):
#   /var/www/alopera-gym/current/scripts/deploy.sh [git-ref]
# Layout: /var/www/alopera-gym/{releases/<ts>, shared/{.env,storage}, current -> releases/<ts>}
set -euo pipefail

APP=/var/www/alopera-gym
REPO=${REPO:-https://github.com/abaadapp/alopera-gym.git}
REF=${1:-main}
TS=$(date +%Y%m%d%H%M%S)
REL=$APP/releases/$TS

echo "→ cloning $REF into $REL"
git clone --depth 1 --branch "$REF" "$REPO" "$REL"

ln -sfn "$APP/shared/.env" "$REL/.env"
rm -rf "$REL/storage" && ln -sfn "$APP/shared/storage" "$REL/storage"

cd "$REL"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

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
echo "✓ deployed $REF ($TS)"
