#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/admin/src"
OUT="$ROOT/admin/dist"

for file in index.html app.js styles.css; do
  if [[ ! -f "$SRC/$file" ]]; then
    echo "Missing AccessAudit Admin source: $SRC/$file" >&2
    exit 1
  fi
done

# The Admin UI is a dependency-free static application. Keep source files
# tracked and generate the distributable directory during CI and release.
rm -rf "$OUT"
mkdir -p "$OUT"
cp "$SRC/index.html" "$SRC/app.js" "$SRC/styles.css" "$OUT/"
node --check "$OUT/app.js"
echo "Built AccessAudit Admin static app at $OUT"
