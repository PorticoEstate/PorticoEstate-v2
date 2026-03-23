#!/bin/bash
set -e

# Restore any .dump files found in /docker-entrypoint-initdb.d/
# This script runs automatically when the postgres container initializes
# (i.e., when the data volume is empty).

for dump_file in /docker-entrypoint-initdb.d/*.dump; do
    [ -f "$dump_file" ] || continue

    db_name=$(basename "$dump_file" .dump)
    echo "Creating database '$db_name' and restoring from $dump_file..."

    psql -U "$POSTGRES_USER" -c "CREATE DATABASE \"$db_name\";" 2>/dev/null || true
    pg_restore -U "$POSTGRES_USER" -d "$db_name" --no-owner --no-privileges "$dump_file" || {
        echo "WARNING: pg_restore finished with errors for $dump_file (this is often harmless)"
    }

    echo "Restore of '$db_name' complete."
done
