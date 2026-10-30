<?php

/**
 * Money helpers — integer minor units (cents) everywhere: product meta, cart, orders; formatted for display.
 *
 * @package ModulY\Cart
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

final class Money
{
    public static function toCents(string $amount): int
    {
        $amount = trim(str_replace(',', '.', $amount));
        return $amount === '' || !is_numeric($amount) ? 0 : (int) round(((float) $amount) * 100);
    }

    /** Store currency, ISO 4217 (option `wpfasty_currency`; default USD). */
    public static function currency(): string
    {
        $code = strtoupper((string) (function_exists('get_option') ? get_option('wpfasty_currency', 'USD') : 'USD'));
        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : 'USD';
    }

    /** Stored money value (integer minor units, possibly a numeric string from post meta) → int cents. */
    public static function cents(mixed $stored): int
    {
        return is_numeric($stored) ? max(0, (int) round((float) $stored)) : 0;
    }

    public static function format(int $cents): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'RUB' => '₽', 'JPY' => '¥'];
        $currency = self::currency();
        $fallback = $symbols[$currency] ?? $currency . ' ';
        $symbol = function_exists('apply_filters') ? (string) apply_filters('latte_shop_currency_symbol', $fallback, $currency) : $fallback;
        $value = function_exists('number_format_i18n')
            ? number_format_i18n($cents / 100, 2)
            : number_format($cents / 100, 2);
        // number_format_i18n() may return &nbsp; entities; views escape, so hand back plain characters.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Ruble and euro follow the number (ГОСТ style: 4 900,00 ₽).
        return in_array($currency, ['RUB', 'EUR'], true) ? $value . "\u{00A0}" . $symbol : $symbol . $value;
    }
}
