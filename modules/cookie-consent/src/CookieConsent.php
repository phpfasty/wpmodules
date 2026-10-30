<?php

/**
 * Cookie consent: the theme renders the banner (`#cookie-banner`, buttons `data-cookie="all|necessary"`,
 * `data-cookie-open` links) when `wpfasty_cookie_consent` says it is enabled; this module ships the script inline.
 * Consent is read server-side only through `analyticsAllowed()` (cookie value `all`).
 *
 * @moduly-request-handler reads the consent cookie.
 * @package ModulY\CookieConsent
 */

declare(strict_types=1);

namespace PhpFasty\Module\CookieConsent;

final class CookieConsent
{
    public const COOKIE = 'wpfasty_cookie_consent';

    private static string $script = '';
    private static int $maxAge = 180;
    private static string $policy = 'cookies';

    /** @param array<string, mixed> $settings */
    public static function configure(array $settings, string $script): void
    {
        self::$maxAge = is_int($settings['max_age_days'] ?? null) ? $settings['max_age_days'] : 180;
        self::$policy = is_string($settings['policy_page'] ?? null) ? $settings['policy_page'] : 'cookies';
        self::$script = $script;
    }

    /**
     * Theme data: `wpfasty_cookie_consent` (array) — enabled flag + policy URL.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function state(array $state): array
    {
        $page = function_exists('get_page_by_path') ? get_page_by_path(self::$policy) : null;
        return array_merge($state, [
            'enabled' => true,
            'cookie' => self::COOKIE,
            'policy_url' => $page ? (string) get_permalink($page) : '',
            'analytics' => self::analyticsAllowed(),
        ]);
    }

    public static function analyticsAllowed(): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a constant only.
        return isset($_COOKIE[self::COOKIE]) && wp_unslash($_COOKIE[self::COOKIE]) === 'all';
    }

    /** Config + script inline in the footer (no file URL needed, no external request). */
    public static function printScript(): void
    {
        if (self::$script === '') {
            return;
        }
        wp_print_inline_script_tag(
            'window.modulyCookieConsent=' . (string) wp_json_encode(['name' => self::COOKIE, 'maxAgeDays' => self::$maxAge]) . ";\n" . self::$script,
            ['id' => 'moduly-cookie-consent']
        );
    }

    public static function privacyText(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content(
                __('Cookie consent', 'moduly'),
                wp_kses_post(sprintf(
                    /* translators: %d: days */
                    __('The site stores your cookie choice (necessary only / all) in the cookie wpfasty_cookie_consent for %d days. Analytics cookies are set only after you accept them.', 'moduly'),
                    self::$maxAge
                ))
            );
        }
    }
}
