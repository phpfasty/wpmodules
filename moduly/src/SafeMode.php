<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

/**
 * Global safe mode: no module is loaded. MODULY_SAFE_MODE (constant or env) or `?moduly_safe=<MODULY_SAFE_TOKEN>`.
 */
final class SafeMode
{
    public static function active(): bool
    {
        if (defined('MODULY_SAFE_MODE') && constant('MODULY_SAFE_MODE')) {
            return true;
        }
        if (in_array(getenv('MODULY_SAFE_MODE'), ['1', 'true'], true)) {
            return true;
        }
        $token = defined('MODULY_SAFE_TOKEN') ? constant('MODULY_SAFE_TOKEN') : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- secret token comparison, no state change.
        $given = isset($_GET['moduly_safe']) ? sanitize_text_field(wp_unslash((string) $_GET['moduly_safe'])) : '';
        return is_string($token) && strlen($token) >= 16 && $given !== '' && hash_equals($token, $given);
    }
}
