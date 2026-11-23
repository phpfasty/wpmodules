<?php

/**
 * The tracker tag exactly as diagd `GET /v1/sites/{id}/install` prints it:
 * `<script src="<public_url>/sitepulse.js" data-site="pk_live_…" defer></script>`.
 * The tracker posts to `<origin of src>/v1/ingest`, so the script origin is also the ingest origin.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class TrackerTag
{
    private function __construct(public readonly string $src, public readonly string $siteKey)
    {
    }

    public static function create(ServerUrl $server, string $publicKey): self
    {
        if (!ApiKey::isValid(ApiKey::PUBLIC, $publicKey)) {
            throw new InvalidInputException('sitepulse_invalid_public_key', 'The public key must look like pk_live_….');
        }

        return new self($server->endpoint('/sitepulse.js'), $publicKey);
    }

    public function html(): string
    {
        return sprintf(
            '<script src="%s" data-site="%s" defer></script>',
            htmlspecialchars($this->src, ENT_QUOTES),
            htmlspecialchars($this->siteKey, ENT_QUOTES),
        );
    }
}
