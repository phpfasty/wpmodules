#!/usr/bin/env bash
# Vendor the ModulY core (phpfasty/moduly) into moduly/lib/core at a tag or commit:
#   tools/sync-core.sh core-v0.1.0 [path-to-moduly-checkout]
# Copies src/, schema/ and LICENSE; records the ref and commit in lib/core/VERSION. CI checks the copy is pristine.
set -euo pipefail
REF="${1:?usage: tools/sync-core.sh <tag|commit> [moduly checkout]}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${2:-}"
TMP=""
if [ -z "$SRC" ]; then
  TMP="$(mktemp -d)"
  git clone -q https://github.com/phpfasty/moduly.git "$TMP/moduly"
  SRC="$TMP/moduly"
fi
COMMIT="$(git -C "$SRC" rev-parse "$REF^{commit}")"
DEST="$ROOT/moduly/lib/core"
rm -rf "$DEST" && mkdir -p "$DEST"
git -C "$SRC" archive "$COMMIT" src schema LICENSE | tar -x -C "$DEST"
printf 'ref=%s\ncommit=%s\n' "$REF" "$COMMIT" > "$DEST/VERSION"
[ -n "$TMP" ] && rm -rf "$TMP"
echo "core $REF ($COMMIT) → moduly/lib/core"
