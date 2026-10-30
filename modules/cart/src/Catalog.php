<?php

/**
 * Pricing and stock of any post type with the `purchasable` capability, read through the types registry
 * that the theme / types layer announces on `wpfasty_types_loaded` (no direct dependency on theme classes).
 *
 * @package ModulY\Cart
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

final class Catalog
{
    private static ?object $registry = null;

    public static function setRegistry(object $registry): void
    {
        self::$registry = $registry;
    }

    /** @return array<string, mixed> */
    private static function capability(int $id): array
    {
        $type = self::$registry !== null && method_exists(self::$registry, 'get') ? self::$registry->get((string) get_post_type($id)) : null;
        if (!is_object($type) || !method_exists($type, 'capability')) {
            return [];
        }
        $cap = $type->capability('purchasable');
        return is_array($cap) ? $cap : [];
    }

    private static function fieldValue(int $id, string $option): string
    {
        $field = (string) (self::capability($id)[$option] ?? '');
        if ($field === '' || self::$registry === null || !method_exists(self::$registry, 'value')) {
            return '';
        }
        return (string) self::$registry->value($id, $field);
    }

    public static function isPurchasable(int $id): bool
    {
        $p = get_post($id);
        return $p instanceof \WP_Post
            && self::capability($id) !== []
            && $p->post_status === 'publish'
            && self::price($id) > 0
            && (self::stock($id) === null || self::stock($id) > 0);
    }

    /** Downloadable product: `purchasable.digital` => true on its post type. */
    public static function isDigital(int $id): bool
    {
        return (self::capability($id)['digital'] ?? false) === true;
    }

    /**
     * License tiers of a product (`purchasable.license_field`, a types `license_tiers` field: JSON, prices in cents).
     *
     * @return list<array{tier: string, label: string, price: int}>
     */
    public static function tiers(int $id): array
    {
        $raw = self::fieldValue($id, 'license_field');
        $rows = $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $tier = is_array($row) ? (string) ($row['tier'] ?? '') : '';
            $price = is_array($row) && is_numeric($row['price'] ?? null) ? (int) $row['price'] : 0;
            if (preg_match('/^[a-z0-9_-]{1,32}$/', $tier) === 1 && $price > 0) {
                $out[] = ['tier' => $tier, 'label' => (string) ($row['label'] ?? $tier), 'price' => $price];
            }
        }
        return $out;
    }

    /**
     * The tier a cart line is sold under: '' for products without tiers, the first (default) tier when none was
     * chosen, the requested one when the product sells it; null when it does not (tampered request).
     */
    public static function tier(int $id, string $requested): ?string
    {
        $tiers = array_column(self::tiers($id), 'tier');
        if ($tiers === []) {
            return $requested === '' ? '' : null;
        }
        if ($requested === '') {
            return $tiers[0];
        }
        return in_array($requested, $tiers, true) ? $requested : null;
    }

    public static function tierLabel(int $id, string $tier): string
    {
        foreach (self::tiers($id) as $t) {
            if ($t['tier'] === $tier) {
                return $t['label'];
            }
        }
        return '';
    }

    /** Effective unit price in cents (sale wins when lower); a non-default license tier has its own price. */
    public static function price(int $id, string $tier = ''): int
    {
        $tiers = self::tiers($id);
        if ($tier !== '' && $tiers !== [] && $tier !== $tiers[0]['tier']) {
            foreach ($tiers as $t) {
                if ($t['tier'] === $tier) {
                    return $t['price'];
                }
            }
            return 0;
        }
        $regular = Money::cents(self::fieldValue($id, 'price_field'));
        $sale = Money::cents(self::fieldValue($id, 'sale_field'));
        return $sale > 0 && $sale < $regular ? $sale : $regular;
    }

    public static function regularPrice(int $id): int
    {
        return Money::cents(self::fieldValue($id, 'price_field'));
    }

    public static function stock(int $id): ?int
    {
        $raw = self::fieldValue($id, 'stock_field');
        return $raw === '' ? null : max(0, (int) $raw);
    }

    public static function setStock(int $id, int $stock): void
    {
        $field = (string) (self::capability($id)['stock_field'] ?? '');
        $type = self::$registry !== null && method_exists(self::$registry, 'get') ? self::$registry->get((string) get_post_type($id)) : null;
        $fields = is_object($type) && property_exists($type, 'fields') && is_array($type->fields) ? $type->fields : [];
        $def = $fields[$field] ?? null;
        if ($field !== '' && is_object($def) && property_exists($def, 'metaKey') && is_string($def->metaKey)) {
            update_post_meta($id, $def->metaKey, max(0, $stock));
        }
    }

    /**
     * Product view-model for the theme (`wpfasty_shop_product`). Keys match the theme views.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function product(array $product, int $id): array
    {
        $price = self::price($id);
        $regular = self::regularPrice($id);
        $stock = self::stock($id);
        return array_merge($product, [
            'price' => Money::format($price),
            'regular' => $regular > $price ? Money::format($regular) : '',
            'on_sale' => $regular > $price,
            'stock' => $stock,
            'in_stock' => $stock === null || $stock > 0,
            'purchasable' => self::isPurchasable($id),
            'price_cents' => $price,
            'licenses' => array_map(static fn (array $t): array => [
                'tier' => $t['tier'],
                'label' => $t['label'],
                'price' => Money::format(self::price($id, $t['tier'])),
                'price_cents' => self::price($id, $t['tier']),
            ], self::tiers($id)),
        ]);
    }
}
