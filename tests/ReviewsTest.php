<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Reviews\Reviews;
use PHPUnit\Framework\TestCase;

final class ReviewsTest extends TestCase
{
    public function testOnlyPaidOrdersWithTheProductVerify(): void
    {
        $orders = [
            ['status' => 'pending', 'items' => [['id' => 5]]],
            ['status' => 'paid', 'items' => [['id' => 7], ['id' => 8]]],
            ['status' => 'cancelled', 'items' => [['id' => 9]]],
        ];
        $this->assertFalse(Reviews::bought($orders, 5), 'unpaid');
        $this->assertTrue(Reviews::bought($orders, 8));
        $this->assertFalse(Reviews::bought($orders, 9), 'cancelled');
        $this->assertFalse(Reviews::bought([], 8));
    }

    public function testRatingRangeAndAggregate(): void
    {
        $this->assertSame(0, Reviews::rating('6'));
        $this->assertSame(0, Reviews::rating('x'));
        $this->assertSame(4, Reviews::rating('4'));
        $this->assertSame(['avg' => 4.3, 'count' => 3], Reviews::aggregate([5, 4, 4, 0]));
        $this->assertSame(['avg' => 0.0, 'count' => 0], Reviews::aggregate([]));
    }
}
