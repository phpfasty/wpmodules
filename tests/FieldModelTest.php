<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Types\Model\Field;
use PhpFasty\WpModules\Tests\Fake\TestState;
use PHPUnit\Framework\TestCase;

/** Types field model: every kind's sanitizer, storage, admin input, meta type and input type. */
final class FieldModelTest extends TestCase
{
    public function testDefinitionDefaults(): void
    {
        $f = Field::fromArray('price', [], 'shop');
        $this->assertSame('string', $f->type);
        $this->assertSame('price', $f->label);
        $this->assertSame('_shop_price', $f->metaKey);
        $g = Field::fromArray('price', ['label' => 'Price', 'meta_key' => '_p'], 'shop');
        $this->assertSame('Price', $g->label);
        $this->assertSame('_p', $g->metaKey);
        $this->assertSame(['1', '2'], Field::fromArray('n', ['options' => ['a' => 1, 'b' => 2]], 'x')->options());
        $this->assertSame('X', Field::fromArray('n', ['sanitize' => static fn (): string => 'X'], 'x')->sanitize('y'));
        $this->expectException(\InvalidArgumentException::class);
        Field::fromArray('n', ['type' => 'nope'], 'x');
    }

    public function testSanitizePerType(): void
    {
        $this->assertSame('y', self::f('text', ['options' => ['x']])->sanitize('y'));
        $this->assertSame('', self::f('string', ['options' => ['x']])->sanitize('y'));
        $this->assertSame('', self::f('url')->sanitize('not a url'));
        $this->assertSame('https://e.com/a', self::f('url')->sanitize('https://e.com/a'));
        $this->assertSame('ab@x.com', self::f('email')->sanitize('a b@x.com'));
        $this->assertSame('5', self::f('integer')->sanitize('5.7'));
        $this->assertSame('', self::f('integer')->sanitize('abc'));
        $this->assertSame('1.5', self::f('number')->sanitize('1,5'));
        $this->assertSame('-2', self::f('money')->sanitize('-2'));
        $this->assertSame('1', self::f('boolean')->sanitize('ON'));
        $this->assertSame('', self::f('boolean')->sanitize('no'));
        $this->assertSame('2024-02-29', self::f('date')->sanitize(' 2024-02-29'));
        $this->assertSame('', self::f('date')->sanitize('2023-02-29'));
        $this->assertSame('', self::f('string')->sanitize(['array']));
        TestState::$postTypes[42] = 'attachment';
        $this->assertSame('42', self::f('file')->sanitize('42'));
        $this->assertSame('7', self::f('user')->sanitize('7'));
    }

    public function testMetaAndInputTypes(): void
    {
        $meta = [];
        $input = [];
        foreach (Field::TYPES as $t) {
            $f = self::f($t, 'enum' === $t ? ['options' => ['a']] : []);
            $meta[$t] = $f->metaType();
            $input[$t] = $f->inputType();
        }
        $this->assertSame([
            'string' => 'string', 'text' => 'string', 'url' => 'string', 'email' => 'string', 'integer' => 'integer',
            'number' => 'number', 'money' => 'integer', 'boolean' => 'boolean', 'date' => 'string', 'enum' => 'string',
            'semver' => 'string', 'file' => 'integer', 'user' => 'integer', 'license_tiers' => 'string',
        ], $meta);
        $this->assertSame([
            'string' => 'text', 'text' => 'text', 'url' => 'url', 'email' => 'email', 'integer' => 'number',
            'number' => 'number', 'money' => 'number', 'boolean' => 'checkbox', 'date' => 'date', 'enum' => 'select',
            'semver' => 'text', 'file' => 'number', 'user' => 'number', 'license_tiers' => 'textarea',
        ], $input);
    }

    public function testFromInput(): void
    {
        $this->assertSame(1250, self::f('money')->fromInput('12.5'));
        $this->assertSame(29, self::f('money')->fromInput('0.29'));
        $this->assertSame(110, self::f('money')->fromInput('1.1'));
        $this->assertSame('1.23', self::f('money')->sanitize('1.234'));
        $this->assertSame(3, self::f('integer')->fromInput('3'));
        $this->assertSame(1.5, self::f('number')->fromInput('1.5'));
        $this->assertSame(7, self::f('user')->fromInput('7'));
        $this->assertTrue(self::f('boolean')->fromInput('1'));
        $this->assertFalse(self::f('boolean')->fromInput(''));
        $this->assertNull(self::f('string')->fromInput(''));
        $this->assertSame('a', self::f('string')->fromInput('a'));
    }

