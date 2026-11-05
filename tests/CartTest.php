<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Cart\Cart;
use PhpFasty\Module\Cart\Handlers;
use PhpFasty\Module\Cart\Money;
use PhpFasty\Moduly\Wp\Support\Value;
use PHPUnit\Framework\TestCase;

/** Ported from wp-fasty tests/ShopCartTest.php: same cookie format and money in integer cents. */
final class CartTest extends TestCase
{
    public function testMoneyUsesIntegerCents(): void
    {
        $this->assertSame(1999, Money::toCents('19.99'));
        $this->assertSame(1050, Money::toCents('10,5'));
        $this->assertSame(0, Money::toCents('abc'));
        $this->assertSame(4500, Money::cents('4500'));
        $this->assertSame(4500, Money::cents(4500));
        $this->assertSame(0, Money::cents(''));
    }

    public function testCookieNameIsUnchanged(): void
    {
        $this->assertSame('moduly_cart', Cart::COOKIE);
    }

    public function testCartCookieRoundTripsWhenSigned(): void
    {
        $this->assertSame([5 => 2, 9 => 1], Cart::decode(Cart::encode([5 => 2, 9 => 1])));
    }

    public function testTamperedCookieIsRejected(): void
    {
        [$payload, $sig] = explode('.', Cart::encode([5 => 2]));
        $this->assertSame([], Cart::decode('5:1000.'.$sig));
        $this->assertSame([], Cart::decode($payload.'.deadbeef'));
        $this->assertSame([], Cart::decode('garbage'));
    }

    public function testQuantitiesAreClamped(): void
    {
        $this->assertSame([7 => Cart::MAX_QTY], Cart::decode(Cart::encode([7 => 5000, -1 => 3, 8 => 0])));
    }

    public function testShippingIsFreeOverThreshold(): void
    {
        $this->assertSame(500, Cart::shipping(5000));
        $this->assertSame(0, Cart::shipping(10000));
        $this->assertSame(0, Cart::shipping(0));
    }

    public function testDigitalOnlyStoreNeverChargesShipping(): void
    {
        Cart::configure(['flat_shipping_cents' => 500, 'free_shipping_cents' => 10000, 'digital_only' => true]);
        $this->assertTrue(Cart::digitalOnly());
        $this->assertSame(0, Cart::shipping(5000));
        Cart::configure(['flat_shipping_cents' => 500, 'free_shipping_cents' => 10000]);
        $this->assertFalse(Cart::digitalOnly());
        $this->assertSame(500, Cart::shipping(5000));
    }

    public function testQuantitiesPreserveProductKeys(): void
    {
        $posted = [42 => '2', '9~extended' => 3];
        $this->assertSame(['42' => 2, '9~extended' => 3], Handlers::quantities($posted));
        $this->assertSame([2], Value::ints([42 => 2]));
    }

    public function testPostedQuantitiesUpdateExistingLines(): void
    {
        $lines = new \ReflectionProperty(Cart::class, 'lines');
        $lines->setValue(null, [42 => 1, '9~extended' => 1]);
        try {
            foreach (Handlers::quantities([42 => 2, '9~extended' => 3]) as $key => $qty) {
                Cart::set($key, $qty);
            }
            $this->assertSame([42 => 2, '9~extended' => 3], Cart::lines());
        } finally {
            $lines->setValue(null, null);
        }
    }

    protected function setUp(): void
    {
        Cart::configure(['flat_shipping_cents' => 500, 'free_shipping_cents' => 10000]);
    }

    protected function tearDown(): void
    {
        $lines = new \ReflectionProperty(Cart::class, 'lines');
        $lines->setValue(null, null);
        parent::tearDown();
    }
}
