<?php

/**
 * Settings > SitePulse (manage_options, Settings API + options.php nonce). The stored secret is never printed:
 * the form shows a mask, an empty field keeps it, "Remove" clears it.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

use PhpFasty\Module\SitePulse\Domain\ApiKey;
use PhpFasty\Module\SitePulse\Domain\ConsentMode;
use PhpFasty\Module\SitePulse\Domain\InvalidInputException;
use PhpFasty\Module\SitePulse\Domain\Settings;

final class AdminPage
{
    public const PAGE = 'sitepulse';
    public const GROUP = 'sitepulse';

    public static function menu(): void
    {
        add_options_page(__('SitePulse', 'moduly'), __('SitePulse', 'moduly'), 'manage_options', self::PAGE, [self::class, 'render']);
    }

    public static function registerSettings(): void
    {
        Options::ensureSecretOption();
        register_setting(self::GROUP, Options::SETTINGS, [
            'type' => 'array',
            'show_in_rest' => false,
            'default' => Settings::DEFAULTS,
            'sanitize_callback' => [self::class, 'sanitizeSettings'],
        ]);
        register_setting(self::GROUP, Options::SECRET, [
            'type' => 'string',
            'show_in_rest' => false,
            'default' => '',
            'sanitize_callback' => [self::class, 'sanitizeSecret'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function sanitizeSettings(mixed $input): array
    {
        [$out, $errors] = Settings::sanitize(is_array($input) ? $input : [], Options::settings());
        foreach ($errors as $i => $message) {
            add_settings_error(Options::SETTINGS, 'sitepulse_'.$i, $message);
        }

        return $out;
    }

    /** Form value `['new' => …, 'clear' => '1']`; a plain string is an already sanitized value (add_option re-run). */
    public static function sanitizeSecret(mixed $input): string
    {
        $old = Options::secret();
        if (is_string($input)) {
            return ApiKey::isValid(ApiKey::SECRET, $input) ? $input : $old;
        }
        $input = is_array($input) ? $input : [];
        try {
            return ApiKey::resolveSecret(
                $old,
                is_string($input['new'] ?? null) ? $input['new'] : '',
                '1' === ($input['clear'] ?? null),
            );
        } catch (InvalidInputException $e) {
            add_settings_error(Options::SECRET, $e->errorCode(), $e->getMessage());

            return $old;
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Options::settings();
        $mask = ApiKey::mask(Options::secret());
        $name = static fn (string $field): string => Options::SETTINGS.'['.$field.']';
        $home = (string) wp_parse_url(home_url(), PHP_URL_SCHEME).'://'.(string) wp_parse_url(home_url(), PHP_URL_HOST);
        $port = wp_parse_url(home_url(), PHP_URL_PORT);
        $origin = $home.(is_int($port) ? ':'.$port : '');

        echo '<div class="wrap"><h1>'.esc_html__('SitePulse', 'moduly').'</h1>';
        // Validation notices are printed by WordPress itself on Settings pages (options-head.php).
        echo '<form method="post" action="'.esc_url(admin_url('options.php')).'">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="sitepulse-server">'.esc_html__('Server URL', 'moduly').'</label></th><td>';
        echo '<input type="url" class="regular-text code" id="sitepulse-server" name="'.esc_attr($name('server_url')).'" value="'.esc_attr($s->serverUrl).'" placeholder="https://pulse.example.com">';
        echo '<p class="description">'.esc_html__('SitePulse API (diagd). https only; http is accepted for localhost during development.', 'moduly').'</p></td></tr>';

        echo '<tr><th scope="row"><label for="sitepulse-secret">'.esc_html__('Secret key', 'moduly').'</label></th><td>';
        echo '<input type="password" class="regular-text code" id="sitepulse-secret" name="'.esc_attr(Options::SECRET).'[new]" value="" autocomplete="new-password" spellcheck="false" placeholder="'.esc_attr('' === $mask ? 'sk_live_…' : $mask).'">';
        if ('' !== $mask) {
            echo ' <label><input type="checkbox" name="'.esc_attr(Options::SECRET).'[clear]" value="1"> '.esc_html__('Remove the saved key', 'moduly').'</label>';
            echo '<p class="description">'.esc_html(sprintf(
                /* translators: %s: masked key */
                __('Saved: %s. Leave the field empty to keep it, or paste a new key to replace it.', 'moduly'),
                $mask
            )).'</p>';
        } else {
            echo '<p class="description">'.esc_html__('sk_live_… from the SitePulse studio (site → keys). Stored on the server only; the editor talks to SitePulse through this site.', 'moduly').'</p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="sitepulse-pk">'.esc_html__('Public key', 'moduly').'</label></th><td>';
        echo '<input type="text" class="regular-text code" id="sitepulse-pk" name="'.esc_attr($name('public_key')).'" value="'.esc_attr($s->publicKey).'" placeholder="pk_live_…" spellcheck="false">';
        echo '<p class="description">'.esc_html__('pk_live_… for the tracker (public).', 'moduly').'</p></td></tr>';

        echo '<tr><th scope="row">'.esc_html__('Tracker', 'moduly').'</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr($name('tracker_enabled')).'" value="1"'.checked($s->trackerEnabled, true, false).'> '.esc_html__('Add sitepulse.js to every page (wp_head)', 'moduly').'</label>';
        echo '<p class="description">'.esc_html(sprintf(
            /* translators: %s: site origin */
            __('Add %s to the allowed origins of this site in the SitePulse studio, otherwise events are rejected (403).', 'moduly'),
            $origin
        )).'</p>';
        echo '<p><label for="sitepulse-tracker-url">'.esc_html__('Tracker / ingest URL (optional)', 'moduly').'</label><br>';
        echo '<input type="url" class="regular-text code" id="sitepulse-tracker-url" name="'.esc_attr($name('tracker_url')).'" value="'.esc_attr($s->trackerUrl).'" placeholder="'.esc_attr__('same as the server URL', 'moduly').'"></p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">'.esc_html__('Cookie consent', 'moduly').'</th><td><fieldset>';
        foreach ([
            ConsentMode::WaitForConsent->value => __('Start the tracker only after analytics consent (EU / GDPR). Signals: ModulY cookie-consent, the "sitepulse:consent" event, window.SitePulseConsent = true, WP Consent API, Cookiebot.', 'moduly'),
            ConsentMode::Off->value => __('Start immediately (no consent banner on this site).', 'moduly'),
        ] as $value => $label) {
            echo '<label><input type="radio" name="'.esc_attr($name('consent_mode')).'" value="'.esc_attr($value).'"'.checked($s->consentMode->value, $value, false).'> '.esc_html($label).'</label><br>';
        }
        echo '</fieldset></td></tr>';

        echo '<tr><th scope="row">'.esc_html__('Editor', 'moduly').'</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr($name('auto_analyze')).'" value="1"'.checked($s->autoAnalyze, true, false).'> '.esc_html__('Re-analyze automatically while typing (debounced)', 'moduly').'</label>';
        echo '</td></tr></table>';
        submit_button();
        echo '</form>';

        echo '<h2>'.esc_html__('Connection', 'moduly').'</h2>';
        echo '<p><button type="button" class="button" id="sitepulse-test">'.esc_html__('Test connection', 'moduly').'</button> <span id="sitepulse-test-result" aria-live="polite"></span></p>';
        echo '</div>';

        $script = Assets::read('settings.js');
        if ('' !== $script) {
            wp_enqueue_script('wp-api-fetch');
            wp_add_inline_script('wp-api-fetch', $script);
        }
    }
}
