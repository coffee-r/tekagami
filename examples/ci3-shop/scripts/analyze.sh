#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
EXAMPLE_DIR="${ROOT_DIR}/examples/ci3-shop"

php "${EXAMPLE_DIR}/scripts/analyze.php" \
  "${EXAMPLE_DIR}/var/tekagami.jsonl" \
  "${EXAMPLE_DIR}/var/report.json" \
  "${EXAMPLE_DIR}/var/flow-map.tsv" \
  > "${EXAMPLE_DIR}/var/analysis.md"

echo "wrote ${EXAMPLE_DIR}/var/analysis.md"
