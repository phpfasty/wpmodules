<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Kernel;

/**
 * Port: where the host keeps module records (WordPress: one non-autoloaded option).
 */
interface StateStoreInterface
{
    /** @return array<string, ModuleRecord> */
    public function load(): array;

    /** @param array<string, ModuleRecord> $records */
    public function save(array $records): void;
}
