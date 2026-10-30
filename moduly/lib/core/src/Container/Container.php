<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Container;

use Closure;
use PhpFasty\Moduly\Exception\ModulyException;

/**
 * Minimal DI container: lazy singletons, owner tracking (which module provided a service), cycle detection.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(ContainerInterface): mixed> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var array<string, string> service id => owner (module id or "core") */
    private array $owners = [];
    /** @var array<string, true> */
    private array $resolving = [];

    /** @param Closure(ContainerInterface): mixed $factory */
    public function singleton(string $id, Closure $factory, string $owner = 'core'): void
    {
        if (isset($this->owners[$id]) && $this->owners[$id] !== $owner) {
            throw new ModulyException('service_conflict', sprintf('Service "%s" is already provided by "%s"', $id, $this->owners[$id]));
        }
        $this->factories[$id] = $factory;
        $this->owners[$id] = $owner;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value, string $owner = 'core'): void
    {
        $this->singleton($id, static fn (): mixed => $value, $owner);
        $this->instances[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new NotFoundException('service_not_found', sprintf('Service "%s" is not registered', $id));
        }
        if (isset($this->resolving[$id])) {
            throw new ModulyException('service_cycle', sprintf('Circular dependency while resolving "%s"', $id));
        }
        $this->resolving[$id] = true;
        try {
            $this->instances[$id] = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }
        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    public function owner(string $id): ?string
    {
        return $this->owners[$id] ?? null;
    }

    /** Remove every service of an owner (module disabled or failed while booting). */
    public function forgetOwner(string $owner): void
    {
        foreach ($this->owners as $id => $o) {
            if ($o === $owner) {
                unset($this->factories[$id], $this->instances[$id], $this->owners[$id]);
            }
        }
    }
}
