<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

/**
 * Golden-file snapshots (tests/snapshots/). A missing snapshot fails; `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`
 * (re)writes them: review the diff like any code change.
 */
trait SnapshotAssertions
{
    protected function assertMatchesSnapshot(string $name, string $actual): void
    {
        $file = __DIR__.'/snapshots/'.$name;
        if ('1' === getenv('UPDATE_SNAPSHOTS')) {
            file_put_contents($file, $actual);
        }
        $this->assertFileExists($file, "snapshot $name missing: run UPDATE_SNAPSHOTS=1 vendor/bin/phpunit");
        $this->assertSame((string) file_get_contents($file), $actual, "snapshot $name differs");
    }

    /** View data contract (`slots` filter payload) as stable, pretty JSON. */
    protected function assertSlotsSnapshot(string $name, mixed $slots): void
    {
        $this->assertMatchesSnapshot($name.'.json', json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }
}
