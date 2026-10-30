<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Manifest;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use PhpFasty\Moduly\Exception\DependencyException;
use PhpFasty\Moduly\Version\Semver;

/**
 * Checks requirements (core, php, host, modules) and orders modules so that dependencies boot first.
 */
final class DependencyResolver
{
    /**
     * @param array<string, string> $host host name => version (e.g. ['wp' => '6.8.1'])
     */
    public function __construct(
        private readonly string $coreVersion,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly array $host = [],
    ) {
    }

    /**
     * Problems of one manifest against the platform and the given installed set; empty = OK.
     *
     * @param array<string, Manifest> $available id => manifest (installed and enabled)
     * @return list<string>
     */
    public function problems(Manifest $m, array $available): array
    {
        $out = [];
        if ($m->requiresCore !== null && !$m->requiresCore->allows(Semver::coerce($this->coreVersion))) {
            $out[] = sprintf('requires ModulY core %s (have %s)', $m->requiresCore, $this->coreVersion);
        }
        if ($m->requiresPhp !== null && !$m->requiresPhp->allows(Semver::coerce($this->phpVersion))) {
            $out[] = sprintf('requires PHP %s (have %s)', $m->requiresPhp, $this->phpVersion);
        }
        foreach ($m->requiresHost as $name => $constraint) {
            if (!isset($this->host[$name])) {
                $out[] = sprintf('requires host "%s" %s', $name, $constraint);
            } elseif (!$constraint->allows(Semver::coerce($this->host[$name]))) {
                $out[] = sprintf('requires %s %s (have %s)', $name, $constraint, $this->host[$name]);
            }
        }
        foreach ($m->requiresModules as $id => $constraint) {
            $dep = $available[$id] ?? null;
            if ($dep === null) {
                $out[] = sprintf('requires module "%s" %s', $id, $constraint);
            } elseif (!\PhpFasty\Moduly\Version\Constraint::parse($constraint)->allows($dep->version)) {
                $out[] = sprintf('requires module "%s" %s (have %s)', $id, $constraint, (string) $dep->version);
            }
        }
        return $out;
    }

    /** @param array<string, Manifest> $available */
    public function assert(Manifest $m, array $available): void
    {
        $problems = $this->problems($m, $available);
        if ($problems !== []) {
            throw new DependencyException('unmet_dependency', $m->id . ': ' . implode('; ', $problems));
        }
    }

    /**
     * @param list<string> $stack
     * @param array<string, Manifest> $modules
     * @param list<string> $order
     * @param array<string, int> $state
     * @param array<string, string> $skipped
     */
    private function visit(string $id, array $stack, array $modules, array &$order, array &$state, array &$skipped): bool
    {
        if (($state[$id] ?? 0) === 2) {
            return true;
        }
        if (($state[$id] ?? 0) === 1) {
            foreach ([...$stack, $id] as $c) {
                $skipped[$c] ??= 'dependency cycle';
            }
            return false;
        }
        $state[$id] = 1;
        $ok = true;
        foreach (array_keys($modules[$id]->requiresModules) as $dep) {
            if (!isset($skipped[$dep]) && isset($modules[$dep])) {
                $ok = $this->visit($dep, [...$stack, $id], $modules, $order, $state, $skipped) && $ok;
            }
        }
        $state[$id] = 2;
        if ($ok && !isset($skipped[$id])) {
            $order[] = $id;
        }
        return $ok;
    }

    /**
     * Topological order (dependencies first). Modules whose requirements fail — directly or through
     * a dependency — are returned in `skipped` with the reason; cycles are skipped too.
     *
     * @param array<string, Manifest> $modules
     * @return array{order: list<string>, skipped: array<string, string>}
     */
    public function order(array $modules): array
    {
        /** @var array<string, string> $skipped */
        $skipped = [];
        foreach ($modules as $id => $m) {
            $p = $this->problems($m, $modules);
            if ($p !== []) {
                $skipped[$id] = implode('; ', $p);
            }
        }
        // Propagate: a module depending on a skipped module is skipped.
        do {
            $changed = false;
            foreach ($modules as $id => $m) {
                if (isset($skipped[$id])) {
                    continue;
                }
                foreach (array_keys($m->requiresModules) as $dep) {
                    if (isset($skipped[$dep])) {
                        $skipped[$id] = "dependency \"{$dep}\" is not loadable";
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        $order = [];
        $state = [];
        $ids = array_map('strval', array_keys($modules));
        sort($ids);
        foreach ($ids as $id) {
            if (!isset($skipped[$id])) {
                $this->visit($id, [], $modules, $order, $state, $skipped);
            }
        }
        $order = array_values(array_filter($order, static fn (string $id): bool => !isset($skipped[$id])));
        return ['order' => $order, 'skipped' => $skipped];
    }
}
