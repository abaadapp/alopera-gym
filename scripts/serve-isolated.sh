#!/usr/bin/env bash
# The app on this computer for a browser check, on a database of its own: never the dev data (.env) nor production.
#
#   scripts/serve-isolated.sh <database> [port]          e.g.  scripts/serve-isolated.sh alopera_gym_ui_testing
#
# Nothing is made, migrated, seeded or served until each check passes, in this order:
#   1. here, before any PHP: not the production server (/var/www/alopera-gym), .env not APP_ENV=production, a plain name
#      with "test" that is not the .env database, no cached configuration;
#   2. the app's own settings, without connecting (Tests\Support\DatabaseIsolation, the same checks as every PHPUnit
#      test): APP_ENV local or testing, PostgreSQL, no DB_URL, a host on this computer, the database asked for;
#   3. once connected (the database made first if missing, from the server's "postgres" database on that same local
#      host): PostgreSQL's own answer, the database reached and the address of the server answering.
# Then migrate, the demo data when it has no user yet (the demo seeder refuses production), and PHP's own server started
# from public/ with DB_DATABASE and APP_URL in its environment (`php artisan serve` passes neither to the server it
# starts, which then serves the .env database). Ctrl-C stops it; the database stays until dropped (dropdb <database>).
set -euo pipefail

die() { printf '✗ %s\n' "$*" >&2; exit 1; }

DB="${1:-}"
PORT="${2:-8765}"
[[ -n "$DB" ]] || die "usage: scripts/serve-isolated.sh <database whose name contains test> [port]"
cd "$(dirname "${BASH_SOURCE[0]}")/.."
PRODUCTION_ROOT="${ALOPERA_PRODUCTION_ROOT:-/var/www/alopera-gym}"

# 1. Before any PHP.
[[ ! -e "$PRODUCTION_ROOT" ]] || die "this is the production server ($PRODUCTION_ROOT exists): never here"
[[ -f .env ]] || die "no .env in $(pwd)"
envval() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true; }
[[ "$(envval APP_ENV)" != "production" ]] || die "the .env file says APP_ENV=production: never here"
[[ "$DB" =~ ^[a-z0-9_]+$ ]] || die "\"$DB\": lower case letters, digits and _ only"
[[ "$DB" == *test* ]] || die "\"$DB\" is not a test database: its name must contain \"test\""
[[ "$DB" != "$(envval DB_DATABASE)" ]] || die "\"$DB\" is the .env database, the app's own data"
[[ ! -f bootstrap/cache/config.php ]] || die "the configuration is cached, so DB_DATABASE would be ignored: run php artisan config:clear"
[[ "$PORT" =~ ^[0-9]+$ ]] || die "port: a number"

export DB_DATABASE="$DB" APP_URL="http://127.0.0.1:$PORT" PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

# One line from the app: "ok" or the reason it refuses.
ask() { php artisan tinker --execute="$1" 2>&1 | grep -E '^(ISOLATION|REFUSED) ' | tail -1 || true; }

# 2. The app's own settings, without connecting.
CHECK='$f = Tests\Support\DatabaseIsolation::facts(app()); $r = Tests\Support\DatabaseIsolation::refusal($f, ["local", "testing"]);
if ($r === null && $f["database"] !== getenv("DB_DATABASE")) { $r = "the app would use \"".$f["database"]."\", not \"".getenv("DB_DATABASE")."\"."; }
echo $r === null ? "ISOLATION ok ".$f["host"] : "REFUSED ".$r, PHP_EOL;'
ANSWER="$(ask "$CHECK")"
[[ "$ANSWER" == "ISOLATION ok "* ]] || die "nothing was made, migrated or served: ${ANSWER#REFUSED }"

if ! php artisan tinker --execute='DB::connection()->getPdo();' >/dev/null 2>&1; then
    DB_DATABASE=postgres php artisan tinker --execute="DB::statement('create database $DB');" >/dev/null
    echo "▸ made the database $DB"
fi

# 3. PostgreSQL's own answer, before migrate and seed.
LIVE='try { Tests\Support\DatabaseIsolation::assert(app(), ["local", "testing"], ""); echo "ISOLATION live", PHP_EOL; }
catch (Throwable $e) { echo "REFUSED ", $e->getMessage(), PHP_EOL; }'
ANSWER="$(ask "$LIVE")"
[[ "$ANSWER" == "ISOLATION live" ]] || die "nothing was migrated or served: ${ANSWER#REFUSED }"

php artisan migrate --force --no-interaction
if [[ "$(php artisan tinker --execute='echo DB::table("users")->count();' 2>/dev/null | tail -1)" == "0" ]]; then
    php artisan db:seed --force --no-interaction
fi

echo "▸ http://127.0.0.1:$PORT on the database $DB (Ctrl-C to stop; dropdb $DB when done)"
cd public
exec php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
