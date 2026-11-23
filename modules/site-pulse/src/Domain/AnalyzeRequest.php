<?php

/**
 * Body of `POST /v1/analyze` (diagd statelessAnalyze): `{html, title, description, keywords}`; the text is not stored.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class AnalyzeRequest
{
    public const MAX_HTML_BYTES = 1048576;
    public const MAX_TITLE = 300;
    public const MAX_DESCRIPTION = 1000;

    /** @param list<string> $keywords */
    private function __construct(
        public readonly string $html,
        public readonly string $title,
        public readonly string $description,
        public readonly array $keywords,
    ) {
    }

    public static function create(string $html, string $title, string $description, mixed $keywords): self
    {
        if ('' === trim($html)) {
            throw new InvalidInputException('sitepulse_empty_content', 'There is no content to analyze yet.');
        }
        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new InvalidInputException('sitepulse_content_too_large', 'The content is larger than 1 MiB.');
        }

        return new self(
            $html,
            mb_substr(trim($title), 0, self::MAX_TITLE),
            mb_substr(trim($description), 0, self::MAX_DESCRIPTION),
            Keywords::normalize($keywords),
        );
    }

    /** @return array{html: string, title: string, description: string, keywords: list<string>} */
    public function toPayload(): array
    {
        return ['html' => $this->html, 'title' => $this->title, 'description' => $this->description, 'keywords' => $this->keywords];
    }
}
