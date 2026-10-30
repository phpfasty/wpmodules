<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Version;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use InvalidArgumentException;

/**
 * Composer-style version constraint subset: `^1.2`, `~1.2.3`, `>=8.2`, `<2.0`, `1.2.3`, `1.2.*`, `*`,
 * AND by space or comma, OR by `||`.
 */
final class Constraint
{
    /** @param list<list<array{0: string, 1: Semver}>> $alternatives */
    private function __construct(private readonly string $raw, private readonly array $alternatives)
    {
    }

    public static function isValid(string $constraint): bool
    {
        try {
            self::parse($constraint);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function parse(string $constraint): self
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            throw new InvalidArgumentException('Empty constraint');
        }
        $alternatives = [];
        foreach (explode('||', $constraint) as $alt) {
            $parts = preg_split('/[\s,]+/', trim($alt), -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false || $parts === []) {
                throw new InvalidArgumentException('Empty constraint alternative: ' . $constraint);
            }
            $ranges = [];
            foreach ($parts as $part) {
                array_push($ranges, ...self::expand($part));
            }
            $alternatives[] = $ranges;
        }
        return new self($constraint, $alternatives);
    }

    public function allows(Semver $version): bool
    {
        foreach ($this->alternatives as $ranges) {
            $ok = true;
            foreach ($ranges as [$op, $bound]) {
                $cmp = $version->compare($bound);
                $ok = match ($op) {
                    '>=' => $cmp >= 0,
                    '>' => $cmp > 0,
                    '<=' => $cmp <= 0,
                    '<' => $cmp < 0,
                    default => $cmp === 0,
                };
                if (!$ok) {
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    public function __toString(): string
    {
        return $this->raw;
    }

    /** @return list<array{0: string, 1: Semver}> */
    private static function expand(string $part): array
    {
        if ($part === '*') {
            return [['>=', Semver::coerce('0.0.0')]];
        }
        if (preg_match('/^(\^|~|>=|<=|>|<|=)?v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?(-[0-9A-Za-z.-]+)?$/', $part, $m) !== 1) {
            throw new InvalidArgumentException('Unsupported constraint: ' . $part);
        }
        $op = $m[1];
        $major = (int) $m[2];
        $minorRaw = $m[3] ?? '';
        $patchRaw = $m[4] ?? '';
        $pre = $m[5] ?? '';
        $minor = $minorRaw === '' || $minorRaw === '*' ? null : (int) $minorRaw;
        $patch = $patchRaw === '' || $patchRaw === '*' ? null : (int) $patchRaw;
        $low = Semver::coerce(sprintf('%d.%d.%d%s', $major, $minor ?? 0, $patch ?? 0, $pre));

        switch ($op) {
            case '^':
                $upper = match (true) {
                    $major > 0 || $minor === null => sprintf('%d.0.0', $major + 1),
                    $minor > 0 || $patch === null => sprintf('0.%d.0', $minor + 1),
                    default => sprintf('0.0.%d', $patch + 1),
                };
                return [['>=', $low], ['<', Semver::coerce($upper . '-0')]];
            case '~':
                $upper = $minor === null || $patch === null ? sprintf('%d.0.0', $major + 1) : sprintf('%d.%d.0', $major, $minor + 1);
                return [['>=', $low], ['<', Semver::coerce($upper . '-0')]];
            case '>=':
            case '>':
            case '<=':
            case '<':
                return [[$op, $low]];
            default:
                if ($minor === null) {
                    return [['>=', $low], ['<', Semver::coerce(sprintf('%d.0.0-0', $major + 1))]];
                }
                if ($patch === null) {
                    return [['>=', $low], ['<', Semver::coerce(sprintf('%d.%d.0-0', $major, $minor + 1))]];
                }
                return [['=', $low]];
        }
    }
}
