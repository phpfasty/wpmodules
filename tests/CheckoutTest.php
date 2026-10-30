<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Orders\Checkout;
use PHPUnit\Framework\TestCase;

final class CheckoutTest extends TestCase
{
    public function testDigitalCartNeedsNoPostalAddress(): void
    {
        $this->assertSame(['name', 'email'], Checkout::required(true));
        $this->assertContains('address', Checkout::required(false));
        $this->assertContains('postcode', Checkout::required(false));
    }
}
