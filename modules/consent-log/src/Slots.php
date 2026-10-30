<?php

/**
 * Checkout consent ticks for the shop templates (slot checkout.consents).
 *
 * @package ModulY\ConsentLog
 */

declare(strict_types=1);

namespace PhpFasty\Module\ConsentLog;

final class Slots
{
    public static function t(string $en, string $ru): string
    {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : 'en_US';
        return str_starts_with($locale, 'ru') ? $ru : $en;
    }

    public static function label(string $name): string
    {
        return match ($name) {
            'consent_offer' => self::t('I accept the public offer and license agreement', 'Принимаю условия публичной оферты и лицензионного договора'),
            'consent_pd' => self::t('I consent to the processing of my personal data', 'Даю согласие на обработку персональных данных'),
            'consent_news' => self::t('I want to receive news and offers by email', 'Хочу получать новости и предложения по email'),
            default => $name,
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array{name: string, id: string, label: string, url: string, link: string, required: bool, error: bool}>
     */
    public static function items(array $context): array
    {
        $checkout = (array) ($context['checkout'] ?? []);
        $errors = array_map('strval', (array) ($checkout['errors'] ?? []));
        $out = [];
        foreach ((array) ($checkout['consents'] ?? []) as $c) {
            $name = (string) ($c['name'] ?? '');
            if (preg_match('/^[a-z0-9_]{1,40}$/', $name) !== 1) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'id' => 'co-' . str_replace('_', '-', $name),
                'label' => self::label($name),
                'url' => (string) ($c['url'] ?? ''),
                'link' => self::t('document', 'документ'),
                'required' => !empty($c['required']),
                'error' => in_array($name, $errors, true),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @param array<string, mixed> $context
     * @return array<string, list<array<string, mixed>>>
     */
    public static function slots(array $slots, array $context): array
    {
        $items = self::items($context);
        if ($items !== []) {
            $slots['checkout.consents'][] = ['view' => 'moduly/consent-log/consents', 'data' => ['items' => $items]];
        }
        return $slots;
    }
}
