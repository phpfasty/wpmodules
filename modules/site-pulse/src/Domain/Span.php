<?php

/**
 * Highlight region inside one analyzed block (`textcheck.Span`): UTF-16 code unit offsets into the block text.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class Span
{
    private function __construct(public readonly string $blockId, public readonly int $start, public readonly int $end)
    {
    }

    public static function fromArray(mixed $row): ?self
    {
        if (!is_array($row) || !is_string($row['block_id'] ?? null) || !is_int($row['start'] ?? null) || !is_int($row['end'] ?? null)) {
            return null;
        }
        if ('' === $row['block_id'] || $row['start'] < 0 || $row['end'] < $row['start']) {
            return null;
        }

        return new self($row['block_id'], $row['start'], $row['end']);
    }

    /** @return array{block_id: string, start: int, end: int} */
    public function toArray(): array
    {
        return ['block_id' => $this->blockId, 'start' => $this->start, 'end' => $this->end];
    }
}
