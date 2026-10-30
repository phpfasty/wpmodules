<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Container;

/**
 * PSR-11-compatible shape without the psr/container dependency (the core ships no runtime dependencies).
 */
interface ContainerInterface
{
    public function get(string $id): mixed;

    public function has(string $id): bool;
}
