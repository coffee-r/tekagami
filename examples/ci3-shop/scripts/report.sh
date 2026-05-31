#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
EXAMPLE_DIR="${ROOT_DIR}/examples/ci3-shop"
TRACE="${EXAMPLE_DIR}/var/tekagami.jsonl"

php "${ROOT_DIR}/bin/tekagami" report "${TRACE}" > "${EXAMPLE_DIR}/var/report.md"
php "${ROOT_DIR}/bin/tekagami" report "${TRACE}" --format json > "${EXAMPLE_DIR}/var/report.json"
php "${ROOT_DIR}/bin/tekagami" export "${TRACE}" --format json > "${EXAMPLE_DIR}/var/export.json"

echo "wrote ${EXAMPLE_DIR}/var/report.md"
echo "wrote ${EXAMPLE_DIR}/var/report.json"
echo "wrote ${EXAMPLE_DIR}/var/export.json"
