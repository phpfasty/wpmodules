<?php

/**
 * One analysis finding (`textcheck.Finding`): rule, status pass|warn|fail|not-measured, severity, text and spans.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class Finding
{
    public const STATUSES = ['fail', 'warn', 'pass', 'not-measured'];

    /** @param list<Span> $spans */
    private function __construct(
        public readonly string $ruleId,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $status,
        public readonly string $title,
        public readonly string $recommendation,
        public readonly array $spans,
    ) {
    }

    public static function fromArray(mixed $row): ?self
    {
        if (!is_array($row) || !is_string($row['rule_id'] ?? null) || '' === $row['rule_id']) {
            return null;
        }
        $status = is_string($row['status'] ?? null) && in_array($row['status'], self::STATUSES, true) ? $row['status'] : 'not-measured';
        $spans = [];
        foreach (is_array($row['spans'] ?? null) ? $row['spans'] : [] as $s) {
            $span = Span::fromArray($s);
            if (null !== $span) {
                $spans[] = $span;
            }
        }

        return new self(
            $row['rule_id'],
            self::text($row['category'] ?? null),
            self::text($row['severity'] ?? null),
            $status,
            self::text($row['title'] ?? null),
            self::text($row['recommendation'] ?? null),
            $spans,
        );
    }

    /**
     * @return array{rule_id: string, category: string, severity: string, status: string, title: string,
     *               recommendation: string, spans: list<array{block_id: string, start: int, end: int}>}
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'category' => $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'title' => $this->title,
            'recommendation' => $this->recommendation,
            'spans' => array_map(static fn (Span $s): array => $s->toArray(), $this->spans),
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
