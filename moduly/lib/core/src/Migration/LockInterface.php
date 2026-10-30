<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Migration;

interface LockInterface
{
    public function acquire(string $name, int $ttlSeconds): bool;

    public function release(string $name): void;
}
