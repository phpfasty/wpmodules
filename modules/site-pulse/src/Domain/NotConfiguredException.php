<?php

/**
 * The server URL or the secret key is not set yet (HTTP 503 from the plugin's REST routes).
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class NotConfiguredException extends SitePulseException
{
    public function __construct(string $message = 'SitePulse is not configured: set the server URL and the secret key in Settings > SitePulse.')
    {
        parent::__construct('sitepulse_not_configured', $message, 503);
    }
}
