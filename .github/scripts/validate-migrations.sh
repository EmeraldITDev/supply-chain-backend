#!/usr/bin/env bash

set -euo pipefail

if [[ "${1:-}" == "--all" ]]; then
    mapfile -t migration_files < <(find database/migrations -maxdepth 1 -type f -name '*.php' -print | sort)
else
    base_ref="${1:-}"
    if [[ -n "$base_ref" ]]; then
        mapfile -t migration_files < <(git diff --name-only --diff-filter=AM "$base_ref" HEAD -- 'database/migrations/*.php')
    else
        mapfile -t migration_files < <(git diff-tree --no-commit-id --name-only --diff-filter=AM -r HEAD -- 'database/migrations/*.php')
    fi
fi

if [[ "${#migration_files[@]}" -eq 0 ]]; then
    echo "No new or modified PHP migration files to validate."
    exit 0
fi

for migration_file in "${migration_files[@]}"; do
    [[ -f "$migration_file" ]] || continue

    echo "Checking $migration_file"
    php -l "$migration_file" >/dev/null

    if grep -nE '^[[:space:]]*use[[:space:]]+Illuminate(DatabaseMigrationsMigration|DatabaseSchemaBlueprint|SupportFacadesSchema|[A-Z])' "$migration_file"; then
        echo "Suspicious flattened Laravel namespace import found in $migration_file" >&2
        exit 1
    fi
done

echo "Validated ${#migration_files[@]} PHP migration file(s)."
