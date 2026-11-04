#!/usr/bin/env bash
# N+1 guard (CI job `wordpress`): the number of SQL queries of the key pages must not grow with the number of
# products listed / lines in the cart. Each page is rendered with few and with many items; the difference must stay
# within a small constant budget (object cache off, so every lookup is a real query).
# Usage (cwd = WordPress root, latte-shop + ModulY cart/orders/types enabled, site served at $1):
#   query-budget.sh http://127.0.0.1:8080 [budget=3]
set -euo pipefail
URL="$1"; BUDGET="${2:-3}"
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/query-count.php <<'PHP'
<?php
// test only: query count of the request as the last line of the HTML
add_action('shutdown', static function (): void {
    if ('cli' !== PHP_SAPI && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST) && !is_admin()) {
        echo "\n<!-- moduly-queries:", (int) get_num_queries(), " -->";
    }
}, PHP_INT_MAX);
PHP
seed() { # $1 = how many products to add
  for i in $(seq 1 "$1"); do
    local id; id=$(wp post create --post_type=product --post_status=publish --post_title="Budget $RANDOM$i" --porcelain)
    wp post meta update "$id" _shop_price $((1000 + i)) >/dev/null
    wp post meta update "$id" _shop_stock 50 >/dev/null
  done
}
count() { # $1 = path, $2 = cookie jar (optional)
  local body; body=$(curl -fsSL ${2:+-b "$2" -c "$2"} "$URL$1")
  echo "$body" | sed -n 's/.*<!-- moduly-queries:\([0-9]*\) -->.*/\1/p' | tail -1
}
check() { # $1 label, $2 few, $3 many
  local d=$(( $3 - $2 ))
  if [ -z "$2" ] || [ -z "$3" ]; then echo "FAIL $1: no query count in the page"; exit 1; fi
  if [ "$d" -gt "$BUDGET" ]; then echo "FAIL $1: $2 → $3 queries (+$d > budget $BUDGET): N+1"; exit 1; fi
  echo "ok   $1: $2 → $3 queries (+$d, budget $BUDGET)"
}
add() { # $1 = product id, $2 = jar: add to cart through the product form (nonce from the page)
  local page nonce
  page=$(curl -fsSL -b "$2" -c "$2" "$URL/?p=$1&post_type=product")
  nonce=$(echo "$page" | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
  curl -fsS -o /dev/null -b "$2" -c "$2" -d "action=moduly_cart&op=add&product_id=$1&qty=1&_wpnonce=$nonce" "$URL/wp-admin/admin-post.php"
}

wp post delete $(wp post list --post_type=product --format=ids) --force >/dev/null 2>&1 || true
seed 2
wp cache flush >/dev/null
count /shop/ >/dev/null   # warm (rewrite, options)
SHOP_FEW=$(count /shop/); PRODUCT_FEW=$(count "/?p=$(wp post list --post_type=product --format=ids | cut -d' ' -f1)&post_type=product")
seed 10
SHOP_MANY=$(count /shop/)
check "/shop/ 2 → 12 products" "$SHOP_FEW" "$SHOP_MANY"
PRODUCT_MANY=$(count "/?p=$(wp post list --post_type=product --format=ids | cut -d' ' -f1)&post_type=product")
check "product page (12 products in the store)" "$PRODUCT_FEW" "$PRODUCT_MANY"

J1=$(mktemp); J6=$(mktemp)
IDS=($(wp post list --post_type=product --format=ids))
add "${IDS[0]}" "$J1"
for id in "${IDS[@]:0:6}"; do add "$id" "$J6"; done
CART_FEW=$(count /cart/ "$J1"); CART_MANY=$(count /cart/ "$J6")
grep -q . <<<"$CART_MANY"
check "/cart/ 1 → 6 lines" "$CART_FEW" "$CART_MANY"
CO_FEW=$(count /checkout/ "$J1"); CO_MANY=$(count /checkout/ "$J6")
check "/checkout/ 1 → 6 lines" "$CO_FEW" "$CO_MANY"
rm -f "$J1" "$J6" wp-content/mu-plugins/query-count.php
echo "query budget: PASS"
