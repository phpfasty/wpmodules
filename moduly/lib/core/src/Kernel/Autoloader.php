<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Kernel;

/**
 * One spl autoloader for all enabled modules: PSR-4 prefix → directory inside the module folder.
 */
final class Autoloader
{
    /** @var array<string, list<string>> prefix => dirs */
    private array $map = [];
    private bool $registered = false;

    public function add(string $prefix, string $dir): void
    {
        $this->map[$prefix][] = rtrim($dir, '/\\') . '/';
        uksort($this->map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        if (!$this->registered) {
            spl_autoload_register($this->load(...));
            $this->registered = true;
        }
    }

    public function remove(string $dirPrefix): void
    {
        foreach ($this->map as $prefix => $dirs) {
            $this->map[$prefix] = array_values(array_filter($dirs, static fn (string $d): bool => !str_starts_with($d, $dirPrefix)));
            if ($this->map[$prefix] === []) {
                unset($this->map[$prefix]);
            }
        }
    }

    public function load(string $class): void
    {
        foreach ($this->map as $prefix => $dirs) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
                return;
            }
            foreach ($dirs as $dir) {
                $file = $dir . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require $file;
                    return;
                }
            }
        }
    }
}
