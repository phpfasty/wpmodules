<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Types\Domain\Field;
use PhpFasty\Module\Types\Domain\PlainSanitizer;
use PhpFasty\Module\Types\Domain\Scalar;
use PhpFasty\Module\Types\WpSanitizer;
use PHPUnit\Framework\TestCase;

/** Types domain without WordPress: the pure-PHP sanitizer, value narrowing. */
final class TypesDomainTest extends TestCase
{
    public function testPlainSanitizer(): void
    {
        $s = new PlainSanitizer();
        $this->assertSame('a b c', $s->text(" a\n<b>b</b>\t c "));
        $this->assertSame('a', $s->text(' a '));
        $this->assertSame('a', $s->textarea(' a '));
        $this->assertSame('x', $s->stripTags('<SCRIPT>a</SCRIPT>x'));
        $this->assertSame('x', $s->stripTags("<script>\na\n</script>x"));
        $this->assertSame('ab', $s->stripTags('a<script>z</script>b'));
        $this->assertSame("a\nb", $s->textarea(" a\n<i>b</i> "));
        $this->assertSame('x', $s->stripTags('<script>alert(1)</script><style>p{}</style> x '));
        $this->assertSame('ab@x.com', $s->email('a b@x.com'));
        $this->assertSame('https://e.com/a', $s->url('https://e.com/a'));
        $this->assertSame('', $s->url('javascript://e.com/%0aalert(1)'));
        $this->assertSame('', $s->url('not a url'));
        $this->assertTrue($s->attachmentExists(1));
        $this->assertFalse($s->attachmentExists(0));
        $this->assertTrue($s->userExists(1));
        $this->assertFalse($s->userExists(0));
    }

    public function testFieldUsesThePort(): void
    {
        Field::useSanitizer(new PlainSanitizer());
        $this->assertInstanceOf(PlainSanitizer::class, Field::sanitizer());
        $file = Field::fromArray('f', ['type' => 'file'], 'x');
        $this->assertSame(99, $file->toStorage('99'), 'plain: no attachment lookup');
        $this->assertSame('a b', Field::fromArray('f', [], 'x')->sanitize("a\n b"));
    }

    public function testScalar(): void
    {
        $this->assertSame('1', Scalar::str(true));
        $this->assertSame('', Scalar::str(false));
        $this->assertSame('2.5', Scalar::str(2.5));
        $this->assertSame('', Scalar::str([]));
        $this->assertSame('s', Scalar::str(new class {
            public function __toString(): string
            {
                return 's';
            }
        }));
        $this->assertSame(3, Scalar::int('3.9'));
        $this->assertSame(1, Scalar::int(true));
        $this->assertSame(2, Scalar::int(2.7));
        $this->assertSame(0, Scalar::int(INF));
        $this->assertSame(0, Scalar::int('x'));
        $this->assertSame([], Scalar::arr('x'));
        $this->assertSame([1], Scalar::arr([1]));
        $this->assertSame(['a' => 1], Scalar::map(['a' => 1, 0 => 2]));
    }

    public function testModelAliasesRemoved(): void
    {
        $this->assertFalse(class_exists('PhpFasty\Module\Types\Model\Field'));
        $this->assertFalse(class_exists('PhpFasty\Module\Types\Model\PostType'));
    }

    protected function tearDown(): void
    {
        Field::useSanitizer(new WpSanitizer());
    }
}
