#!/usr/bin/env bash
# Platform-wide PostgreSQL dump → gzip → DigitalOcean Spaces (S3 API via s3cmd).
# Per-club archives (data + files) are produced separately by `php artisan backup:tenants`.
set -euo pipefail

APP=/var/www/alopera-gym
set -a; source "$APP/shared/.env"; set +a

STAMP=$(date +%Y%m%d-%H%M%S)
OUT=$APP/shared/backups/db-$STAMP.sql.gz
mkdir -p "$APP/shared/backups"

PGPASSWORD="$DB_PASSWORD" pg_dump --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" \
  --no-owner --no-privileges --format=plain "$DB_DATABASE" | gzip -9 > "$OUT"

if [[ -n "${SPACES_BUCKET:-}" ]]; then
  s3cmd put "$OUT" "s3://$SPACES_BUCKET/db/$(basename "$OUT")" --host="${SPACES_ENDPOINT#https://}" --host-bucket="%(bucket)s.${SPACES_ENDPOINT#https://}"
fi

# local retention: 14 days
find "$APP/shared/backups" -name 'db-*.sql.gz' -mtime +14 -delete
echo "✓ $OUT"
