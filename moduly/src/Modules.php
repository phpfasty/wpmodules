<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Exception\ModulyException;
use PhpFasty\Moduly\Kernel\Kernel;
use PhpFasty\Moduly\Kernel\ModuleRecord;
use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Manifest\Manifest;
use PhpFasty\Moduly\Moduly;
use PhpFasty\Moduly\Package\Fs;
use PhpFasty\Moduly\Package\Installer;
use PhpFasty\Moduly\Signature\Verifier;
use Throwable;

/**
 * Admin operations shared by the admin screen, REST and WP-CLI. Every operation is audited.
 */
final class Modules
{
    public function __construct(
        private readonly Kernel $kernel,
        private readonly OptionState $state,
        private readonly AuditLog $audit,
        private readonly DependencyResolver $resolver,
    ) {
    }

    /** @return array<string, ModuleRecord> */
    public function records(): array
    {
        return $this->state->load();
    }

    public function manifest(ModuleRecord $rec): ?Manifest
    {
        try {
            return $this->kernel->manifest($rec->id, $rec->version);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Install (or update) from a zip on disk. Never enables by itself unless $enable; an enabled module stays enabled
     * across an update and its activate() runs again (forward-only migrations).
     */
    public function install(string $zipPath, bool $enable = false, bool $allowDowngrade = false): Manifest
    {
        if (defined('DISALLOW_FILE_MODS') && constant('DISALLOW_FILE_MODS') === true && !(defined('WP_CLI') && constant('WP_CLI'))) {
            throw new ModulyException('file_mods_disallowed', __('Installing modules is disabled on this site (DISALLOW_FILE_MODS). Use WP-CLI from your deployment.', 'moduly'));
        }
        if (!Paths::ensure()) {
            throw new ModulyException('fs', __('The modules folder cannot be created.', 'moduly'));
        }
        $records = $this->state->load();
        $installed = [];
        foreach ($records as $id => $rec) {
            $m = $this->manifest($rec);
            if ($m !== null) {
                $installed[$id] = $m;
            }
        }
        $installer = new Installer(Paths::modules(), Paths::quarantine(), new Verifier(Keys::keyring()), $this->resolver);
        try {
            $result = $installer->install($zipPath, $installed, $allowDowngrade);
        } catch (ModulyException $e) {
            $this->audit->record('install', '', '', 'rejected', ['reason' => $e->reason]);
            throw $e;
        }
        $m = $result->manifest;
        $old = $records[$m->id] ?? null;
        $wasEnabled = $old !== null && $old->status === ModuleRecord::ENABLED;
        $previous = $old !== null && $old->version !== (string) $m->version ? $old->version : ($old->previous ?? '');
        $records = $this->state->load();
        $records[$m->id] = new ModuleRecord($m->id, (string) $m->version, $old?->status === ModuleRecord::FAILED ? ModuleRecord::INSTALLED : ($old->status ?? ModuleRecord::INSTALLED), '', $previous);
        $this->state->save($records);
        OptionState::setMeta($m->id, $result->signed, $result->key);
        $this->audit->record($old === null ? 'install' : 'update', $m->id, (string) $m->version, 'ok', ['signed' => $result->signed, 'permissions' => $m->permissions]);
        $this->pruneOldVersions($m->id, (string) $m->version, $previous);
        if ($wasEnabled) {
            // The old version is loaded in this request: activate() of the new code runs on the next request.
            $pending = get_option('moduly_pending_activate', []);
            $pending = is_array($pending) ? $pending : [];
            $pending[$m->id] = (string) $m->version;
            update_option('moduly_pending_activate', $pending, true);
        } elseif ($enable) {
            $this->enable($m->id);
        }
        return $m;
    }

    public function enable(string $id): void
    {
        try {
            $this->kernel->enable($id);
        } catch (Throwable $e) {
            $this->audit->record('enable', $id, '', 'failed', ['error' => $e->getMessage()]);
            throw $e instanceof ModulyException ? $e : new ModulyException('enable_failed', $e->getMessage());
        }
        flush_rewrite_rules(false);
    }

    public function disable(string $id): void
    {
        $this->kernel->disable($id);
        flush_rewrite_rules(false);
    }

    /** Remove a disabled module's code. Data (posts, meta, options) is never touched. */
    public function remove(string $id): void
    {
        $records = $this->state->load();
        $rec = $records[$id] ?? throw new ModulyException('not_installed', __('Module is not installed.', 'moduly'));
        if ($rec->status === ModuleRecord::ENABLED) {
            throw new ModulyException('enabled', __('Disable the module before removing it.', 'moduly'));
        }
        $dir = Paths::modules() . '/' . $id;
        $real = realpath($dir);
        $root = realpath(Paths::modules());
        if ($real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            Fs::remove($real);
        }
        unset($records[$id]);
        $this->state->save($records);
        $this->audit->record('remove', $id, $rec->version, 'ok', ['data' => 'kept']);
    }

    /** Switch back to the previous installed version (kept on disk, N=1). */
    public function rollback(string $id): void
    {
        $records = $this->state->load();
        $rec = $records[$id] ?? throw new ModulyException('not_installed', __('Module is not installed.', 'moduly'));
        if ($rec->previous === '' || !is_dir($this->kernel->dir($id, $rec->previous))) {
            throw new ModulyException('no_previous', __('No previous version is available.', 'moduly'));
        }
        $records[$id] = new ModuleRecord($id, $rec->previous, $rec->status === ModuleRecord::FAILED ? ModuleRecord::INSTALLED : $rec->status, '', $rec->version);
        $this->state->save($records);
        $this->audit->record('rollback', $id, $rec->previous, 'ok', ['from' => $rec->version]);
    }

    private function pruneOldVersions(string $id, string $keep, string $previous): void
    {
        $base = Paths::modules() . '/' . $id;
        foreach (scandir($base) ?: [] as $v) {
            if ($v === '.' || $v === '..' || $v === $keep || $v === $previous) {
                continue;
            }
            if (preg_match('/^[0-9A-Za-z.+-]+$/', $v) === 1 && is_dir($base . '/' . $v)) {
                Fs::remove($base . '/' . $v);
            }
        }
    }

    public static function coreVersion(): string
    {
        return Moduly::VERSION;
    }
}
