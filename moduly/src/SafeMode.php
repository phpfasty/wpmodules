<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

/**
 * Global safe mode: no module is loaded. MODULY_SAFE_MODE (constant or env, preferred), or the request header
 * `X-Moduly-Safe: <MODULY_SAFE_TOKEN>` (token length ≥ 16). Query-string tokens are not accepted (they land in
 * access logs and browser history). Responses in token mode send `Referrer-Policy: no-referrer`.
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
        $given = '';
        if (isset($_SERVER['HTTP_X_MODULY_SAFE']) && is_string($_SERVER['HTTP_X_MODULY_SAFE'])) {
            $given = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_MODULY_SAFE']));
        }
        $active = is_string($token) && strlen($token) >= 16 && '' !== $given && hash_equals($token, $given);
        if ($active && !headers_sent()) {
            header('Referrer-Policy: no-referrer');
        }

        return $active;
    }
}
