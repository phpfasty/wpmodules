<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Migration\LockInterface;

/** Cross-request lock: add_option() is atomic (unique option_name); a stale lock expires after its TTL. */
final class OptionLock implements LockInterface
{
    public function acquire(string $name, int $ttlSeconds): bool
    {
        $key = 'moduly_lock_' . $name;
        if (add_option($key, (string) (time() + $ttlSeconds), '', false)) {
            return true;
        }
        $until = (int) get_option($key, '0');
        if ($until < time()) {
            delete_option($key);
            return add_option($key, (string) (time() + $ttlSeconds), '', false);
        }
        return false;
    }

    public function release(string $name): void
    {
        delete_option('moduly_lock_' . $name);
    }
}
