<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\ConsentLog\Slots as ConsentSlots;
use PhpFasty\Module\CookieConsent\Slots as CookieSlots;
use PhpFasty\Module\Downloads\Slots as DownloadSlots;
use PhpFasty\Module\Licenses\Licenses;
use PhpFasty\Module\Reviews\Slots as ReviewSlots;
use PHPUnit\Framework\TestCase;

/** Views the modules add to the latte-shop extension points (filter `wpfasty_slots`). */
final class SlotsTest extends TestCase
{
    private const VIEW = '#^moduly/[a-z0-9-]+/[a-z0-9_-]+$#';

    /** @param array<string, list<array<string, mixed>>> $slots */
    private function assertViewsShipped(array $slots): void
    {
        $root = dirname(__DIR__) . '/modules';
        foreach ($slots as $list) {
            foreach ($list as $slot) {
                $view = (string) $slot['view'];
                $this->assertMatchesRegularExpression(self::VIEW, $view);
                [, $id, $name] = explode('/', $view);
                foreach (['.latte', '.html.twig'] as $ext) {
                    $this->assertFileExists("{$root}/{$id}/views/moduly/{$id}/{$name}{$ext}", 'Latte + Twig twin');
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function product(bool $purchasable = true): array
    {
        return ['page' => ['id' => 5, 'fields' => [], 'product' => [
            'purchasable' => $purchasable,
            'licenses' => [
                ['tier' => 'regular', 'label' => 'Regular', 'price' => '10 ₽'],
                ['tier' => 'extended', 'label' => 'Extended', 'price' => '50 ₽'],
                ['tier' => 'Bad Tier!', 'label' => 'x'],
                'not-a-row',
            ],
        ]]];
    }

    public function testLicenseTiersAreNormalisedFirstChecked(): void
    {
        $tiers = Licenses::tiers($this->product()['page']['product']);
        $this->assertSame(['regular', 'extended'], array_column($tiers, 'id'));
        $this->assertSame([true, false], array_column($tiers, 'checked'));
        $this->assertSame('50 ₽', $tiers[1]['price']);
        $this->assertCount(3, $tiers[1]['terms']);
        $this->assertNotSame('', $tiers[0]['summary']);
    }

    public function testLicensePickerOnlyWhenPurchasable(): void
    {
        $slots = Licenses::slots([], $this->product());
        $this->assertSame('moduly/licenses/picker', $slots['product.purchase'][0]['view']);
        $this->assertSame('moduly/licenses/compare', $slots['product.after'][0]['view']);
        $this->assertViewsShipped($slots);

        $readOnly = Licenses::slots([], $this->product(false));
        $this->assertArrayNotHasKey('product.purchase', $readOnly);
        $this->assertArrayHasKey('product.after', $readOnly);
        $this->assertSame([], Licenses::slots([], ['page' => ['product' => []]]));
    }

    public function testLicenseFieldIsDeclaredOnce(): void
    {
        $fields = Licenses::fields([]);
        $this->assertSame('license_tiers', $fields[Licenses::field()]['type']);
        $kept = Licenses::fields([Licenses::field() => ['type' => 'custom']]);
        $this->assertSame('custom', $kept[Licenses::field()]['type']);
    }

    public function testDownloadsChangelogAndDetails(): void
    {
        $log = DownloadSlots::changelog(['changelog' => "1.1.0|2026-10-01|Faster\nbroken line\n1.0.0 | 2026-06-01 | First"]);
        $this->assertSame(['1.1.0', '1.0.0'], array_column($log, 'version'));
        $this->assertSame('First', $log[1]['notes']);

        $ctx = ['page' => ['product' => [], 'fields' => ['version' => '1.1.0', 'requires_php' => '8.2']]];
        $slots = DownloadSlots::slots([], $ctx);
        $this->assertSame('moduly/downloads/details', $slots['product.after'][0]['view']);
        $this->assertSame('8.2+', $slots['product.after'][0]['data']['rows'][1]['value']);
        $this->assertArrayNotHasKey('checkout.order', $slots);

        $paid = DownloadSlots::slots([], ['order' => ['downloads_available' => true, 'downloads' => [['url' => 'u']]]]);
        $this->assertSame('moduly/downloads/links', $paid['checkout.order'][0]['view']);
        $this->assertViewsShipped($slots + $paid);
    }

    public function testConsentCheckboxesKeepErrors(): void
    {
        $ctx = ['checkout' => ['errors' => ['consent_pd'], 'consents' => [
            ['name' => 'consent_offer', 'url' => '/oferta/', 'required' => true],
            ['name' => 'consent_pd', 'url' => '/consent/', 'required' => true],
            ['name' => 'bad name', 'url' => '/x/'],
        ]]];
        $items = ConsentSlots::items($ctx);
        $this->assertSame(['co-consent-offer', 'co-consent-pd'], array_column($items, 'id'));
        $this->assertSame([false, true], array_column($items, 'error'));
        $slots = ConsentSlots::slots([], $ctx);
        $this->assertViewsShipped($slots);
        $this->assertSame([], ConsentSlots::slots([], []));
    }

    public function testCookieBannerGoesToTheFooter(): void
    {
        $slots = CookieSlots::slots([], []);
        $this->assertSame('moduly/cookie-consent/banner', $slots['footer'][0]['view']);
        $this->assertSame('moduly/cookie-consent/settings', $slots['footer.links'][0]['view']);
        $this->assertNotSame('', $slots['footer'][0]['data']['labels']['settings']);
        $this->assertViewsShipped($slots);
    }

    public function testReviewSummaryFallsBackToApprovedList(): void
    {
        $ctx = ['page' => ['id' => 5, 'fields' => [], 'product' => [
            'reviews_available' => true,
            'reviews_list' => [['author' => 'A', 'rating' => 5], ['author' => 'B', 'rating' => 4]],
        ]]];
        $vm = ReviewSlots::viewModel($ctx);
        $this->assertNotNull($vm);
        $this->assertSame('4.5', $vm['rating']);
        $this->assertSame(2, $vm['count']);
        $this->assertSame('★★★★☆', $vm['reviews'][1]['stars']);
        $this->assertSame(['5', '4', '3', '2', '1'], array_column($vm['rating_options'], 'value'));
        $this->assertNull(ReviewSlots::viewModel(['page' => ['product' => []]]));
        $this->assertViewsShipped(ReviewSlots::slots([], $ctx));
    }
}
