<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Cart\Cart;
use PhpFasty\Module\Cart\Catalog;
use PHPUnit\Framework\TestCase;

/** Cart lines carry a license tier; the price always comes from the product, never from the client. */
final class LicenseTiersTest extends TestCase
{
    protected function setUp(): void
    {
        $type = new class () {
            /** @return array<string, string> */
            public function capability(string $cap): array
            {
                return ['price_field' => 'price', 'sale_field' => 'sale_price', 'license_field' => 'licenses'];
            }
        };
        Catalog::setRegistry(new class ($type) {
            public function __construct(private object $type)
            {
            }

            public function get(string $name): object
            {
                return $this->type;
            }

            public function value(int $id, string $field): string
            {
                return match ($field) {
                    'price' => '4900',
                    'sale_price' => '3900',
                    'licenses' => $id === 1
                        ? '[{"tier":"regular","label":"Regular","price":4900},{"tier":"extended","label":"Extended","price":19900}]'
                        : '',
                    default => '',
                };
            }
        });
    }

    public function testTierIsResolvedOnTheServer(): void
    {
        $this->assertSame('regular', Catalog::tier(1, ''));
        $this->assertSame('extended', Catalog::tier(1, 'extended'));
        $this->assertNull(Catalog::tier(1, 'free'));
        $this->assertSame('', Catalog::tier(2, ''));
        $this->assertNull(Catalog::tier(2, 'extended'));
    }

    public function testTierPrices(): void
    {
        $this->assertSame(3900, Catalog::price(1, 'regular')); // default tier follows the (sale) product price
        $this->assertSame(19900, Catalog::price(1, 'extended'));
        $this->assertSame(0, Catalog::price(1, 'free'));
        $this->assertSame('Extended', Catalog::tierLabel(1, 'extended'));
    }

    public function testCookieKeepsTiersAndOldFormat(): void
    {
        $lines = [5 => 1, '1~extended' => 2];
        $this->assertSame($lines, Cart::decode(Cart::encode($lines)));
        $this->assertSame([5 => 2], Cart::decode(Cart::encode([5 => 2])));
        $this->assertSame([1, 'extended'], Cart::parse('1~extended'));
    }

    public function testTamperedTierOrPriceIsRejected(): void
    {
        [, $sig] = explode('.', Cart::encode(['1~regular' => 1]));
        $this->assertSame([], Cart::decode('1~extended:1.' . $sig)); // swapped tier, old signature
        $this->assertSame([], Cart::decode('1~extended:1:1.' . $sig));
        $this->assertSame([], Cart::decode(Cart::encode(['1~EXT<script>' => 1])));
    }
}
