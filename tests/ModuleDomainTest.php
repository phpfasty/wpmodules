<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\ConsentLog\Domain\ConsentPolicy;
use PhpFasty\Module\CookieConsent\Domain\Choice;
use PhpFasty\Module\Licenses\Domain\Tier;
use PhpFasty\Module\Orders\Domain\CheckoutPolicy;
use PhpFasty\Module\Orders\Domain\OrderStatus;
use PhpFasty\Module\Reviews\Domain\RatingPolicy;
use PHPUnit\Framework\TestCase;

/** WordPress-free domain of every module (no fakes needed). */
final class ModuleDomainTest extends TestCase
{
    public function testRatingRangeAndAggregate(): void
    {
        $this->assertSame(1, RatingPolicy::rating('1'));
        $this->assertSame(5, RatingPolicy::rating(5));
        $this->assertSame(0, RatingPolicy::rating(0));
        $this->assertSame(0, RatingPolicy::rating(6));
        $this->assertSame(0, RatingPolicy::rating(null));
        $this->assertSame(['avg' => 3.0, 'count' => 2], RatingPolicy::aggregate([1, 5, 6, 0]));
        $this->assertSame(['avg' => 4.7, 'count' => 3], RatingPolicy::aggregate([5, 5, 4]));
        $this->assertSame(['avg' => 0.0, 'count' => 0], RatingPolicy::aggregate([]));
    }

    public function testBoughtNeedsPaidOrderWithTheProduct(): void
    {
        $orders = [
            ['status' => 'pending', 'items' => [['id' => 3]]],
            ['status' => 'completed', 'items' => ['x', ['id' => '4'], ['id' => 7]]],
            ['status' => 'processing', 'items' => [['id' => 'abc']]],
        ];
        $this->assertFalse(RatingPolicy::bought($orders, 3));
        $this->assertTrue(RatingPolicy::bought($orders, 4));
        $this->assertTrue(RatingPolicy::bought($orders, 7));
        $this->assertFalse(RatingPolicy::bought($orders, 0));
        $this->assertTrue(RatingPolicy::bought([['status' => 'paid', 'items' => [['id' => 9]]]], 9));
    }

    public function testOrderStatus(): void
    {
        $this->assertTrue(OrderStatus::valid('cancelled'));
        $this->assertFalse(OrderStatus::valid('refunded'));
        $this->assertTrue(OrderStatus::paid('paid'));
        $this->assertTrue(OrderStatus::paid('completed'));
        $this->assertFalse(OrderStatus::paid('pending'));
        $this->assertSame(['cod', 'card_2'], OrderStatus::paymentMethods(['cod', 'Bad', '', 'card_2', str_repeat('a', 33)]));
        $this->assertSame([str_repeat('a', 32)], OrderStatus::paymentMethods([str_repeat('a', 32)]));
    }

    public function testCheckoutPolicy(): void
    {
        $this->assertSame(['name', 'email'], CheckoutPolicy::defaultRequired(true));
        $this->assertSame(CheckoutPolicy::REQUIRED, CheckoutPolicy::defaultRequired(false));
        $this->assertSame(['email', 'phone'], CheckoutPolicy::knownFields(['email', 'bogus', 'phone']));
        $form = ['name' => 'A', 'email' => 'a@example.com', 'payment' => 'cod'];
        $this->assertSame([], CheckoutPolicy::validate($form, ['name', 'email'], ['cod']));
        $this->assertSame(['city', 'payment'], CheckoutPolicy::validate($form, ['name', 'city'], ['bank']));
        $this->assertSame(['email'], CheckoutPolicy::validate(['email' => 'nope', 'payment' => 'cod'], [], ['cod']));
        $this->assertSame(['email', 'payment'], CheckoutPolicy::validate([], ['email'], ['cod']));
        $this->assertSame(['name', 'consent_pd', 'server'], CheckoutPolicy::displayable(['name', 'consent_pd', 'X', '<b>', 'a', 'server']));
        $this->assertSame([], CheckoutPolicy::displayable(['a'.str_repeat('b', 32)]));
        $this->assertSame(['a'.str_repeat('b', 31)], CheckoutPolicy::displayable(['a'.str_repeat('b', 31)]));
    }

    public function testTierNormalize(): void
    {
        $rows = ['bad', ['tier' => 'regular', 'label' => 'R', 'price' => 10], ['id' => 'ext-2', 'price' => '1.5'], ['tier' => 'NO'], ['tier' => str_repeat('a', 33)], ['label' => 'x']];
        $this->assertSame([
            ['id' => 'regular', 'label' => 'R', 'price' => '10', 'default' => true],
            ['id' => 'ext-2', 'label' => 'ext-2', 'price' => '1.5', 'default' => false],
        ], Tier::normalize($rows));
        $this->assertTrue(Tier::validId(str_repeat('a', 32)));
        $this->assertFalse(Tier::validId(''));
        $this->assertSame([['id' => 'a', 'label' => 'a', 'price' => '', 'default' => true]], Tier::normalize([['tier' => 'a', 'label' => [], 'price' => null]]));
    }

    public function testConsentPolicy(): void
    {
        $defs = [
            ['name' => 'consent_offer', 'required' => true, 'page' => 'o'],
            ['name' => 'consent_news', 'required' => false, 'page' => 'n'],
            ['name' => 'consent_pd', 'required' => true, 'page' => 'p'],
        ];
        $this->assertSame(['consent_offer', 'consent_pd'], ConsentPolicy::missing($defs, []));
        $this->assertSame(['consent_pd'], ConsentPolicy::missing($defs, ['consent_offer' => true, 'consent_pd' => false]));
        $this->assertSame([], ConsentPolicy::missing($defs, ['consent_offer' => true, 'consent_pd' => true]));
        $this->assertSame(
            ['time' => 't', 'ip' => '1.2.3.4', 'version' => 'v1', 'offer' => true, 'personal_data' => false, 'newsletter' => true],
            ConsentPolicy::record(['consent_offer' => true, 'consent_news' => true], '1.2.3.4', 't', 'v1')
        );
        $this->assertSame('[deleted]', ConsentPolicy::anonymous('name'));
        $this->assertSame('', ConsentPolicy::anonymous('phone'));
        $this->assertSame(['ip' => '', 'time' => 't', 'anonymized' => true], ConsentPolicy::anonymizeLog(['ip' => 'x', 'time' => 't']));
    }

    public function testCookieChoice(): void
    {
        $this->assertTrue(Choice::allowsAnalytics('all'));
        $this->assertFalse(Choice::allowsAnalytics('necessary'));
        $this->assertTrue(Choice::valid('necessary'));
        $this->assertTrue(Choice::valid('all'));
        $this->assertFalse(Choice::valid('ALL'));
    }
}
