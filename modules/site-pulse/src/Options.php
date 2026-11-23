<?php

/**
 * Option storage: `sitepulse_settings` (array) and `sitepulse_secret` (string, not autoloaded, never sent to the
 * browser or exposed in REST). Builds the configured client for the proxy.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

use PhpFasty\Module\SitePulse\Domain\ApiKey;
use PhpFasty\Module\SitePulse\Domain\NotConfiguredException;
use PhpFasty\Module\SitePulse\Domain\Settings;
use PhpFasty\Module\SitePulse\Domain\SitePulseService;

final class Options
{
    public const SETTINGS = 'sitepulse_settings';
    public const SECRET = 'sitepulse_secret';

    private static int $timeout = 15;

    public static function configure(int $timeout): void
    {
        self::$timeout = $timeout;
    }

    public static function settings(): Settings
    {
        return Settings::fromStored(get_option(self::SETTINGS, Settings::DEFAULTS));
    }

    public static function secret(): string
    {
        $secret = get_option(self::SECRET, '');

        return is_string($secret) && ApiKey::isValid(ApiKey::SECRET, $secret) ? $secret : '';
    }

    public static function configured(): bool
    {
        return null !== self::settings()->server() && '' !== self::secret();
    }

    public static function service(): SitePulseService
    {
        $server = self::settings()->server();
        $secret = self::secret();
        if (null === $server || '' === $secret) {
            throw new NotConfiguredException();
        }

        return new SitePulseService(new WpHttpClient($server, $secret, self::$timeout));
    }

    public static function ensureSecretOption(): void
    {
        if (false === get_option(self::SECRET, false)) {
            add_option(self::SECRET, '', '', false);
        } else {
            wp_set_option_autoload(self::SECRET, false);
        }
    }

    public static function secretAutoload(mixed $autoload, mixed $option): mixed
    {
        return self::SECRET === $option ? false : $autoload;
    }
}
