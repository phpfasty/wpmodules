<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Manifest;

use PhpFasty\Moduly\Version\Constraint;
use PhpFasty\Moduly\Version\Semver;

/**
 * Validated, immutable `module.json`. Build only through ManifestValidator.
 */
final class Manifest
{
    /**
     * @param array<string, Constraint> $requiresHost host name (wp, …) => constraint
     * @param array<string, string> $requiresModules id => constraint
     * @param list<string> $permissions
     * @param list<string> $features
     * @param list<string> $types
     * @param list<string> $capabilities
     * @param array<string, string> $psr4 namespace prefix (with trailing \) => relative dir
     * @param list<array{type: string, name: string, method: string, priority: int, args: int}> $hooks
     * @param array<string, mixed> $settings JSON Schema subset
     * @param array<string, mixed> $raw decoded module.json, as signed
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Semver $version,
        public readonly string $publisher,
        public readonly string $license,
        public readonly string $description,
        public readonly ?Constraint $requiresCore,
        public readonly ?Constraint $requiresPhp,
        public readonly array $requiresHost,
        public readonly array $requiresModules,
        public readonly array $features,
        public readonly array $types,
        public readonly array $capabilities,
        public readonly array $permissions,
        public readonly string $entry,
        public readonly array $psr4,
        public readonly array $hooks,
        public readonly array $settings,
        public readonly array $raw,
    ) {
    }
}
