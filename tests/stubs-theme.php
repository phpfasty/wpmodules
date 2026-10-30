<?php

declare(strict_types=1);

namespace WPFasty\Core;

if (!interface_exists(BootableServiceInterface::class)) {
    /** Theme interface (wp-fasty latte) the types Registry implements; stub for analysis and unit tests. */
    interface BootableServiceInterface
    {
        public function boot(): void;
    }
}
