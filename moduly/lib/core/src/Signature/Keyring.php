<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Signature;

/**
 * Trusted publisher keys (ed25519 public keys, base64) + revocations. Unsigned packages are only accepted
 * when explicitly allowed (development hosts).
 */
final class Keyring
{
    /**
     * @param array<string, list<string>> $trusted publisher => base64 public keys
     * @param list<string> $revoked base64 public keys
     */
    public function __construct(
        private readonly array $trusted = [],
        private readonly array $revoked = [],
        public readonly bool $allowUnsigned = false,
    ) {
    }

    public function isTrusted(string $publisher, string $key): bool
    {
        if ($key === '' || in_array($key, $this->revoked, true)) {
            return false;
        }
        return in_array($key, $this->trusted[$publisher] ?? [], true);
    }
}
