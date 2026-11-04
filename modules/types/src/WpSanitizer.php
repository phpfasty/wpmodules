<?php

/**
 * WordPress adapter of the types Sanitizer port (sanitize_* / esc_url_raw / attachment and user lookups).
 * Falls back to the pure-PHP rules where a function is not loaded (early bootstrap, unit tests).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types;

use PhpFasty\Module\Types\Domain\PlainSanitizer;
use PhpFasty\Module\Types\Domain\SanitizerInterface;

final class WpSanitizer implements SanitizerInterface
{
    private readonly PlainSanitizer $plain;

    public function __construct()
    {
        $this->plain = new PlainSanitizer();
    }

    public function text(string $raw): string
    {
        return function_exists('sanitize_text_field') ? sanitize_text_field($raw) : $this->stripTags($raw);
    }

    public function textarea(string $raw): string
    {
        return function_exists('sanitize_textarea_field') ? sanitize_textarea_field($raw) : $this->stripTags($raw);
    }

    public function email(string $raw): string
    {
        return function_exists('sanitize_email') ? sanitize_email($raw) : $this->plain->email($raw);
    }

    public function url(string $raw): string
    {
        return function_exists('esc_url_raw') ? esc_url_raw($raw) : $this->plain->url($raw);
    }

    public function stripTags(string $raw): string
    {
        return function_exists('wp_strip_all_tags') ? wp_strip_all_tags($raw) : $this->plain->stripTags($raw);
    }

    public function attachmentExists(int $id): bool
    {
        return $id > 0 && (!function_exists('get_post_type') || 'attachment' === get_post_type($id));
    }

    public function userExists(int $id): bool
    {
        return $id > 0 && (!function_exists('get_userdata') || false !== get_userdata($id));
    }
}
