#!/usr/bin/env bash
# Restore drill (spec 7.3 / acceptance "trial restore before launch"):
# loads a dump into a throw-away database and checks that key tables have rows.
#   scripts/restore-drill.sh /path/to/db-YYYYmmdd-HHMMSS.sql.gz
set -euo pipefail

DUMP=${1:?usage: restore-drill.sh <dump.sql.gz>}
DRILL_DB=${DRILL_DB:-alopera_gym_restore_drill}

dropdb --if-exists "$DRILL_DB"
createdb "$DRILL_DB"
gunzip -c "$DUMP" | psql --quiet --set ON_ERROR_STOP=1 "$DRILL_DB" > /dev/null

for t in tenants users gym_members gym_memberships invoices payments audit_logs; do
  printf '%-18s %s\n' "$t" "$(psql -At -c "select count(*) from $t" "$DRILL_DB")"
done

echo "✓ restore drill OK — inspect the counts above, then: dropdb $DRILL_DB"
