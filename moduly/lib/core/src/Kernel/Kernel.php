<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Kernel;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use PhpFasty\Moduly\Container\Container;
use PhpFasty\Moduly\Container\ScopedContainer;
use PhpFasty\Moduly\Contract\HostInterface;
use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Exception\ModulyException;
use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Manifest\Manifest;
use PhpFasty\Moduly\Manifest\ManifestValidator;
use Throwable;

/**
 * Boots enabled modules in dependency order. Safe mode boots nothing. A module that throws while booting
 * (or whose requirements fail) is marked `failed`, its services are dropped, and the rest keeps booting.
 * Hard PHP fatals are attributed by the host adapter through moduleForFile() / booting().
 */
final class Kernel
{
    /** @var array<string, ModuleInterface> */
    private array $booted = [];
    /** @var array<string, Manifest> */
    private array $manifests = [];
    private ?string $booting = null;
    private readonly Autoloader $autoloader;

    public function __construct(
        private readonly string $modulesDir,
        private readonly StateStoreInterface $state,
        private readonly HostInterface $host,
        private readonly DependencyResolver $resolver,
        private readonly Container $container = new Container(),
        private readonly ?AuditLogInterface $audit = null,
        private readonly ManifestValidator $validator = new ManifestValidator(),
    ) {
        $this->autoloader = new Autoloader();
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * @return array{booted: list<string>, failed: array<string, string>}
     */
    public function boot(bool $safeMode = false): array
    {
        if ($safeMode) {
            return ['booted' => [], 'failed' => []];
        }
        $records = $this->state->load();
        $failed = [];
        $manifests = [];
        foreach ($records as $id => $rec) {
            if ($rec->status !== ModuleRecord::ENABLED) {
                continue;
            }
            try {
                $manifests[$id] = $this->manifest($id, $rec->version);
            } catch (Throwable $e) {
                $failed[$id] = self::message($e);
            }
        }
        $plan = $this->resolver->order($manifests);
        $failed += $plan['skipped'];

        foreach ($plan['order'] as $id) {
            $m = $manifests[$id];
            $brokenDeps = array_intersect(array_keys($m->requiresModules), array_keys($failed));
            if ($brokenDeps !== []) {
                $failed[$id] = 'dependency "' . implode('", "', $brokenDeps) . '" failed';
                continue;
            }
            try {
                $this->booting = $id;
                $module = $this->load($m, $records[$id]->version);
                $module->boot($this->context($m));
                $this->registerHooks($module, $m);
                $this->booted[$id] = $module;
                $this->manifests[$id] = $m;
            } catch (Throwable $e) {
                $this->container->forgetOwner($id);
                $failed[$id] = self::message($e);
            } finally {
                $this->booting = null;
            }
        }
        if ($failed !== []) {
            $this->markFailed($failed);
        }
        return ['booted' => array_keys($this->booted), 'failed' => $failed];
    }

    /** Run activate() of an installed module (enabling it in the state store when it succeeds). */
    public function enable(string $id): void
    {
        $records = $this->state->load();
        $rec = $records[$id] ?? throw new ModulyException('not_installed', "Module \"{$id}\" is not installed");
        $m = $this->manifest($id, $rec->version);
        $enabled = [];
        foreach ($records as $oid => $r) {
            if ($r->status === ModuleRecord::ENABLED && $oid !== $id) {
                $enabled[$oid] = $this->manifests[$oid] ?? $this->manifest($oid, $r->version);
            }
        }
        $this->resolver->assert($m, $enabled);
        $module = $this->booted[$id] ?? $this->load($m, $rec->version);
        $ctx = $this->context($m);
        if (!isset($this->booted[$id])) {
            $module->boot($ctx);
            $this->registerHooks($module, $m);
            $this->booted[$id] = $module;
            $this->manifests[$id] = $m;
        }
        $module->activate($ctx);
        $records[$id] = $rec->with(ModuleRecord::ENABLED);
        $this->state->save($records);
        $this->audit?->record('enable', $id, $rec->version, 'ok');
    }

    public function disable(string $id): void
    {
        $records = $this->state->load();
        $rec = $records[$id] ?? throw new ModulyException('not_installed', "Module \"{$id}\" is not installed");
        foreach ($records as $oid => $r) {
            if ($oid !== $id && $r->status === ModuleRecord::ENABLED) {
                $om = $this->manifests[$oid] ?? null;
                if ($om !== null && isset($om->requiresModules[$id])) {
                    throw new ModulyException('required_by', "Module \"{$oid}\" requires \"{$id}\"; disable it first");
                }
            }
        }
        if (isset($this->booted[$id])) {
            try {
                $this->booted[$id]->deactivate($this->context($this->manifests[$id]));
            } catch (Throwable $e) {
                $this->host->log('error', 'Module deactivate failed', ['module' => $id, 'error' => self::message($e)]);
            }
        }
        $records[$id] = $rec->with(ModuleRecord::INSTALLED);
        $this->state->save($records);
        $this->audit?->record('disable', $id, $rec->version, 'ok');
    }

    /** Module id whose folder contains $file (for fatal-error attribution), or null. */
    public function moduleForFile(string $file): ?string
    {
        $root = rtrim(str_replace('\\', '/', $this->modulesDir), '/') . '/';
        $file = str_replace('\\', '/', $file);
        if (!str_starts_with($file, $root)) {
            return null;
        }
        $id = explode('/', substr($file, strlen($root)), 2)[0];
        return preg_match(ManifestValidator::ID_PATTERN, $id) === 1 ? $id : null;
    }

    public function booting(): ?string
    {
        return $this->booting;
    }

    /** @param array<string, string> $failed */
    public function markFailed(array $failed): void
    {
        $records = $this->state->load();
        foreach ($failed as $id => $error) {
            if (isset($records[$id])) {
                $records[$id] = $records[$id]->with(ModuleRecord::FAILED, mb_substr($error, 0, 500));
                $this->audit?->record('fail', $id, $records[$id]->version, 'failed', ['error' => mb_substr($error, 0, 500)]);
                $this->host->log('error', 'ModulY module failed and was disabled', ['module' => $id, 'error' => $error]);
            }
        }
        $this->state->save($records);
    }

    public function manifest(string $id, string $version): Manifest
    {
        if (preg_match(ManifestValidator::ID_PATTERN, $id) !== 1 || preg_match('/^[0-9A-Za-z.+-]+$/', $version) !== 1) {
            throw new ModulyException('bad_record', 'Invalid module record');
        }
        $file = $this->dir($id, $version) . '/module.json';
        if (!is_file($file)) {
            throw new ModulyException('missing_files', "Module \"{$id}\" {$version} is missing on disk");
        }
        return $this->validator->fromJson((string) file_get_contents($file), $id);
    }

    public function dir(string $id, string $version): string
    {
        return rtrim($this->modulesDir, '/\\') . '/' . $id . '/' . $version;
    }

    /** @return array<string, ModuleInterface> */
    public function booted(): array
    {
        return $this->booted;
    }

    private function load(Manifest $m, string $version): ModuleInterface
    {
        $dir = $this->dir($m->id, $version);
        foreach ($m->psr4 as $prefix => $rel) {
            $this->autoloader->add($prefix, $dir . '/' . $rel);
        }
        if (!class_exists($m->entry)) {
            throw new ModulyException('missing_entry', "Entry class {$m->entry} not found");
        }
        $module = new ($m->entry)();
        if (!$module instanceof ModuleInterface) {
            throw new ModulyException('bad_entry', "Entry class {$m->entry} must implement ModuleInterface");
        }
        return $module;
    }

    private function context(Manifest $m): ModuleContext
    {
        $readable = array_merge(['core', $m->id], array_keys($m->requiresModules));
        return new ModuleContext(
            $m,
            $this->dir($m->id, (string) $m->version),
            new ScopedContainer($this->container, $m->id, $readable),
            $this->host,
            $this->host->settings($m->id),
        );
    }

    private function registerHooks(ModuleInterface $module, Manifest $m): void
    {
        foreach ($m->hooks as $hook) {
            $callback = [$module, $hook['method']];
            if (!is_callable($callback)) {
                throw new ModulyException('bad_hook', "Hook method {$hook['method']} is not callable");
            }
            $this->host->on($hook['type'], $hook['name'], $callback, $hook['priority'], $hook['args']);
        }
    }

    private static function message(Throwable $e): string
    {
        // Never leak absolute paths into state, notices or audit.
        return preg_replace('#(/[^\s:]+)+/#', '', $e->getMessage()) ?? $e->getMessage();
    }
}
