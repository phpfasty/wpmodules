<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Package;

use PhpFasty\Moduly\Manifest\Manifest;

final class InstallResult
{
    public function __construct(
        public readonly Manifest $manifest,
        public readonly string $path,
        public readonly bool $signed,
        public readonly string $key,
        public readonly bool $replaced,
    ) {
    }
}
