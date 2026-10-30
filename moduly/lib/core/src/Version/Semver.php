<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Version;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use InvalidArgumentException;

/**
 * SemVer 2.0 version (build metadata ignored for precedence).
 */
final class Semver
{
    public const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /** @param list<string> $pre */
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly array $pre,
    ) {
    }

    public static function isValid(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }

    public static function parse(string $version): self
    {
        if (preg_match(self::PATTERN, $version, $m) !== 1) {
            throw new InvalidArgumentException('Not a SemVer 2.0 version: ' . $version);
        }
        $pre = isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : [];
        return new self((int) $m[1], (int) $m[2], (int) $m[3], $pre);
    }

    /**
     * Lenient parse for host versions such as PHP "8.4.23" or WordPress "6.8" / "7.1.3-beta1".
     */
    public static function coerce(string $version): self
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-?([0-9A-Za-z.-]+))?/', trim($version), $m) !== 1) {
            throw new InvalidArgumentException('Not a version: ' . $version);
        }
        $pre = isset($m[4]) ? explode('.', $m[4]) : [];
        return new self((int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), $pre);
    }

    public function compare(self $other): int
    {
        foreach ([[$this->major, $other->major], [$this->minor, $other->minor], [$this->patch, $other->patch]] as [$a, $b]) {
            if ($a !== $b) {
                return $a <=> $b;
            }
        }
        if ($this->pre === [] || $other->pre === []) {
            return ($this->pre === []) <=> ($other->pre === []);
        }
        $count = max(count($this->pre), count($other->pre));
        for ($i = 0; $i < $count; $i++) {
            if (!isset($this->pre[$i])) {
                return -1;
            }
            if (!isset($other->pre[$i])) {
                return 1;
            }
            $a = $this->pre[$i];
            $b = $other->pre[$i];
            $an = ctype_digit($a);
            $bn = ctype_digit($b);
            $cmp = match (true) {
                $an && $bn => (int) $a <=> (int) $b,
                $an => -1,
                $bn => 1,
                default => strcmp($a, $b) <=> 0,
            };
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    }

    public function isPreRelease(): bool
    {
        return $this->pre !== [];
    }

    public function __toString(): string
    {
        return sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch) . ($this->pre === [] ? '' : '-' . implode('.', $this->pre));
    }
}
