<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Container;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use Closure;
use PhpFasty\Moduly\Exception\ModulyException;

/**
 * The container as a module sees it: it may register services under its own name and read services of
 * the core and of the modules it declares in requires.modules — nothing else.
 */
final class ScopedContainer implements ContainerInterface
{
    /** @param list<string> $readable owners this module may read from (core + its dependencies + itself) */
    public function __construct(
        private readonly Container $inner,
        private readonly string $moduleId,
        private readonly array $readable,
    ) {
    }

    /** @param Closure(ContainerInterface): mixed $factory */
    public function singleton(string $id, Closure $factory): void
    {
        $this->inner->singleton($id, $factory, $this->moduleId);
    }

    public function get(string $id): mixed
    {
        $owner = $this->inner->owner($id);
        if ($owner === null) {
            throw new NotFoundException('service_not_found', sprintf('Service "%s" is not registered', $id));
        }
        if (!in_array($owner, $this->readable, true)) {
            throw new ModulyException('service_denied', sprintf('Module "%s" must require "%s" to use "%s"', $this->moduleId, $owner, $id));
        }
        return $this->inner->get($id);
    }

    public function has(string $id): bool
    {
        $owner = $this->inner->owner($id);
        return $owner !== null && in_array($owner, $this->readable, true);
    }
}
