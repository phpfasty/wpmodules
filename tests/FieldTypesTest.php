<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Types\Data;
use PhpFasty\Module\Types\Model\Field;
use PHPUnit\Framework\TestCase;

/** Module `types` 0.2: digital-goods field kinds (enum, semver, file, user, license_tiers). */
final class FieldTypesTest extends TestCase
{
    /** @param array<string, mixed> $def */
    private static function field(string $type, array $def = []): Field
    {
        return Field::fromArray('f', ['type' => $type] + $def, 'shop');
    }

    public function testEnumNeedsOptionsAndKeepsTheClosedSet(): void
    {
        $f = self::field('enum', ['options' => ['gpl', 'commercial']]);
        $this->assertSame('gpl', $f->sanitize('gpl'));
        $this->assertSame('', $f->sanitize('mit'));
        $this->assertSame('select', $f->inputType());
        $this->expectException(\InvalidArgumentException::class);
        self::field('enum');
    }

    public function testSemver(): void
    {
        $f = self::field('semver');
        $this->assertSame('1.2.3', $f->sanitize('v1.2.3'));
        $this->assertSame('6.5', $f->sanitize('6.5'));
        $this->assertSame('2.0.0-beta.1', $f->sanitize('2.0.0-beta.1'));
        $this->assertSame('', $f->sanitize('1.2.3.4'));
        $this->assertSame('', $f->sanitize('latest'));
        $this->assertSame('string', $f->metaType());
    }

    public function testFileIsPrivateIntegerAndNeverInRest(): void
    {
        $f = self::field('file');
        $this->assertTrue($f->isPrivate());
        $this->assertSame('integer', $f->metaType());
        $this->assertSame(42, $f->fromInput('42'));
        $this->assertNull($f->fromInput('../../wp-config.php'));
        $this->assertNull($f->toStorage('-3'));
        $this->assertFalse(Data::restSchema($f));
        $this->assertFalse(self::field('user')->isPrivate());
        $this->assertSame(['schema' => ['pattern' => Field::SEMVER_PATTERN]], Data::restSchema(self::field('semver')));
    }

    public function testUserId(): void
    {
        $f = self::field('user');
        $this->assertSame(7, $f->toStorage('7'));
        $this->assertNull($f->toStorage('abc'));
    }

    public function testLicenseTiersRoundTripInCents(): void
    {
        $f = self::field('license_tiers');
        $stored = $f->fromInput("regular|Regular|49.00\nextended|Extended|199,50\nbad|Bad|0\nregular|Dup|1");
        $this->assertSame('[{"tier":"regular","label":"Regular","price":4900},{"tier":"extended","label":"Extended","price":19950}]', $stored);
        $this->assertSame("regular|Regular|49.00\nextended|Extended|199.50", $f->toInput($stored));
        // REST / programmatic: cents
        $this->assertSame('[{"tier":"pro","label":"Pro","price":100}]', $f->toStorage([['tier' => 'Pro!', 'label' => '<b>Pro</b>', 'price' => 100]]));
        $this->assertNull($f->toStorage('[{"tier":"x","price":-5}]'));
        $this->assertSame('textarea', $f->inputType());
        $this->assertCount(Field::MAX_TIERS, Field::tiers(json_encode(array_map(static fn (int $i): array => ['tier' => "t$i", 'price' => 1], range(1, 9)))));
    }
}
