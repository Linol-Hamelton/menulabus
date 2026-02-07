#!/usr/bin/env bash
set -euo pipefail

# Run N cleanup batches using the same SQL file.
# Usage:
#   ./sql/modx-redirects-cleanup-run-many.sh 3 labus_pro

BATCHES="${1:-1}"
DB_NAME="${2:-labus_pro}"

if ! [[ "$BATCHES" =~ ^[0-9]+$ ]] || [ "$BATCHES" -lt 1 ]; then
  echo "BATCHES must be a positive integer"
  exit 1
fi

for i in $(seq 1 "$BATCHES"); do
  echo "=== Cleanup batch $i/$BATCHES ==="
  mysql -D "$DB_NAME" < sql/modx-redirects-cleanup-run-01.sql
done

