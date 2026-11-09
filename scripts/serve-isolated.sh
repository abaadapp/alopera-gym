#!/usr/bin/env bash
# The app on this computer for a browser check, on a database of its own: never the dev data (.env) nor production.
#
#   scripts/serve-isolated.sh <database> [port]          e.g.  scripts/serve-isolated.sh alopera_gym_ui_testing
#
# The database's name must contain "test" (lower case, digits and _ only), and must not be the .env one. It is made if
# missing, migrated, and given the demo data when it has no user yet (the demo seeder refuses production). The server is
# PHP's own, started from public/ with DB_DATABASE and APP_URL in its environment: `php artisan serve` passes neither to
# the server it starts, which then serves the .env database. Before starting, the app itself says which database it
# uses. Ctrl-C stops it; the database stays until dropped by hand (dropdb <database>).
set -euo pipefail

die() { printf '✗ %s\n' "$*" >&2; exit 1; }

DB="${1:-}"
PORT="${2:-8765}"
[[ -n "$DB" ]] || die "usage: scripts/serve-isolated.sh <database whose name contains test> [port]"
cd "$(dirname "${BASH_SOURCE[0]}")/.."

[[ -f .env ]] || die "no .env in $(pwd)"
envval() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true; }

[[ "$(envval APP_ENV)" != "production" ]] || die "APP_ENV is production in .env: this is for a computer, never the server"
[[ "$DB" =~ ^[a-z0-9_]+$ ]] || die "\"$DB\": lower case letters, digits and _ only"
[[ "$DB" == *test* ]] || die "\"$DB\" is not a test database: its name must contain \"test\""
[[ "$DB" != "$(envval DB_DATABASE)" ]] || die "\"$DB\" is the .env database, the app's own data"
[[ ! -f bootstrap/cache/config.php ]] || die "the configuration is cached, so DB_DATABASE would be ignored: run php artisan config:clear"
[[ "$PORT" =~ ^[0-9]+$ ]] || die "port: a number"

export DB_DATABASE="$DB" APP_URL="http://127.0.0.1:$PORT" PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

# The app's own word for the database it will use, before anything is written.
USING="$(php artisan tinker --execute='echo DB::connection()->getDatabaseName();' 2>/dev/null | tail -1)"
[[ "$USING" == "$DB" ]] || die "the app would use \"$USING\", not \"$DB\": stopped"

if ! php artisan tinker --execute='DB::connection()->getPdo();' >/dev/null 2>&1; then
    DB_DATABASE=postgres php artisan tinker --execute="DB::statement('create database $DB');" >/dev/null
    echo "▸ made the database $DB"
fi
php artisan migrate --force --no-interaction
if [[ "$(php artisan tinker --execute='echo DB::table("users")->count();' 2>/dev/null | tail -1)" == "0" ]]; then
    php artisan db:seed --force --no-interaction
fi

echo "▸ http://127.0.0.1:$PORT on the database $DB (Ctrl-C to stop; dropdb $DB when done)"
cd public
exec php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
