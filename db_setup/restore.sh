#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DUMP_DIR="${1:-$SCRIPT_DIR}"
TARGET_DB="portico_ebe"

# --- Helper functions ---

do_dump() {
    local dump_name="$1"
    local dump_path="$DUMP_DIR/$dump_name"

    echo ""
    echo "Creating dump '$dump_name'..."

    if command -v pg_dump &>/dev/null; then
        pg_dump -U "$POSTGRES_USER" -Fc "$TARGET_DB" -f "$dump_path"
    else
        CONTAINER="${POSTGRES_HOST:-portico_postgres}"
        PGUSER="${POSTGRES_USER:-postgres}"
        docker exec "$CONTAINER" pg_dump -U "$PGUSER" -Fc "$TARGET_DB" -f "/tmp/$dump_name"
        docker cp "$CONTAINER:/tmp/$dump_name" "$dump_path"
        docker exec "$CONTAINER" rm "/tmp/$dump_name"
    fi

    local size
    size=$(du -h "$dump_path" | cut -f1)
    echo "Dump created: $dump_path ($size)"
}

do_restore() {
    local selected="$1"

    echo ""
    echo "Restoring '$(basename "$selected")' into database '$TARGET_DB'..."
    echo "Dropping and recreating database..."

    if command -v pg_restore &>/dev/null; then
        psql -U "$POSTGRES_USER" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$TARGET_DB' AND pid <> pg_backend_pid();" >/dev/null 2>&1
        psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET_DB\";"
        psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE \"$TARGET_DB\";"
        pg_restore -U "$POSTGRES_USER" -d "$TARGET_DB" --no-owner --no-privileges "$selected" || {
            echo "WARNING: pg_restore finished with errors (this is often harmless)"
        }
    else
        CONTAINER="${POSTGRES_HOST:-portico_postgres}"
        PGUSER="${POSTGRES_USER:-postgres}"
        docker exec "$CONTAINER" psql -U "$PGUSER" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$TARGET_DB' AND pid <> pg_backend_pid();" >/dev/null 2>&1
        docker exec "$CONTAINER" psql -U "$PGUSER" -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET_DB\";"
        docker exec "$CONTAINER" psql -U "$PGUSER" -d postgres -c "CREATE DATABASE \"$TARGET_DB\";"
        docker exec -i "$CONTAINER" pg_restore -U "$PGUSER" -d "$TARGET_DB" --no-owner --no-privileges < "$selected" || {
            echo "WARNING: pg_restore finished with errors (this is often harmless)"
        }
    fi

    echo "Restore of '$TARGET_DB' complete."
}

# --- Main menu ---

# Non-interactive mode (docker-entrypoint): restore newest dump automatically
if [ ! -t 0 ]; then
    dump_files=()
    while IFS= read -r f; do
        dump_files+=("$f")
    done < <(ls -t "$DUMP_DIR"/*.dump 2>/dev/null)

    if [ ${#dump_files[@]} -eq 0 ]; then
        echo "No .dump files found in $DUMP_DIR"
        exit 0
    fi

    echo "Non-interactive mode: restoring newest dump."
    do_restore "${dump_files[0]}"
    exit 0
fi

# Interactive mode
echo ""
echo "Database Management - '$TARGET_DB'"
echo "==========================================="
echo "  d) Create a new dump"
echo "  r) Restore from a dump"
echo ""
read -rp "Choose action [r]: " action
action=${action:-r}

case "$action" in
    d|D)
        default_name="${TARGET_DB}_$(date +%d_%B_%Y | tr '[:upper:]' '[:lower:]').dump"
        read -rp "Dump filename [$default_name]: " dump_name
        dump_name=${dump_name:-$default_name}
        do_dump "$dump_name"
        ;;
    r|R)
        dump_files=()
        while IFS= read -r f; do
            dump_files+=("$f")
        done < <(ls -t "$DUMP_DIR"/*.dump 2>/dev/null)

        if [ ${#dump_files[@]} -eq 0 ]; then
            echo "No .dump files found in $DUMP_DIR"
            exit 0
        fi

        echo ""
        echo "Available database dumps (newest first):"
        echo "-------------------------------------------"
        for i in "${!dump_files[@]}"; do
            file="${dump_files[$i]}"
            size=$(du -h "$file" | cut -f1)
            date=$(stat -c '%y' "$file" 2>/dev/null || stat -f '%Sm' "$file" 2>/dev/null)
            printf "  %d) %s (%s, %s)\n" $((i + 1)) "$(basename "$file")" "$size" "$date"
        done
        echo ""

        read -rp "Select a dump to restore [1]: " choice
        choice=${choice:-1}

        if ! [[ "$choice" =~ ^[0-9]+$ ]] || [ "$choice" -lt 1 ] || [ "$choice" -gt ${#dump_files[@]} ]; then
            echo "Invalid selection."
            exit 1
        fi

        selected="${dump_files[$((choice - 1))]}"

        read -rp "This will DROP and recreate '$TARGET_DB'. Continue? [y/N]: " confirm
        if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
            echo "Aborted."
            exit 0
        fi

        do_restore "$selected"
        ;;
    *)
        echo "Invalid action."
        exit 1
        ;;
esac
