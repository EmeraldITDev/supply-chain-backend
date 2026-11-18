#!/usr/bin/env bash

set -euo pipefail

if [[ "${1:-}" == "--all" ]]; then
    mapfile -t migration_files < <(find database/migrations -maxdepth 1 -type f -name '*.php' -print | sort)
    file_label="migration"
elif [[ "${1:-}" == "--all-php" ]]; then
    mapfile -t migration_files < <(git ls-files '*.php' | sort)
    file_label="PHP"
elif [[ "${1:-}" == "--php" ]]; then
    base_ref="${2:-}"
    if [[ -n "$base_ref" ]]; then
        mapfile -t migration_files < <(git diff --name-only --diff-filter=AM "$base_ref" HEAD -- '*.php')
    else
        mapfile -t migration_files < <(git diff-tree --no-commit-id --name-only --diff-filter=AM -r HEAD -- '*.php')
    fi
    file_label="PHP"
else
    base_ref="${1:-}"
    if [[ -n "$base_ref" ]]; then
        mapfile -t migration_files < <(git diff --name-only --diff-filter=AM "$base_ref" HEAD -- 'database/migrations/*.php')
    else
        mapfile -t migration_files < <(git diff-tree --no-commit-id --name-only --diff-filter=AM -r HEAD -- 'database/migrations/*.php')
    fi
fi

if [[ "${#migration_files[@]}" -eq 0 ]]; then
    echo "No new or modified PHP files to validate."
    exit 0
fi

for migration_file in "${migration_files[@]}"; do
    [[ -f "$migration_file" ]] || continue

    echo "Checking $migration_file"
    php -l "$migration_file" >/dev/null

    if grep -nE '^[[:space:]]*(namespace|use)[[:space:]]+(App|Illuminate|Laravel|Tests)([A-Za-z]|$)' "$migration_file"; then
        echo "Suspicious flattened PHP namespace found in $migration_file" >&2
        exit 1
    fi
done

echo "Validated ${#migration_files[@]} ${file_label:-PHP migration} file(s)."
