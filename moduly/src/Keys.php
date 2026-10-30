<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Signature\Keyring;

/**
 * Trusted publisher keys: the built-in PHP FastY key + MODULY_TRUSTED_KEYS ("publisher:base64,…") from wp-config.
 * Unsigned modules only with MODULY_ALLOW_UNSIGNED on a local/development environment.
 */
final class Keys
{
    /** ed25519 public key of publisher "phpfasty" (modules built by phpfasty/wpmodules CI). */
    public const PHPFASTY = 'TyPZk9xKSLd2UCK+1tUnRsNYiaHxp5Cf6zo7tUsYEgM=';

    public static function keyring(): Keyring
    {
        $trusted = ['phpfasty' => [self::PHPFASTY]];
        foreach (self::pairs('MODULY_TRUSTED_KEYS') as [$publisher, $key]) {
            $trusted[$publisher][] = $key;
        }
        $revoked = array_map(static fn (array $p): string => $p[1], self::pairs('MODULY_REVOKED_KEYS'));
        return new Keyring($trusted, $revoked, self::allowUnsigned());
    }

    public static function allowUnsigned(): bool
    {
        return defined('MODULY_ALLOW_UNSIGNED') && constant('MODULY_ALLOW_UNSIGNED') === true
            && in_array(wp_get_environment_type(), ['local', 'development'], true);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function pairs(string $constant): array
    {
        $raw = defined($constant) ? constant($constant) : getenv($constant);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $item) {
            $parts = explode(':', trim($item), 2);
            if (count($parts) === 2 && preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $parts[0]) === 1 && preg_match('#^[A-Za-z0-9+/]{43}=$#', $parts[1]) === 1) {
                $out[] = [$parts[0], $parts[1]];
            }
        }
        return $out;
    }
}
