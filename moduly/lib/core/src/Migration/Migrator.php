<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Migration;

use PhpFasty\Moduly\Exception\ModulyException;

/**
 * Runs pending migrations of a scope in order under a lock. Safe to re-run: the version only advances after a
 * migration reports completion, and finished migrations are skipped.
 */
final class Migrator
{
    public function __construct(
        private readonly SchemaVersionStoreInterface $versions,
        private readonly LockInterface $lock,
        private readonly int $batchSize = 200,
        private readonly int $maxBatchesPerRun = 50,
    ) {
    }

    /**
     * @param list<MigrationInterface> $migrations
     * @return array{from: int, to: int, done: bool, ran: list<int>}
     */
    public function run(string $scope, array $migrations): array
    {
        $byVersion = [];
        foreach ($migrations as $m) {
            $v = $m->version();
            if ($v <= 0 || isset($byVersion[$v])) {
                throw new ModulyException('bad_migration', "Migration version {$v} is invalid or duplicated in {$scope}");
            }
            $byVersion[$v] = $m;
        }
        ksort($byVersion);
        $from = $this->versions->get($scope);
        $lockName = 'moduly_migrate_' . $scope;
        if (!$this->lock->acquire($lockName, 300)) {
            return ['from' => $from, 'to' => $from, 'done' => false, 'ran' => []];
        }
        $ran = [];
        $current = $from;
        $batches = 0;
        try {
            foreach ($byVersion as $v => $m) {
                if ($v <= $current) {
                    continue;
                }
                do {
                    if ($batches++ >= $this->maxBatchesPerRun) {
                        return ['from' => $from, 'to' => $current, 'done' => false, 'ran' => $ran];
                    }
                    $finished = $m->up($this->batchSize);
                } while (!$finished);
                $this->versions->set($scope, $v);
                $current = $v;
                $ran[] = $v;
            }
        } finally {
            $this->lock->release($lockName);
        }
        return ['from' => $from, 'to' => $current, 'done' => true, 'ran' => $ran];
    }
}
