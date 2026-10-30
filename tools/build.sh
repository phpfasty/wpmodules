#!/usr/bin/env bash
# Build release artifacts into dist/:
#   moduly-<version>.zip         — the WordPress plugin "ModulY" (folder moduly/)
#   modules/<id>-<version>.zip   — every module under modules/, signed (SIGNATURE) with MODULY_SIGNING_KEY
#                                  (base64 ed25519 secret key of publisher "phpfasty"; unset = unsigned, dev only)
#   SHA256SUMS
# Reproducible: sorted entries, fixed mtimes.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
cd "$ROOT"
rm -rf "$DIST" && mkdir -p "$DIST/modules"
VERSION="$(sed -n 's/^ \* Version: *//p' moduly/moduly.php | head -1)"
php tools/moduly.php zipdir moduly "$DIST/moduly-$VERSION.zip"
for dir in modules/*/; do
  id="$(basename "$dir")"
  STAGE="$(mktemp -d)"
  cp -r "$dir" "$STAGE/$id"
  rm -f "$STAGE/$id/SIGNATURE"
  php tools/moduly.php sign "$STAGE/$id"
  v="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$STAGE/$id/module.json")"
  php tools/moduly.php pack "$STAGE/$id" "$DIST/modules/$id-$v.zip"
  rm -rf "$STAGE"
done
(cd "$DIST" && sha256sum moduly-*.zip modules/*.zip > SHA256SUMS)
cat "$DIST/SHA256SUMS"
