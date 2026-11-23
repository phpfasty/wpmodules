<?php

/**
 * Deployment presets: each SitePulse server has two origins — the studio/API (PHP proxy, `sk_live_`) and the
 * tracker/ingest host (`/sitepulse.js` and `POST /v1/ingest`, `pk_live_`). `custom` takes both URLs from the form
 * (e.g. a local `diagd --demo` on http://localhost:8080 for both).
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

enum Region: string
{
    case Fra = 'fra';
    case Ru = 'ru';
    case Custom = 'custom';

    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Custom) : self::Custom;
    }

    /** Studio/API base URL of the preset; empty for custom. */
    public function apiUrl(): string
    {
        return match ($this) {
            self::Fra => 'https://pulse.yself.link',
            self::Ru => 'https://pulse.yself.ru.net',
            self::Custom => '',
        };
    }

    /** Tracker/ingest base URL of the preset; empty for custom. */
    public function trackerUrl(): string
    {
        return match ($this) {
            self::Fra => 'https://events.yself.link',
            self::Ru => 'https://events.yself.ru.net',
            self::Custom => '',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fra => 'FRA (EU)',
            self::Ru => 'RU',
            self::Custom => 'Custom',
        };
    }

    /** EU: analytics only after consent (GDPR / ePrivacy). */
    public function requiresConsent(): bool
    {
        return self::Fra === $this;
    }
}
