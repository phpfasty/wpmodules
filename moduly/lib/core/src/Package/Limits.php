<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Package;

/**
 * Upload / unpack limits (TZ §5.1). Defaults: 10 MB zip, 2000 entries, 50 MB unpacked, ratio 100.
 */
final class Limits
{
    public const EXTENSIONS = [
        'php', 'json', 'js', 'mjs', 'css', 'map', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'ico',
        'woff', 'woff2', 'txt', 'md', 'latte', 'twig', 'mo', 'po', 'pot', 'html', 'xml',
    ];
    /** Files at the module root that have no extension. */
    public const BARE_FILES = ['SIGNATURE', 'LICENSE', 'README', 'CHANGELOG'];

    public function __construct(
        public readonly int $maxZipBytes = 10 * 1024 * 1024,
        public readonly int $maxEntries = 2000,
        public readonly int $maxUnpackedBytes = 50 * 1024 * 1024,
        public readonly int $maxRatio = 100,
        public readonly int $maxPathLength = 200,
    ) {
    }
}
