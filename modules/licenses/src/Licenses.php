<?php

/**
 * License tiers: product field, picker view-model and the tier comparison (what each license allows).
 *
 * @package ModulY\Licenses
 */

declare(strict_types=1);

namespace PhpFasty\Module\Licenses;

final class Licenses
{
    private static string $field = 'licenses';
    private static string $termsPage = 'oferta';

    /** @param array<string, mixed> $settings */
    public static function configure(array $settings): void
    {
        self::$field = (string) ($settings['field'] ?? 'licenses');
        self::$termsPage = (string) ($settings['terms_page'] ?? 'oferta');
    }

    public static function field(): string
    {
        return self::$field;
    }

    /** Russian or English label (no gettext catalog needed in a module zip). */
    public static function t(string $en, string $ru): string
    {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : 'en_US';
        return str_starts_with($locale, 'ru') ? $ru : $en;
    }

    /**
     * What a tier allows; known ids get a description, others only their label.
     *
     * @return list<string>
     */
    public static function terms(string $tier): array
    {
        return match ($tier) {
            'regular' => [
                self::t('One end product (one site)', 'Один конечный продукт (один сайт)'),
                self::t('End users are not charged', 'Конечные пользователи не платят'),
                self::t('Updates and 6 months of support', 'Обновления и 6 месяцев поддержки'),
            ],
            'extended' => [
                self::t('One end product (one site)', 'Один конечный продукт (один сайт)'),
                self::t('End users may be charged (SaaS, paid access)', 'Можно брать плату с пользователей (SaaS, платный доступ)'),
                self::t('Updates and 12 months of support', 'Обновления и 12 месяцев поддержки'),
            ],
            default => [],
        };
    }

    /** One-line summary of a tier for the picker. */
    public static function summary(string $tier): string
    {
        return match ($tier) {
            'regular' => self::t('One site, end users are not charged.', 'Один сайт, пользователи не платят.'),
            'extended' => self::t('One site, end users may be charged.', 'Один сайт, можно брать плату с пользователей.'),
            default => '',
        };
    }

    /**
     * Product field declared on the latte-shop product type.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function fields(array $fields): array
    {
        $fields[self::$field] ??= [
            'type' => 'license_tiers',
            'label' => self::t('License tiers (first = default)', 'Лицензии (первая — по умолчанию)'),
        ];
        return $fields;
    }

    /**
     * Tiers of a product view-model (`product.licenses` from the cart module), normalised for the views.
     *
     * @param array<string, mixed> $product
     * @return list<array{id: string, label: string, summary: string, price: string, terms: list<string>, checked: bool}>
     */
    public static function tiers(array $product): array
    {
        $out = [];
        foreach ((array) ($product['licenses'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['tier'] ?? $row['id'] ?? '');
            if (preg_match('/^[a-z0-9_-]{1,32}$/', $id) !== 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'label' => self::label($id, (string) ($row['label'] ?? $id)),
                'summary' => self::summary($id),
                'price' => (string) ($row['price'] ?? ''),
                'terms' => self::terms($id),
                'checked' => $out === [],
            ];
        }
        return $out;
    }

    private static function label(string $id, string $fallback): string
    {
        return match ($id) {
            'regular' => self::t('Regular license', 'Обычная лицензия'),
            'extended' => self::t('Extended license', 'Расширенная лицензия'),
            default => $fallback,
        };
    }

    /**
     * Extension points of the shop templates: picker inside the add-to-cart form, comparison below the product.
     *
     * @param array<string, list<array<string, mixed>>> $slots
     * @param array<string, mixed> $context
     * @return array<string, list<array<string, mixed>>>
     */
    public static function slots(array $slots, array $context): array
    {
        $page = (array) ($context['page'] ?? []);
        $product = (array) ($page['product'] ?? []);
        $tiers = self::tiers($product);
        if ($tiers === []) {
            return $slots;
        }
        $labels = [
            'license' => self::t('License', 'Лицензия'),
            'compare' => self::t('What the licenses allow', 'Что разрешают лицензии'),
            'terms' => self::t('Full license terms', 'Полные условия лицензии'),
        ];
        $termsUrl = '';
        if (function_exists('get_page_by_path')) {
            $p = get_page_by_path(self::$termsPage);
            $termsUrl = $p ? (string) get_permalink($p) : '';
        }
        if (!empty($product['purchasable'])) {
            $slots['product.purchase'][] = [
                'view' => 'moduly/licenses/picker',
                'data' => ['tiers' => $tiers, 'labels' => $labels],
            ];
        }
        $slots['product.after'][] = [
            'view' => 'moduly/licenses/compare',
            'data' => ['tiers' => $tiers, 'labels' => $labels, 'terms_url' => $termsUrl],
        ];
        return $slots;
    }
}
