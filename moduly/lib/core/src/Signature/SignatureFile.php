<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Signature;

use JsonException;
use PhpFasty\Moduly\Exception\SignatureException;

/**
 * `<module>/SIGNATURE`: {"alg": "ed25519"|"none", "publisher", "key": base64 pubkey, "files": {path: sha256},
 * "signature": base64}. The signed message is Canonical::encode(["files" => files, "module" => module.json]).
 * Paths are relative to the module root; SIGNATURE itself is not listed.
 */
final class SignatureFile
{
    public const NAME = 'SIGNATURE';

    /** @param array<string, string> $files */
    public function __construct(
        public readonly string $alg,
        public readonly string $publisher,
        public readonly string $key,
        public readonly array $files,
        public readonly string $signature,
    ) {
    }

    public static function parse(string $json): self
    {
        try {
            $d = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SignatureException('bad_signature_file', 'SIGNATURE is not valid JSON');
        }
        if (!is_array($d) || !isset($d['alg'], $d['files']) || !is_string($d['alg']) || !is_array($d['files'])) {
            throw new SignatureException('bad_signature_file', 'SIGNATURE is incomplete');
        }
        $files = [];
        foreach ($d['files'] as $path => $hash) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new SignatureException('bad_signature_file', 'SIGNATURE has a bad file hash');
            }
            $files[(string) $path] = $hash;
        }
        $str = static fn (string $k): string => isset($d[$k]) && is_string($d[$k]) ? $d[$k] : '';
        return new self($d['alg'], $str('publisher'), $str('key'), $files, $str('signature'));
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, string> $files
     */
    public static function message(array $module, array $files): string
    {
        ksort($files, SORT_STRING);
        return Canonical::encode(['files' => $files, 'module' => $module]);
    }

    public function toJson(): string
    {
        $files = $this->files;
        ksort($files, SORT_STRING);
        return (string) json_encode([
            'alg' => $this->alg,
            'publisher' => $this->publisher,
            'key' => $this->key,
            'files' => $files,
            'signature' => $this->signature,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
