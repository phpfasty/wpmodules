<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Types\Model\PostType;
use PHPUnit\Framework\TestCase;

final class PostTypeModelTest extends TestCase
{
    public function testMetaPrefixDefaultsToTheTypeName(): void
    {
        $t = PostType::fromArray(['name' => 'book', 'fields' => ['price' => ['type' => 'money']]]);
        self::assertSame('_book_price', $t->fields['price']->metaKey);
        $p = PostType::fromArray(['name' => 'book', 'meta_prefix' => 'b', 'fields' => ['price' => ['type' => 'money']]]);
        self::assertSame('_b_price', $p->fields['price']->metaKey);
    }

    public function testNumericFieldKeysBecomeStrings(): void
    {
        $t = PostType::fromArray(['name' => 'book', 'fields' => [7 => ['type' => 'text']]]);
        self::assertSame(['7'], array_map('strval', array_keys($t->fields)));
        self::assertSame([], PostType::fromArray(['name' => 'book', 'fields' => 'x'])->fields);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'leading digit' => ['1book'];
        yield 'trailing dash' => ['book-'];
        yield 'uppercase' => ['Book'];
        yield 'too long' => ['abcdefghijklmnopqrstu'];
        yield 'one char' => ['b'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNames')]
    public function testInvalidNamesAreRejected(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PostType::fromArray(['name' => $name]);
    }
}
