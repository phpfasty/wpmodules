<?php

/**
 * Types module entry.
 *
 * @package ModulY\Types
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types;

use PhpFasty\Module\Types\Migrations\Migration_0001_SchemaSnapshot;
use PhpFasty\Module\Types\Migrations\Migration_0002_AliasBackfill;
use PhpFasty\Module\Types\Model\Registry;
use PhpFasty\Moduly\Migration\Migrator;
use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

/**
 * Module `types`: takes over WPFasty\Types\{Registry,Field,PostType} (class_alias on plugins_loaded, before the
 * theme's autoloader exists) and the data side of the wpfasty-types plugin.
 *
 * Disable scenario (.cursor/rules/module-disable-scenario.mdc): nothing is aliased, the theme's own Registry and
 * the wpfasty-types plugin (if installed) resume; no data is deleted — migrations only add, prune needs `--yes`.
 */
final class Module implements ModuleInterface
{
    public const SCOPE = 'types';

    /** Highest migration version shipped (keep in sync with migrations()). */
    public const LATEST = 2;

    /** Classes the module provides under the theme's namespace. */
    public const ALIASES = [
        'WPFasty\\Types\\Registry' => Registry::class,
        'WPFasty\\Types\\Field' => Model\Field::class,
        'WPFasty\\Types\\PostType' => Model\PostType::class,
    ];

    private static ?Migrator $migrator = null;

    /** @var (callable(list<string>): list<array<mixed>>)|null */
    private static $definitions = null;

    public function boot(ModuleContext $context): void
    {
        $c = $context->container;
        if ($c->has('wp.theme_definitions')) {
            $defs = $c->get('wp.theme_definitions');
            self::$definitions = static fn (array $dirs): array => $defs->arrays($dirs);
        }
        if ($c->has('wp.migrator')) {
            self::$migrator = $c->get('wp.migrator');
        }
        if (!self::alias()) {
            return; // a theme Registry is already loaded: leave everything to the theme (same as disabled)
        }
        // The plugin's data layer would register the same meta twice; ours replaces it while enabled.
        $context->host->on('action', 'wpfasty_types_loaded', [self::class, 'detachPlugin'], 1);
        $context->host->on('action', 'wpfasty_types_loaded', [Data::class, 'attach'], 10);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('moduly types', Cli::class);
        }
    }

    public function activate(ModuleContext $context): void
    {
    }

    public function deactivate(ModuleContext $context): void
    {
        // Data stays; schema_version stays so a later re-enable does not re-run finished migrations.
    }

    /**
     * Aliases the theme class names to the module classes right now (plugins_loaded). A prepended autoloader is
     * not enough: the theme's Composer autoloader is registered later and also prepends, so it would win. Declaring
     * the aliases eagerly means the theme's own Registry/Field/PostType files are never loaded while enabled.
     * False when a theme copy is already loaded (then the module stays out of the way, like disabled).
     */
    public static function alias(): bool
    {
        foreach (array_keys(self::ALIASES) as $alias) {
            if (self::declared($alias)) {
                return false;
            }
        }
        class_exists(ThemeContract::class);
        foreach (self::ALIASES as $alias => $target) {
            class_alias($target, $alias);
        }
        if (self::$definitions !== null) {
            Registry::setDefinitions(self::$definitions);
        }
        return true;
    }

    private static function declared(string $class): bool
    {
        return in_array(strtolower($class), array_map('strtolower', get_declared_classes()), true);
    }

    public static function detachPlugin(): void
    {
        remove_action('wpfasty_types_loaded', ['WPFastyTypes\\Plugin', 'attach']);
    }

    public static function migrate(Registry $types): void
    {
        $versions = (array) get_option('moduly_schema_version', []);
        if (self::$migrator === null || (int) ($versions[self::SCOPE] ?? 0) >= self::LATEST) {
            return; // nothing pending: no lock write on every request
        }
        $result = self::$migrator->run(self::SCOPE, self::migrations($types));
        if (!$result['done']) {
            do_action('moduly_types_migration_pending'); // batch limit hit: the next request continues
        }
    }

    /** @return list<\PhpFasty\Moduly\Migration\MigrationInterface> */
    public static function migrations(Registry $types): array
    {
        return [new Migration_0001_SchemaSnapshot($types), new Migration_0002_AliasBackfill($types)];
    }
}
