<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

/**
 * Host service `wp.theme_definitions`: modules may not include files (static scan), so the host reads the
 * array-returning definition files (e.g. `types/*.php`) of the active theme and its parent for them.
 * Only directories inside the active stylesheet/template directories are read.
 */
final class ThemeDefinitions
{
    /**
     * @param list<string> $dirs
     * @return list<array<mixed>>
     */
    public function arrays(array $dirs): array
    {
        $roots = array_filter([realpath(get_stylesheet_directory()), realpath(get_template_directory())]);
        // A base layer of the active theme (Latte `Extends:` header) adds its dir through `wpfasty_theme_dirs`;
        // only directories inside the themes root are accepted.
        $themes = realpath(get_theme_root());
        foreach ((array) apply_filters('wpfasty_theme_dirs', []) as $extra) {
            $real = realpath((string) $extra);
            if ($real !== false && $themes !== false && $this->inside($real, [$themes])) {
                $roots[] = $real;
            }
        }
        $out = [];
        foreach (array_unique($dirs) as $dir) {
            $real = realpath((string) $dir);
            if ($real === false || !$this->inside($real, $roots)) {
                continue;
            }
            foreach (glob($real . '/*.php') ?: [] as $file) {
                $def = include $file;
                if (is_array($def)) {
                    $out[] = $def;
                }
            }
        }
        return $out;
    }

    /** @param array<string> $roots */
    private function inside(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }
        return false;
    }
}
