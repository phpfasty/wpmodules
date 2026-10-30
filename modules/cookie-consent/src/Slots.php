<?php

/**
 * Cookie banner for the shop templates (slot footer): hidden until the script finds no consent cookie.
 *
 * @package ModulY\CookieConsent
 */

declare(strict_types=1);

namespace PhpFasty\Module\CookieConsent;

final class Slots
{
    public static function t(string $en, string $ru): string
    {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : 'en_US';
        return str_starts_with($locale, 'ru') ? $ru : $en;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @param array<string, mixed> $context
     * @return array<string, list<array<string, mixed>>>
     */
    public static function slots(array $slots, array $context): array
    {
        $state = CookieConsent::state([]);
        $slots['footer'][] = [
            'view' => 'moduly/cookie-consent/banner',
            'data' => [
                'policy_url' => (string) ($state['policy_url'] ?? ''),
                'labels' => [
                    'text' => self::t(
                        'We use necessary cookies to run the store and, with your consent, analytics cookies.',
                        'Мы используем необходимые cookie для работы магазина и, с вашего согласия, аналитические.'
                    ),
                    'accept' => self::t('Accept all', 'Принять все'),
                    'necessary' => self::t('Necessary only', 'Только необходимые'),
                    'policy' => self::t('Cookie policy', 'Политика cookie'),
                    'settings' => self::t('Cookie settings', 'Настройки cookie'),
                ],
            ],
        ];
        return $slots;
    }
}
