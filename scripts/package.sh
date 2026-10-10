#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(jq -r '.version' config.json)"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid config.json version: $VERSION" >&2
  exit 1
fi

# Generated assets are intentionally ignored by Git. Include them explicitly:
# git archive HEAD only captures tracked files and would silently omit admin/dist.
for file in index.html app.js styles.css; do
  if [[ ! -f "admin/dist/$file" ]]; then
    echo "Missing generated Admin asset: admin/dist/$file (run scripts/build-admin.sh)" >&2
    exit 1
  fi
done

STAGE="$ROOT/.package"
OUT="$ROOT/dist"
ZIP="$OUT/TXBoard-AccessAudit-v${VERSION}.zip"

rm -rf "$STAGE" "$OUT"
mkdir -p "$STAGE" "$OUT"

git archive --format=tar --prefix=AccessAudit/ HEAD | tar -xf - -C "$STAGE"

rm -rf "$STAGE/AccessAudit/.github" \
       "$STAGE/AccessAudit/scripts" \
       "$STAGE/AccessAudit/.gitignore" \
       "$STAGE/AccessAudit/admin/src"
mkdir -p "$STAGE/AccessAudit/admin/dist"
cp admin/dist/index.html admin/dist/app.js admin/dist/styles.css "$STAGE/AccessAudit/admin/dist/"

(
  cd "$STAGE"
  zip -qr "$ZIP" AccessAudit
)

rm -rf "$STAGE"
echo "$ZIP"
