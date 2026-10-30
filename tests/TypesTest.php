<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Types\Migrations\Migration_0002_AliasBackfill;
use PhpFasty\Module\Types\Model\Field;
use PhpFasty\Module\Types\Model\Registry;
use PhpFasty\Module\Types\Module;
use PhpFasty\Moduly\Migration\LockInterface;
use PhpFasty\Moduly\Migration\MigrationGuard;
use PhpFasty\Moduly\Migration\Migrator;
use PhpFasty\Moduly\Migration\SchemaVersionStoreInterface;
use PHPUnit\Framework\TestCase;
use PhpFasty\WpModules\Tests\Fake\FakeWp;

require_once __DIR__ . '/fake-wp-functions.php';

/** Module `types`: forward-only migrations (ADR-002), alias reads, and the no-delete guarantee. */
final class TypesTest extends TestCase
{
    protected function setUp(): void
    {
        FakeWp::reset();
    }

    public function testMigrationsContainNoDeletes(): void
    {
        $dir = dirname(__DIR__) . '/modules/types/src/Migrations';
        self::assertNotEmpty(glob($dir . '/Migration_*.php'));
        self::assertSame([], MigrationGuard::violations($dir));
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression('/\bdelete_\w+\s*\(/', (string) file_get_contents($file), basename($file));
        }
    }

    public function testLatestMatchesShippedMigrations(): void
    {
        $versions = array_map(static fn ($m): int => $m->version(), Module::migrations(self::registry()));
        self::assertSame(Module::LATEST, max($versions));
    }

    /** Old schema (field `price` stored under `_old_price`, `year` as text) → migrate twice → nothing lost. */
    public function testOldSchemaMigratesForwardIdempotentlyAndKeepsAllMeta(): void
    {
        for ($id = 1; $id <= 7; $id++) {
            FakeWp::$posts[$id] = 'book';
            FakeWp::$meta[$id]['_old_price'] = [(string) ($id * 100)];
            FakeWp::$meta[$id]['_book_note'] = ['n' . $id];
        }
        FakeWp::$posts[8] = 'page';
        FakeWp::$meta[8]['_old_price'] = ['999'];
        FakeWp::$meta[3]['_book_price'] = ['5']; // new key already set: never overwritten
        FakeWp::$meta[4]['_old_price'] = ['abc']; // invalid for money: skipped + logged
        $before = FakeWp::dump();

        $registry = self::registry();
        $migrator = self::migrator(batch: 2);
        $first = $migrator->run(Module::SCOPE, Module::migrations($registry));
        self::assertTrue($first['done']);
        self::assertSame([1, 2], $first['ran']);
        $after = FakeWp::dump();

        // every original row is still there, unchanged
        foreach ($before['meta'] as $id => $keys) {
            foreach ($keys as $key => $values) {
                self::assertSame($values, $after['meta'][$id][$key], "{$id}:{$key}");
            }
        }
        self::assertSame(['100'], $after['meta'][1]['_book_price']);
        self::assertSame(['5'], $after['meta'][3]['_book_price']);
        self::assertArrayNotHasKey('_book_price', $after['meta'][4]);
        self::assertArrayNotHasKey('_book_price', $after['meta'][8]);
        self::assertSame(['700'], $after['meta'][7]['_book_price']);
        self::assertContains('moduly_types_invalid_value', array_column(FakeWp::$actions, 0));
        self::assertSame(2, FakeWp::$options['moduly_schema_version']['types']);
        self::assertArrayHasKey('book', FakeWp::$options['moduly_types_schema']['current']);

        // re-run: no-op (version recorded), and re-running a migration directly changes nothing either
        $second = $migrator->run(Module::SCOPE, Module::migrations($registry));
        self::assertSame([], $second['ran']);
        FakeWp::$options['moduly_types_m2_cursor'] = [];
        (new Migration_0002_AliasBackfill($registry))->up(100);
        self::assertSame($after['meta'], FakeWp::dump()['meta']);
    }

    public function testReadFallsBackToAliasAndInvalidGivesDefault(): void
    {
        FakeWp::$posts[1] = 'book';
        FakeWp::$meta[1]['_old_price'] = ['250'];
        FakeWp::$meta[2]['_book_price'] = ['12.5x'];
        $price = self::registry()->get('book')?->fields['price'];
        self::assertInstanceOf(Field::class, $price);
        self::assertSame('250', Registry::read(1, $price));
        self::assertSame('', Registry::read(2, $price));
        self::assertSame(['12.5x'], FakeWp::$meta[2]['_book_price']); // stored value untouched
    }

    public function testAliasTargetsAreTheThemeClassNames(): void
    {
        self::assertSame(
            ['WPFasty\\Types\\Registry', 'WPFasty\\Types\\Field', 'WPFasty\\Types\\PostType'],
            array_keys(Module::ALIASES)
        );
    }

    private static function registry(): Registry
    {
        Registry::setDefinitions(static fn (array $dirs): array => [[
            'name' => 'book',
            'fields' => [
                'price' => ['type' => 'money', 'aliases' => ['_old_price']],
                'note' => ['type' => 'string'],
            ],
        ]]);
        $r = new Registry(['/theme/types']);
        $r->load();
        return $r;
    }

    private static function migrator(int $batch): Migrator
    {
        $versions = new class () implements SchemaVersionStoreInterface {
            public function get(string $scope): int
            {
                return (int) (((array) get_option('moduly_schema_version', []))[$scope] ?? 0);
            }

            public function set(string $scope, int $version): void
            {
                $all = (array) get_option('moduly_schema_version', []);
                $all[$scope] = $version;
                update_option('moduly_schema_version', $all);
            }
        };
        $lock = new class () implements LockInterface {
            public function acquire(string $name, int $ttlSeconds): bool
            {
                return true;
            }

            public function release(string $name): void
            {
            }
        };
        return new Migrator($versions, $lock, $batch, 50);
    }
}
