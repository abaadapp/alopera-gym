#!/usr/bin/env bash
# The originals against REAL test buckets (Spaces, or any S3 server), from this computer, never production:
#
#   scripts/spaces-live-test.sh <keys file> <database>           the bucket self-tests, then tests/Feature/SpacesLiveTest
#   scripts/spaces-live-test.sh <keys file> <database> serve [port]   the isolated app (scripts/serve-isolated.sh) with
#                                                                     originals in the test bucket, for a browser check
#
# The keys file is a plain KEY=value file OUTSIDE the repository, readable by you only (chmod 600), with MEDIA_SPACES_KEY,
# _SECRET, _REGION, _BUCKET, _ENDPOINT and MEDIA_COPY_KEY, _SECRET, _REGION, _BUCKET, _ENDPOINT. Both bucket names must
# contain "test" and neither may be the database copies' bucket (SPACES_BUCKET in .env). Everything printed passes
# through a filter that hides the keys, the secrets and every signature, and the app's log is off for the run.
set -euo pipefail

die() { printf '✗ %s\n' "$*" >&2; exit 1; }

KEYS="${1:-}"
DB="${2:-}"
MODE="${3:-test}"
PORT="${4:-8791}"
[[ -f "$KEYS" && -n "$DB" ]] || die "usage: scripts/spaces-live-test.sh <keys file> <test database> [serve [port]]"
cd "$(dirname "${BASH_SOURCE[0]}")/.."
REPO="$(pwd -P)"
KEYS="$(cd "$(dirname "$KEYS")" && pwd -P)/$(basename "$KEYS")"

[[ ! -e "${ALOPERA_PRODUCTION_ROOT:-/var/www/alopera-gym}" ]] || die "this is the production server: never here"
case "$KEYS" in "$REPO"/*) die "the keys file must be outside the repository" ;; esac
[[ "$(stat -f %Lp "$KEYS" 2>/dev/null || stat -c %a "$KEYS")" == "600" ]] || die "make the keys file readable by you only: chmod 600 $KEYS"
[[ "$DB" == *test* ]] || die "\"$DB\" is not a test database"

set -a
# shellcheck disable=SC1090
source "$KEYS"
set +a
for v in MEDIA_SPACES_KEY MEDIA_SPACES_SECRET MEDIA_SPACES_REGION MEDIA_SPACES_BUCKET MEDIA_SPACES_ENDPOINT \
         MEDIA_COPY_KEY MEDIA_COPY_SECRET MEDIA_COPY_REGION MEDIA_COPY_BUCKET MEDIA_COPY_ENDPOINT; do
    [[ -n "${!v:-}" ]] || die "$v is missing in the keys file"
done
for b in "$MEDIA_SPACES_BUCKET" "$MEDIA_COPY_BUCKET"; do
    [[ "$b" == *test* ]] || die "bucket \"$b\": a test bucket's name contains \"test\""
done
[[ "$MEDIA_SPACES_BUCKET" != "$MEDIA_COPY_BUCKET" ]] || die "the copy must be another bucket"
BACKUP="$(grep -E '^SPACES_BUCKET=' .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || true)"
[[ -z "$BACKUP" || ( "$BACKUP" != "$MEDIA_SPACES_BUCKET" && "$BACKUP" != "$MEDIA_COPY_BUCKET" ) ]] || die "that is the database copies' bucket"
case "$MEDIA_SPACES_ENDPOINT $MEDIA_COPY_ENDPOINT" in *.cdn.*) die "use the region's endpoint, not the CDN one" ;; esac

# Hides every key, secret and signature in what is printed (literal match, whatever characters they hold).
hide() {
    perl -pe 'BEGIN { $| = 1; @s = grep { length } @ENV{qw(MEDIA_SPACES_KEY MEDIA_SPACES_SECRET MEDIA_COPY_KEY MEDIA_COPY_SECRET)} }
        for my $x (@s) { s/\Q$x\E/[hidden]/g }
        s/(X-Amz-(?:Credential|Signature))=[^&"\s]*/$1=[hidden]/g;
        s/(Authorization: )\S.*/$1[hidden]/g;'
}

export DB_DATABASE="$DB" GYM_HOME_CLUB="" LOG_CHANNEL=null SPACES_LIVE_TEST=1 SPACES_LIVE_ORIGIN="http://127.0.0.1:$PORT"

if [[ "$MODE" == "serve" ]]; then
    export MEDIA_ORIGINALS=spaces GYM_MAX_IMAGE_KB="${GYM_MAX_IMAGE_KB:-25600}" MEDIA_MAX_PIXELS="${MEDIA_MAX_PIXELS:-50000000}" MEDIA_MAX_REQUEST_KB="${MEDIA_MAX_REQUEST_KB:-30720}"
    scripts/serve-isolated.sh "$DB" "$PORT" 2>&1 | hide
    exit "${PIPESTATUS[0]}"
fi

status=0
php artisan media:spaces-check --origin="$SPACES_LIVE_ORIGIN" 2>&1 | hide || status=1
php artisan media:spaces-check --bucket=copy 2>&1 | hide || status=1
php artisan test --group=spaces-live 2>&1 | hide || status=1
exit $status
