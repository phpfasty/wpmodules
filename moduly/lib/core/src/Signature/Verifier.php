<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Signature;

use PhpFasty\Moduly\Exception\SignatureException;
use PhpFasty\Moduly\Manifest\Manifest;
use PhpFasty\Moduly\Package\Fs;

/**
 * Verifies that the unpacked module matches SIGNATURE exactly (no missing, extra or changed files) and that the
 * ed25519 signature over (module.json, file hashes) is made by a trusted key of the manifest publisher.
 */
final class Verifier
{
    public function __construct(private readonly Keyring $keyring)
    {
    }

    /**
     * @return array{signed: bool, key: string}
     */
    public function verify(string $moduleDir, Manifest $manifest): array
    {
        $sigPath = $moduleDir . '/' . SignatureFile::NAME;
        if (!is_file($sigPath)) {
            throw new SignatureException('missing_signature', 'Package has no SIGNATURE file');
        }
        $sig = SignatureFile::parse((string) file_get_contents($sigPath));

        $actual = [];
        foreach (Fs::files($moduleDir) as $rel) {
            if ($rel === SignatureFile::NAME) {
                continue;
            }
            $actual[$rel] = (string) hash_file('sha256', $moduleDir . '/' . $rel);
        }
        $expected = $sig->files;
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        if (array_keys($expected) !== array_keys($actual)) {
            throw new SignatureException('file_list_mismatch', 'Package files do not match SIGNATURE');
        }
        foreach ($expected as $rel => $hash) {
            if (!hash_equals($hash, $actual[$rel])) {
                throw new SignatureException('hash_mismatch', 'A package file was modified after signing');
            }
        }

        if ($sig->alg === 'none') {
            if (!$this->keyring->allowUnsigned) {
                throw new SignatureException('unsigned', 'Unsigned modules are not allowed on this site');
            }
            return ['signed' => false, 'key' => ''];
        }
        if ($sig->alg !== 'ed25519') {
            throw new SignatureException('bad_alg', 'Unsupported signature algorithm');
        }
        if ($sig->publisher !== $manifest->publisher) {
            throw new SignatureException('publisher_mismatch', 'SIGNATURE publisher differs from module.json');
        }
        if (!$this->keyring->isTrusted($manifest->publisher, $sig->key)) {
            throw new SignatureException('untrusted_key', 'Module is signed by a key that is not trusted');
        }
        $pub = base64_decode($sig->key, true);
        $raw = base64_decode($sig->signature, true);
        if ($pub === false || $raw === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new SignatureException('bad_signature', 'Signature or key is malformed');
        }
        $message = SignatureFile::message($manifest->raw, $sig->files);
        if (!sodium_crypto_sign_verify_detached($raw, $message, $pub)) {
            throw new SignatureException('bad_signature', 'Signature does not verify');
        }
        return ['signed' => true, 'key' => $sig->key];
    }
}
