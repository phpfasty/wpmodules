<?php

/**
 * Port: text cleaning and id checks the field rules need from the host (WordPress adapter: Types\WpSanitizer).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types\Domain;

interface SanitizerInterface
{
    /** One line of plain text (no tags, no line breaks). */
    public function text(string $raw): string;

    /** Plain text, line breaks kept. */
    public function textarea(string $raw): string;

    public function email(string $raw): string;

    /** A safe absolute URL or ''. */
    public function url(string $raw): string;

    public function stripTags(string $raw): string;

    public function attachmentExists(int $id): bool;

    public function userExists(int $id): bool;
}
