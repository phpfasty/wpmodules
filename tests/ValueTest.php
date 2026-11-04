<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Moduly\Wp\Support\Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function strings(): iterable
    {
        yield 'string' => ['a', 'a'];
        yield 'int' => [12, '12'];
        yield 'float' => [1.5, '1.5'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, ''];
        yield 'null' => [null, ''];
        yield 'array' => [['x'], ''];
        yield 'stringable' => [new class implements \Stringable {
            public function __toString(): string
            {
                return 's';
            }
        }, 's'];
        yield 'object' => [new \stdClass(), ''];
    }

    #[DataProvider('strings')]
    public function testStr(mixed $in, string $out): void
    {
        self::assertSame($out, Value::str($in));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function ints(): iterable
    {
        yield 'int' => [7, 7];
        yield 'numeric string' => [' 42 ', 42];
        yield 'decimal string' => ['3.9', 3];
        yield 'float' => [2.7, 2];
        yield 'inf' => [INF, 0];
        yield 'nan' => [NAN, 0];
        yield 'true' => [true, 1];
        yield 'garbage' => ['12abc', 0];
        yield 'array' => [[1], 0];
        yield 'null' => [null, 0];
    }

    #[DataProvider('ints')]
    public function testInt(mixed $in, int $out): void
    {
        self::assertSame($out, Value::int($in));
    }

    public function testFloat(): void
    {
        self::assertSame(1.25, Value::float('1.25'));
        self::assertSame(2.0, Value::float(2));
        self::assertSame(1.0, Value::float(true));
        self::assertSame(0.5, Value::float(0.5));
        self::assertSame(0.0, Value::float('x'));
        self::assertSame(0.0, Value::float(null));
    }

    public function testBool(): void
    {
        self::assertTrue(Value::bool(true));
        self::assertTrue(Value::bool('yes'));
        self::assertTrue(Value::bool(1));
        self::assertTrue(Value::bool(0.1));
        foreach (['', '0', 'false', 'No', ' off '] as $s) {
            self::assertFalse(Value::bool($s), $s);
        }
        self::assertFalse(Value::bool(0));
        self::assertFalse(Value::bool(null));
        self::assertFalse(Value::bool([1]));
    }

    public function testArraysAndLists(): void
    {
        self::assertSame([], Value::arr('x'));
        self::assertSame([3 => 'a'], Value::arr([3 => 'a']));
        self::assertSame(['k' => 1], Value::map(['k' => 1, 0 => 2]));
        self::assertSame([], Value::map(null));
        self::assertSame(['a', 'b'], Value::list([5 => 'a', 9 => 'b']));
        self::assertSame([], Value::list(1));
        self::assertSame(['1', '', 'x'], Value::strings([1, null, 'x']));
        self::assertSame([1, 0, 3], Value::ints(['1', 'x', 3]));
    }

    public function testAt(): void
    {
        self::assertSame(1, Value::at(['a' => 1], 'a'));
        self::assertSame('z', Value::at([2 => 'z'], 2));
        self::assertNull(Value::at(['a' => 1], 'b'));
        self::assertNull(Value::at('abc', 0));
    }
}
