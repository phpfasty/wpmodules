#!/usr/bin/env bash
# Integration: module `types` on a real WordPress (CI job `wordpress`, or locally on SQLite).
#   old schema data → enable types → migrate → switch theme → back → disable → enable → prune (dry-run, then --yes)
#   Every original post meta row must survive every step; only rows explicitly pruned with --yes (after a JSON
#   backup) may disappear.
# Usage (cwd = WordPress root, ModulY active): types-roundtrip.sh <dist dir> <dir with latte.zip latte-shop.zip wpfasty-types.zip>
set -euo pipefail
DIST="$(cd "$1" && pwd)"; THEMES="$(cd "$2" && pwd)"
# snapshot via $wpdb (works on MySQL/MariaDB and the SQLite drop-in alike)
snap() {
  wp eval 'global $wpdb; foreach ($wpdb->get_results("SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = \"product\" ORDER BY pm.meta_id", ARRAY_A) as $r) { echo $r["post_id"], "\t", $r["meta_key"], "\t", md5($r["meta_value"]), "\n"; }' > "$1"
}
contains() { # every row of $1 is in $2
  local missing; missing="$(comm -23 <(sort "$1") <(sort "$2") | wc -l)"
  if [ "$missing" != "0" ]; then echo "FAIL $3: $missing original meta row(s) lost"; comm -23 <(sort "$1") <(sort "$2") | head; exit 1; fi
  echo "ok   $3: all $(wc -l < "$1") original meta rows intact"
}
expect() { if [ "$1" != "$2" ]; then echo "FAIL $3: got '$1', want '$2'"; exit 1; fi; echo "ok   $3"; }

wp theme install "$THEMES/latte.zip" --force --quiet
wp theme install "$THEMES/latte-shop.zip" --force --activate --quiet
wp plugin install "$THEMES/wpfasty-types.zip" --force --activate --quiet
# A field that changed key: `price` used to live in `_legacy_price` (declared as alias via the wpfasty_types filter).
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/types-alias.php <<'PHP'
<?php
add_filter('wpfasty_types', static function (array $defs): array {
    foreach ($defs as &$d) {
        if (($d['name'] ?? '') === 'product') { $d['fields']['price']['aliases'] = ['_legacy_price']; }
    }
    return $defs;
});
PHP
expect "$(wp eval 'echo get_class(new WPFasty\Types\Registry());')" 'WPFasty\Types\Registry' "module absent: theme Registry"

# Old-schema data (written while only the theme + plugin were active)
A=$(wp post create --post_type=product --post_status=publish --post_title=A --porcelain)
B=$(wp post create --post_type=product --post_status=publish --post_title=B --porcelain)
C=$(wp post create --post_type=product --post_status=publish --post_title=C --porcelain)
wp post meta add "$A" _shop_price 1500 >/dev/null
wp post meta add "$A" _shop_sku SKU-A >/dev/null
wp post meta add "$B" _legacy_price 2500 >/dev/null     # old key only → backfilled to _shop_price
wp post meta add "$C" _legacy_price 'n/a' >/dev/null    # invalid for money → skipped, read as default
wp post meta add "$C" _shop_obsolete old >/dev/null  # no field claims it → prune candidate
snap /tmp/t0.txt

wp moduly install "$DIST"/modules/types-*.zip --enable
wp eval '' >/dev/null
expect "$(wp eval 'echo get_class(new WPFasty\Types\Registry());')" 'PhpFasty\Module\Types\Model\Registry' "enabled: Registry aliased to the module"
expect "$(wp eval 'echo (int) (get_option("moduly_schema_version")["types"] ?? 0);')" 2 "schema_version types = 2"
expect "$(wp post meta get "$B" _shop_price)" 2500 "alias backfilled"
expect "$(wp eval "\$r = PhpFasty\Module\Types\Data::registry(); echo '[' . WPFasty\Types\Registry::read($C, \$r->get('product')->fields['price']) . ']';")" '[]' "invalid alias value reads as default"
expect "$(wp eval 'echo post_type_exists("product") ? "y" : "n";')" y "product type registered by the module"
snap /tmp/t1.txt; contains /tmp/t0.txt /tmp/t1.txt "after migrate"

wp eval '' >/dev/null   # second run: no-op
snap /tmp/t1b.txt; expect "$(diff /tmp/t1.txt /tmp/t1b.txt && echo same)" same "migrations idempotent"

wp theme activate twentytwentyfive --quiet 2>/dev/null || wp theme install twentytwentyfive --activate --quiet
wp eval '' >/dev/null
snap /tmp/t2.txt; contains /tmp/t0.txt /tmp/t2.txt "after switching to twentytwentyfive"
wp theme activate latte-shop --quiet
snap /tmp/t3.txt; contains /tmp/t0.txt /tmp/t3.txt "after switching back"

wp moduly disable types
expect "$(wp eval 'echo get_class(new WPFasty\Types\Registry());')" 'WPFasty\Types\Registry' "disabled: theme Registry resumes"
expect "$(wp eval 'echo post_type_exists("product") ? "y" : "n";')" y "disabled: plugin still registers product"
snap /tmp/t4.txt; contains /tmp/t0.txt /tmp/t4.txt "after disable"
wp moduly enable types
snap /tmp/t5.txt; contains /tmp/t0.txt /tmp/t5.txt "after re-enable"

wp moduly types prune --dry-run --format=json | tee /tmp/prune.json
grep -q '_shop_obsolete' /tmp/prune.json
! grep -q '_legacy_price\|_shop_price\|_shop_sku' /tmp/prune.json
snap /tmp/t6.txt; expect "$(diff /tmp/t5.txt /tmp/t6.txt && echo same)" same "dry-run deletes nothing"
wp moduly types prune --format=json >/dev/null; snap /tmp/t6b.txt
expect "$(diff /tmp/t5.txt /tmp/t6b.txt && echo same)" same "no --yes: deletes nothing"
wp moduly types prune --yes --format=json
BK=$(ls -t wp-content/moduly/backups/types-prune-*.json | head -1)
grep -q '_shop_obsolete' "$BK"; echo "ok   backup $BK"
expect "$(wp post meta get "$C" _shop_obsolete 2>/dev/null || true)" '' "pruned row removed"
grep -v '_shop_obsolete' /tmp/t0.txt > /tmp/t0k.txt; snap /tmp/t7.txt; contains /tmp/t0k.txt /tmp/t7.txt "after prune --yes (others intact)"
echo "types roundtrip: PASS"
