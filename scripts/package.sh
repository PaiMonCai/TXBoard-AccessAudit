#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(jq -r '.version' config.json)"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid config.json version: $VERSION" >&2
  exit 1
fi

STAGE="$ROOT/.package"
OUT="$ROOT/dist"
ZIP="$OUT/TXBoard-AccessAudit-v${VERSION}.zip"

rm -rf "$STAGE" "$OUT"
mkdir -p "$STAGE" "$OUT"

git archive --format=tar --prefix=AccessAudit/ HEAD | tar -xf - -C "$STAGE"

rm -rf   "$STAGE/AccessAudit/.github"   "$STAGE/AccessAudit/scripts"   "$STAGE/AccessAudit/.gitignore"

(
  cd "$STAGE"
  zip -qr "$ZIP" AccessAudit
)

rm -rf "$STAGE"
echo "$ZIP"
