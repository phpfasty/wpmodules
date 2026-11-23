<?php

/**
 * Tracker in wp_head. Consent mode `off`: the install snippet as is. Mode `require`: an inline loader that adds
 * the same tag only after an analytics consent signal (ModulY cookie-consent / wp-fasty `wpfasty:consent`,
 * `sitepulse:consent`, `window.SitePulseConsent = true`, WP Consent API, Cookiebot) — sitepulse.js writes
 * localStorage on load, so it must not even be requested before consent.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

use PhpFasty\Module\SitePulse\Domain\ConsentMode;

final class Tracker
{
    public static function print(): void
    {
        if (is_admin() || is_feed() || is_preview() || is_customize_preview()) {
            return;
        }
        if (!apply_filters('sitepulse_track_request', true)) {
            return;
        }
        $settings = Options::settings();
        $tag = $settings->trackerTag();
        if (null === $tag) {
            return;
        }
        if (ConsentMode::Off === $settings->consentMode) {
            // The install snippet byte for byte (GET /v1/sites/{id}/install); TrackerTag escapes both validated attributes.
            echo $tag->html()."\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in TrackerTag::html().

            return;
        }
        $loader = Assets::read('consent-loader.js');
        if ('' === $loader) {
            return;
        }
        $config = (string) wp_json_encode(['src' => $tag->src, 'site' => $tag->siteKey], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        wp_print_inline_script_tag('window.sitePulseTracker='.$config.";\n".$loader, ['id' => 'sitepulse-consent-loader']);
    }

    public static function privacyText(): void
    {
        if (!function_exists('wp_add_privacy_policy_content') || !Options::settings()->trackerEnabled) {
            return;
        }
        wp_add_privacy_policy_content(
            __('SitePulse', 'moduly'),
            wp_kses_post(__('When analytics is allowed, the SitePulse script records page views, scroll depth, clicks and form interaction metadata (never field values) and sends them to the site owner\'s SitePulse server. It stores a random visitor id in localStorage (sp_vid) and a session id in sessionStorage (sp_sid).', 'moduly'))
        );
    }
}
