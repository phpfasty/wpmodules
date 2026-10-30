<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Signature;

use PhpFasty\Moduly\Manifest\ManifestValidator;
use PhpFasty\Moduly\Package\Fs;

/**
 * Publisher-side: hashes every file of a module directory and writes SIGNATURE (ed25519, or alg "none"
 * for local development). Used by build tooling, never at runtime on a site.
 */
final class Signer
{
    /**
     * @param string|null $secretKey base64 ed25519 secret key (64 bytes); null = unsigned ("none")
     */
    public static function sign(string $moduleDir, ?string $secretKey): SignatureFile
    {
        $json = (string) file_get_contents($moduleDir . '/module.json');
        $manifest = (new ManifestValidator())->fromJson($json, basename($moduleDir));
        $files = [];
        foreach (Fs::files($moduleDir) as $rel) {
            if ($rel !== SignatureFile::NAME) {
                $files[$rel] = (string) hash_file('sha256', $moduleDir . '/' . $rel);
            }
        }
        ksort($files, SORT_STRING);
        if ($secretKey === null) {
            $sig = new SignatureFile('none', $manifest->publisher, '', $files, '');
        } else {
            $sk = base64_decode($secretKey, true);
            if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new \InvalidArgumentException('Secret key must be a base64 ed25519 secret key');
            }
            $message = SignatureFile::message($manifest->raw, $files);
            $sig = new SignatureFile(
                'ed25519',
                $manifest->publisher,
                base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk)),
                $files,
                base64_encode(sodium_crypto_sign_detached($message, $sk)),
            );
        }
        file_put_contents($moduleDir . '/' . SignatureFile::NAME, $sig->toJson());
        return $sig;
    }
}
