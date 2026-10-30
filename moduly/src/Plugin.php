<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Container\Container;
use PhpFasty\Moduly\Kernel\Kernel;
use PhpFasty\Moduly\Kernel\ModuleRecord;
use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Moduly;
use Throwable;

/**
 * Bootstrap of the WordPress adapter: boots enabled modules on plugins_loaded:5 (before themes and init),
 * wires admin screen, REST, WP-CLI, safe mode and fatal-error attribution.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var array{booted: list<string>, failed: array<string, string>} */
    private array $boot = ['booted' => [], 'failed' => []];

    private function __construct(
        public readonly Kernel $kernel,
        public readonly Modules $modules,
        public readonly Container $container,
        public readonly bool $safe,
    ) {
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'load'], 5);
        add_filter('map_meta_cap', [self::class, 'mapCaps'], 10, 2);
        add_action('moduly_audit_prune', [AuditLog::class, 'prune']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled('moduly_audit_prune')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'moduly_audit_prune');
            }
        });
    }

    public static function activate(): void
    {
        Paths::ensure();
        AuditLog::install();
    }

    public static function load(): void
    {
        if (self::$instance !== null) {
            return;
        }
        if (get_option('moduly_audit_db') !== AuditLog::DB_VERSION) {
            AuditLog::install();
        }
        $state = new OptionState();
        $host = new Host();
        $audit = new AuditLog();
        $resolver = new DependencyResolver(Moduly::VERSION, PHP_VERSION, ['wp' => (string) get_bloginfo('version')]);
        $container = new Container();
        $container->instance('wp.rate_limiter', new RateLimiter());
        $container->instance('wp.audit', $audit);
        $kernel = new Kernel(Paths::modules(), $state, $host, $resolver, $container, $audit);
        $modules = new Modules($kernel, $state, $audit, $resolver);
        $container->instance('wp.modules', $modules);
        $safe = SafeMode::active();
        $self = new self($kernel, $modules, $container, $safe);
        self::$instance = $self;

        // Settings schemas for Host::settings() (read from module.json of enabled modules).
        foreach ($state->load() as $rec) {
            if ($rec->status === ModuleRecord::ENABLED) {
                $m = $modules->manifest($rec);
                if ($m !== null) {
                    $host->setSchema($m->id, $m->settings);
                }
            }
        }

        register_shutdown_function([$self, 'onShutdown']);
        $self->boot = $kernel->boot($safe);
        // Disable scenario: answer the declared endpoints of installed modules that are not running.
        $installed = [];
        foreach ($state->load() as $rec) {
            $m = $modules->manifest($rec);
            if ($m !== null) {
                $installed[$m->id] = $m;
            }
        }
        Unavailable::register(Unavailable::collect($installed, $self->boot['booted']));
        if ($safe) {
            $audit->record('safe-mode', '', '', 'active');
        }
        // Activation hooks may register post types / rewrite rules: run them once WordPress is fully loaded.
        add_action('init', [$self, 'runPendingActivations'], 20);

        if (is_admin()) {
            (new AdminPage($self))->register();
        }
        add_action('rest_api_init', [new RestController($self), 'register']);
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('moduly', new Cli($self));
        }
    }

    /** @return array{booted: list<string>, failed: array<string, string>} */
    public function bootResult(): array
    {
        return $this->boot;
    }

    /** A fatal error inside a module's files marks that module failed: it is skipped from the next request on. */
    public function onShutdown(): void
    {
        $e = error_get_last();
        if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $id = $this->kernel->booting() ?? $this->kernel->moduleForFile($e['file']);
        if ($id !== null) {
            try {
                $this->kernel->markFailed([$id => 'Fatal error: ' . wp_basename($e['file']) . ':' . $e['line']]);
            } catch (Throwable) {
                // Shutdown: nothing else to do.
                return;
            }
        }
    }

    /**
     * moduly_install → install_plugins (respects DISALLOW_FILE_MODS and multisite network admins);
     * moduly_manage → activate_plugins.
     *
     * @param list<string> $caps
     * @return list<string>
     */
    public static function mapCaps(array $caps, string $cap): array
    {
        return match ($cap) {
            'moduly_install' => is_multisite() ? ['manage_network_plugins', 'install_plugins'] : ['install_plugins'],
            'moduly_manage' => is_multisite() ? ['manage_network_plugins'] : ['activate_plugins'],
            default => $caps,
        };
    }

    /** Activate modules updated in an earlier request (their new code is loaded now); on `init`. */
    public function runPendingActivations(): void
    {
        $pending = get_option('moduly_pending_activate', []);
        if (!is_array($pending) || $pending === [] || $this->safe) {
            return;
        }
        delete_option('moduly_pending_activate');
        foreach (array_keys($pending) as $id) {
            if (is_string($id) && in_array($id, $this->boot['booted'], true)) {
                try {
                    $this->kernel->enable($id);
                } catch (Throwable $e) {
                    $this->kernel->markFailed([$id => $e->getMessage()]);
                }
            }
        }
        flush_rewrite_rules(false);
    }
}
