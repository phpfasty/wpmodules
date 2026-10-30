<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Exception\ModulyException;
use Throwable;
use WP_CLI;

/**
 * Manage ModulY modules (the main path for fleets: CI artifact → wp moduly install).
 */
final class Cli
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * List installed modules.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json, csv, yaml. Default: table.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $rows = [];
        foreach ($this->plugin->modules->records() as $rec) {
            $rows[] = ['id' => $rec->id, 'version' => $rec->version, 'status' => $rec->status, 'previous' => $rec->previous, 'error' => $rec->error];
        }
        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['id', 'version', 'status', 'previous', 'error']);
    }

    /**
     * Install or update a module from a zip.
     *
     * ## OPTIONS
     *
     * <zip>
     * : Path to the module zip.
     *
     * [--enable]
     * : Enable after install.
     *
     * [--force]
     * : Allow a downgrade.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function install(array $args, array $assoc): void
    {
        try {
            $m = $this->plugin->modules->install($args[0], isset($assoc['enable']), isset($assoc['force']));
            WP_CLI::success(sprintf('Installed %s %s%s.', $m->id, (string) $m->version, isset($assoc['enable']) ? ' and enabled' : ''));
        } catch (ModulyException $e) {
            WP_CLI::error(sprintf('[%s] %s', $e->reason, $e->getMessage()));
        }
    }

    /**
     * Enable a module.
     *
     * <id>
     * : Module id.
     *
     * @param list<string> $args
     */
    public function enable(array $args): void
    {
        $this->run(fn () => $this->plugin->modules->enable($args[0]), "Enabled {$args[0]}.");
    }

    /**
     * Disable a module (data is kept).
     *
     * <id>
     * : Module id.
     *
     * @param list<string> $args
     */
    public function disable(array $args): void
    {
        $this->run(fn () => $this->plugin->modules->disable($args[0]), "Disabled {$args[0]}.");
    }

    /**
     * Switch a module back to its previous version.
     *
     * <id>
     * : Module id.
     *
     * @param list<string> $args
     */
    public function rollback(array $args): void
    {
        $this->run(fn () => $this->plugin->modules->rollback($args[0]), "Rolled back {$args[0]}.");
    }

    /**
     * Remove a disabled module's files (data is kept).
     *
     * <id>
     * : Module id.
     *
     * @param list<string> $args
     */
    public function remove(array $args): void
    {
        $this->run(fn () => $this->plugin->modules->remove($args[0]), "Removed {$args[0]} (data kept).");
    }

    /**
     * Show whether safe mode is active for this process.
     */
    public function status(): void
    {
        WP_CLI::line('core ' . Modules::coreVersion() . ', safe mode ' . ($this->plugin->safe ? 'ON' : 'off'));
        foreach ($this->plugin->bootResult()['failed'] as $id => $error) {
            WP_CLI::warning("{$id}: {$error}");
        }
    }

    private function run(callable $op, string $message): void
    {
        try {
            $op();
            WP_CLI::success($message);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
        }
    }
}
