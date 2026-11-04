<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Cart\Domain\CartLine;
use PhpFasty\Module\Cart\Domain\CartSummary;
use PhpFasty\Module\Cart\Domain\LineCodec;
use PhpFasty\Module\Cart\Domain\ShippingPolicy;
use PHPUnit\Framework\TestCase;

final class CartDomainTest extends TestCase
{
    use SnapshotAssertions;

    public function testRoundTripAndFormat(): void
    {
        $c = self::codec();
        $raw = $c->encode([5 => 1, '9~extended' => 2]);
        self::assertSame('5:1,9~extended:2.'.hash_hmac('sha256', '5:1,9~extended:2', 'secret'), $raw);
        self::assertSame([5 => 1, '9~extended' => 2], $c->decode($raw));
        self::assertSame('', $c->encode([]));
        self::assertSame('', $c->encode([0 => 3]));
    }

    public function testTamperedOrForeignValuesDecodeToNothing(): void
    {
        $raw = self::codec()->encode([5 => 1]);
        self::assertSame([], self::codec()->decode(str_replace('5:1', '5:9', $raw)));
        self::assertSame([], (new LineCodec('other', static fn (int $id): ?int => null))->decode($raw));
        self::assertSame([], self::codec()->decode('5:1'));
        self::assertSame([], self::codec()->decode(''));
        $bad = '5:1;x';
        self::assertSame([], self::codec()->decode($bad.'.'.hash_hmac('sha256', $bad, 'secret')));
        $tail = '5:1,';
        self::assertSame([], self::codec()->decode($tail.'.'.hash_hmac('sha256', $tail, 'secret')));
        $head = 'x5:1';
        self::assertSame([], self::codec()->decode($head.'.'.hash_hmac('sha256', $head, 'secret')));
    }

    public function testClampCapsQuantityStockAndLines(): void
    {
        self::assertSame([5 => 99], self::codec()->clamp([5 => 500]));
        self::assertSame([5 => 3], self::codec(3)->clamp([5 => 10]));
        self::assertSame([5 => 99], self::codec(100)->clamp([5 => 100]));
        self::assertSame([], self::codec(0)->clamp([5 => 1]));
        self::assertSame([], self::codec()->clamp([5 => 0, -1 => 2, '7~BAD' => 1]));
        self::assertSame(['7~pro' => 1], self::codec()->clamp(['7~pro' => 1]));
        self::assertSame([7 => 1], self::codec()->clamp(['7' => 1]));
        $many = [];
        for ($i = 1; $i <= 60; ++$i) {
            $many[$i] = 1;
        }
        self::assertCount(LineCodec::MAX_LINES, self::codec()->clamp($many));
        self::assertSame(range(1, 50), array_keys(self::codec()->clamp($many)));
    }

    public function testKeys(): void
    {
        self::assertSame('5', LineCodec::key(5));
        self::assertSame('5~pro', LineCodec::key(5, 'pro'));
        self::assertSame([5, 'pro'], LineCodec::parse('5~pro'));
        self::assertSame([5, ''], LineCodec::parse(5));
        self::assertSame([5, 'a~b'], LineCodec::parse('5~a~b'));
    }

    public function testShippingPolicy(): void
    {
        $p = new ShippingPolicy(500, 10000);
        self::assertSame(500, $p->cost(9999));
        self::assertSame(0, $p->cost(10000));
        self::assertSame(0, $p->cost(0));
        self::assertSame(0, $p->cost(100, false));
        self::assertSame(0, (new ShippingPolicy(500, 10000, true))->cost(100));
    }

    public function testSummary(): void
    {
        $p = new ShippingPolicy(500, 10000);
        $s = CartSummary::of([self::line(1, 2, 1000, false), self::line(2, 1, 300, true, 'pro')], $p)->toArray();
        self::assertSame(2300, $s['subtotal']);
        self::assertSame(500, $s['shipping']);
        self::assertSame(2800, $s['total']);
        self::assertSame(3, $s['count']);
        self::assertFalse($s['digital']);
        self::assertSame('2~pro', $s['items'][1]['key']);
        self::assertSame(2000, $s['items'][0]['line']);
        self::assertSame(['key', 'tier', 'tier_label', 'id', 'title', 'url', 'qty', 'unit', 'line', 'unit_display', 'line_display', 'image', 'sku', 'digital'], array_keys($s['items'][0]));

        $sum = CartSummary::of([self::line(1, 2, 1000, false), self::line(2, 1, 300, true, 'pro')], $p);
        self::assertSame(3, $sum->count());
        self::assertFalse($sum->digital());
        self::assertSame('2~pro', $sum->lines[1]->key());
        self::assertSame(300, $sum->lines[1]->total());

        $this->assertSlotsSnapshot('cart-summary', $s);

        $d = CartSummary::of([self::line(2, 1, 300, true)], $p)->toArray();
        self::assertTrue($d['digital']);
        self::assertSame(0, $d['shipping']);
        $e = CartSummary::of([], $p)->toArray();
        self::assertFalse($e['digital']);
        self::assertSame(['digital' => false, 'items' => [], 'count' => 0, 'subtotal' => 0, 'shipping' => 0, 'total' => 0], $e);
    }

    private static function codec(?int $stock = null): LineCodec
    {
        return new LineCodec('secret', static fn (int $id): ?int => $stock);
    }

    private static function line(int $id, int $qty, int $unit, bool $digital, string $tier = ''): CartLine
    {
        return new CartLine($id, $tier, '', 'T'.$id, '/p/'.$id, $qty, $unit, (string) $unit, (string) ($unit * $qty), '', '', $digital);
    }
}
