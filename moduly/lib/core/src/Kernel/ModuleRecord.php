<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Kernel;

/**
 * Host-persisted state of one installed module (active version, enabled flag, failure).
 */
final class ModuleRecord
{
    public const INSTALLED = 'installed';
    public const ENABLED = 'enabled';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $status = self::INSTALLED,
        public readonly string $error = '',
        public readonly string $previous = '',
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(string $id, array $a): self
    {
        $s = static fn (string $k): string => isset($a[$k]) && is_string($a[$k]) ? $a[$k] : '';
        $status = in_array($s('status'), [self::INSTALLED, self::ENABLED, self::FAILED], true) ? $s('status') : self::INSTALLED;
        return new self($id, $s('version'), $status, $s('error'), $s('previous'));
    }

    /** @return array{version: string, status: string, error: string, previous: string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'status' => $this->status, 'error' => $this->error, 'previous' => $this->previous];
    }

    public function with(string $status, string $error = ''): self
    {
        return new self($this->id, $this->version, $status, $error, $this->previous);
    }
}