    public function testToStorage(): void
    {
        $money = self::f('money');
        $this->assertSame(5, $money->toStorage(5));
        $this->assertSame(3, $money->toStorage(2.5));
        $this->assertSame(2, $money->toStorage(2.4));
        $this->assertSame(-3, $money->toStorage('-3'));
        $this->assertNull($money->toStorage('x'));
        $this->assertSame(0, self::f('integer')->toStorage(-3));
        $this->assertSame(3, self::f('integer')->toStorage(2.5));
        $this->assertSame(2, self::f('integer')->toStorage(2.4));
        $this->assertFalse(self::f('boolean')->toStorage(false));
        $this->assertFalse(self::f('boolean')->toStorage(0));
        $this->assertFalse(self::f('boolean')->toStorage(2));
        $this->assertTrue(self::f('boolean')->toStorage(1));
        $this->assertSame(2.56, self::f('number')->toStorage(2.555));
        $this->assertSame(0.0, self::f('number')->toStorage(-1));
        $this->assertNull(self::f('number')->toStorage(null));
        $this->assertTrue(self::f('boolean')->toStorage('yes'));
        $this->assertFalse(self::f('boolean')->toStorage('no'));
        TestState::$postTypes[42] = 'attachment';
        $this->assertSame(42, self::f('file')->toStorage(42));
        $this->assertNull(self::f('file')->toStorage(5));
        $this->assertNull(self::f('file')->toStorage([42]));
        $this->assertSame(7, self::f('user')->toStorage(7));
        TestState::$missingUsers = [8];
        $this->assertNull(self::f('user')->toStorage(8));
        $this->assertSame('a', self::f('string')->toStorage(' a '));
    }

    public function testIdsRejectZeroAndNonDigits(): void
    {
        TestState::$postTypes[0] = 'attachment';
        TestState::$postTypes[1] = 'attachment';
        $this->assertSame('', self::f('file')->sanitize('0'));
        $this->assertSame('', self::f('file')->sanitize('abc'));
        $this->assertSame('', self::f('user')->sanitize('0'));
        $this->assertSame('', self::f('user')->sanitize('-1'));
    }

    public function testToInput(): void
    {
        $this->assertSame('12.50', self::f('money')->toInput(1250));
        $this->assertSame('1', self::f('boolean')->toInput(true));
        $this->assertSame('', self::f('boolean')->toInput(false));
        $this->assertSame('', self::f('boolean')->toInput(0));
        $this->assertSame('', self::f('string')->toInput(null));
        $this->assertSame('x', self::f('string')->toInput('x'));
        $this->assertSame('a|A|1.50', self::f('license_tiers')->toInput([['tier' => 'a', 'label' => 'A', 'price' => 150]]));
    }

    public function testPrivateOverride(): void
    {
        $this->assertFalse(self::f('file', ['private' => false])->isPrivate());
        $this->assertTrue(self::f('string', ['private' => true])->isPrivate());
        $this->assertFalse(self::f('string')->isPrivate());
    }

    public function testSemverAndNumberHelpers(): void
    {
        $this->assertSame('1.2', Field::semver(' v1.2 '));
        $long = '1.2.3-'.str_repeat('a', 58);
        $this->assertSame($long, Field::semver($long));
        $this->assertSame('', Field::semver($long.'a'));
        $this->assertSame('0', Field::number('-1'));
        $this->assertSame('0', Field::number('-0.5'));
        $this->assertSame('-1', Field::number('-1', true));
        $this->assertSame('', Field::number('abc'));
        $this->assertSame('', Field::number(''));
        $this->assertSame('1.23', Field::number('1.234'));
        $this->assertSame('2024-01-31', Field::date('2024-01-31T10:00:00Z'));
        $this->assertSame('', Field::date('x2024-01-31'));
        $this->assertSame('', Field::date('2024-01-32'));
    }

    public function testTiersParsing(): void
    {
        $this->assertSame([], Field::tiers(''));
        $this->assertSame([['tier' => 'a', 'label' => 'A', 'price' => 150]], Field::tiers('a|A|150'));
        $this->assertSame([['tier' => 'a', 'label' => 'A', 'price' => 2]], Field::tiers('a|A|1.50'));
        $this->assertSame(110, Field::tiers('a|A|1.10', true)[0]['price']);
        $this->assertSame(29, Field::tiers('a|A|0.29', true)[0]['price']);
        $this->assertSame([], Field::tiers('a|A|', true));
        $this->assertSame(
            [['tier' => 'pro', 'label' => 'Pro', 'price' => 3], ['tier' => 'b', 'label' => 'L', 'price' => 2]],
            Field::tiers(['x', ['tier' => 'pro', 'price' => 2.5], ['tier' => 'pro', 'price' => 9], ['tier' => 'b', 'label' => '<i>L</i>', 'price' => 2.4]])
        );
        $this->assertSame([], Field::tiers([['tier' => 'a', 'price' => 'x']]));
        $this->assertSame([], Field::tiers(42));
        $this->assertSame('[{"tier":"a","label":"Цена/x","price":1}]', self::f('license_tiers')->toStorage([['tier' => 'a', 'label' => 'Цена/x', 'price' => 1]]));
    }

    protected function tearDown(): void
    {
        TestState::$postTypes = [];
        TestState::$missingUsers = [];
    }

    /** @param array<string, mixed> $def */
    private static function f(string $type, array $def = []): Field
    {
        return Field::fromArray('f', ['type' => $type] + $def, 'shop');
    }
}
