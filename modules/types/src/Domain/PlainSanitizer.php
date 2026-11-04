<?php

/**
 * Pure-PHP Sanitizer (default outside WordPress: unit tests, CLI tools). Ids are not looked up (any positive id).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types\Domain;

final class PlainSanitizer implements SanitizerInterface
{
    public function text(string $raw): string
    {
        return trim((string) preg_replace('/[\r\n\t ]+/', ' ', $this->stripTags($raw)));
    }

    public function textarea(string $raw): string
    {
        return trim($this->stripTags($raw));
    }

    public function email(string $raw): string
    {
        return (string) filter_var($raw, FILTER_SANITIZE_EMAIL);
    }

    public function url(string $raw): string
    {
        return false !== filter_var($raw, FILTER_VALIDATE_URL) && 1 === preg_match('#^https?://#i', $raw) ? $raw : '';
    }

    public function stripTags(string $raw): string
    {
        return trim(strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $raw)));
    }

    public function attachmentExists(int $id): bool
    {
        return $id > 0;
    }

    public function userExists(int $id): bool
    {
        return $id > 0;
    }
}
