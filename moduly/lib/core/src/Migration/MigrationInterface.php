<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Migration;

/**
 * Forward-only migration (ADR-002): classes named Migration_NNNN_<Name>, `up()` only, idempotent, never deletes data.
 * Long work is split into batches: `up()` returns false while there is more to do (the runner calls it again).
 */
interface MigrationInterface
{
    /** Sequence number NNNN (> 0), unique within a scope. */
    public function version(): int;

    /** @return bool true when finished, false when another batch is needed */
    public function up(int $batchSize): bool;
}
