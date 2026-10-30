<?php

/**
 * Checkout consents (152-FZ art. 9, 38-FZ art. 18): which ticks exist, validation and the proof record.
 * Reads the checkout form after the orders module verified its nonce:
 * @moduly-request-handler
 *
 * @package ModulY\ConsentLog
 */

declare(strict_types=1);

namespace PhpFasty\Module\ConsentLog;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- runs inside the orders checkout handler after its nonce check.

final class Consents
{
    public const META = '_order_consent_log';

    /** @var array<string, mixed> */
    private static array $settings = [];

    /** @param array<string, mixed> $settings */
    public static function configure(array $settings): void
    {
        self::$settings = $settings;
    }

    public static function version(): string
    {
        $v = self::$settings['legal_version'] ?? '';
        return is_string($v) && $v !== '' ? $v : '2026-10-30';
    }

    /**
     * Consent ticks shown at checkout (`moduly_consents` filter).
     *
     * @return list<array{name: string, required: bool, page: string}>
     */
    public static function definitions(): array
    {
        $page = static fn (string $k, string $d): string => is_string(self::$settings[$k] ?? null) ? (string) self::$settings[$k] : $d;
        $defs = [
            ['name' => 'consent_offer', 'required' => true, 'page' => $page('offer_page', 'oferta')],
            ['name' => 'consent_pd', 'required' => true, 'page' => $page('personal_data_page', 'consent')],
            ['name' => 'consent_news', 'required' => false, 'page' => $page('newsletter_page', 'newsletter-consent')],
        ];
        return function_exists('apply_filters') ? array_values((array) apply_filters('moduly_consents', $defs)) : $defs;
    }

    /**
     * Missing required ticks → error codes (named after the tick).
     *
     * @param list<string> $errors
     * @param array<string, bool> $ticked
     * @return list<string>
     */
    public static function validate(array $errors, array $ticked): array
    {
        foreach (self::definitions() as $d) {
            if ($d['required'] && empty($ticked[$d['name']])) {
                $errors[] = $d['name'];
            }
        }
        return $errors;
    }

    /** @return array<string, bool> */
    public static function ticked(): array
    {
        $out = [];
        foreach (self::definitions() as $d) {
            $out[$d['name']] = isset($_POST[$d['name']]);
        }
        return $out;
    }

    /**
     * Proof record stored on the order.
     *
     * @param array<string, bool> $ticked
     * @return array<string, mixed>
     */
    public static function record(array $ticked, string $ip, string $time): array
    {
        return [
            'time' => $time,
            'ip' => $ip,
            'version' => self::version(),
            'offer' => !empty($ticked['consent_offer']),
            'personal_data' => !empty($ticked['consent_pd']),
            'newsletter' => !empty($ticked['consent_news']),
        ];
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    public static function onValidate(array $errors): array
    {
        return self::validate($errors, self::ticked());
    }

    public static function onOrderCreated(int $orderId): void
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        update_post_meta($orderId, self::META, self::record(self::ticked(), $ip, gmdate('c')));
    }

    /**
     * Checkout view-model: consent ticks with document links (theme renders them).
     *
     * @param array<string, mixed> $checkout
     * @return array<string, mixed>
     */
    public static function checkout(array $checkout): array
    {
        $checkout['consents'] = array_map(static function (array $d): array {
            $page = get_page_by_path($d['page']);
            return $d + ['url' => $page ? (string) get_permalink($page) : ''];
        }, self::definitions());
        $checkout['consent_version'] = self::version();
        return $checkout;
    }
}
