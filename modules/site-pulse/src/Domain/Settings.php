<?php

/**
 * Stored module settings (option `sitepulse_settings`, the secret lives in its own option). Sanitizing collects
 * human-readable errors instead of throwing so the settings form can keep the previous valid values.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class Settings
{
    public const DEFAULTS = [
        'region' => 'custom',
        'server_url' => '',
        'tracker_url' => '',
        'public_key' => '',
        'tracker_enabled' => false,
        'consent_mode' => 'require',
        'auto_analyze' => false,
    ];

    private function __construct(
        public readonly Region $region,
        public readonly string $serverUrl,
        public readonly string $trackerUrl,
        public readonly string $publicKey,
        public readonly bool $trackerEnabled,
        public readonly ConsentMode $consentMode,
        public readonly bool $autoAnalyze,
    ) {
    }

    public static function fromStored(mixed $stored): self
    {
        $s = is_array($stored) ? $stored : [];
        $region = Region::fromInput($s['region'] ?? null);
        $custom = Region::Custom === $region;

        return new self(
            $region,
            $custom ? (is_string($s['server_url'] ?? null) ? $s['server_url'] : '') : $region->apiUrl(),
            $custom ? (is_string($s['tracker_url'] ?? null) ? $s['tracker_url'] : '') : $region->trackerUrl(),
            is_string($s['public_key'] ?? null) ? $s['public_key'] : '',
            true === ($s['tracker_enabled'] ?? null),
            ConsentMode::fromInput($s['consent_mode'] ?? null),
            true === ($s['auto_analyze'] ?? null),
        );
    }

    /**
     * Form input → stored array. Invalid fields keep their previous value and add an error. A region preset sets
     * both URLs (the form fields are ignored); switching to the EU preset turns consent mode on.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array{0: array{region: string, server_url: string, tracker_url: string, public_key: string, tracker_enabled: bool,
     *                        consent_mode: string, auto_analyze: bool}, 1: list<string>}
     */
    public static function sanitize(array $input, self $previous): array
    {
        $errors = [];
        $out = $previous->toArray();
        $region = Region::fromInput($input['region'] ?? null);
        $out['region'] = $region->value;
        foreach (['server_url', 'tracker_url'] as $field) {
            if (Region::Custom !== $region) {
                $out[$field] = 'server_url' === $field ? $region->apiUrl() : $region->trackerUrl();
                continue;
            }
            $raw = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            if ('' === $raw) {
                $out[$field] = '';
                continue;
            }
            try {
                $out[$field] = ServerUrl::fromString($raw)->base();
            } catch (InvalidInputException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $pk = is_string($input['public_key'] ?? null) ? trim($input['public_key']) : '';
        if ('' === $pk || ApiKey::isValid(ApiKey::PUBLIC, $pk)) {
            $out['public_key'] = $pk;
        } else {
            $errors[] = 'The public key must look like pk_live_…; the previous key was kept.';
        }
        $out['tracker_enabled'] = self::checked($input['tracker_enabled'] ?? null);
        $out['consent_mode'] = ConsentMode::fromInput($input['consent_mode'] ?? null)->value;
        if ($region->requiresConsent() && $region !== $previous->region) {
            $out['consent_mode'] = ConsentMode::WaitForConsent->value;
        }
        $out['auto_analyze'] = self::checked($input['auto_analyze'] ?? null);

        return [$out, $errors];
    }

    public function server(): ?ServerUrl
    {
        try {
            return '' === $this->serverUrl ? null : ServerUrl::fromString($this->serverUrl);
        } catch (InvalidInputException) {
            return null;
        }
    }

    /** Script/ingest origin: the tracker URL when set (separate ingest host), otherwise the server URL. */
    public function trackerServer(): ?ServerUrl
    {
        try {
            return '' === $this->trackerUrl ? $this->server() : ServerUrl::fromString($this->trackerUrl);
        } catch (InvalidInputException) {
            return null;
        }
    }

    /** The tag to print, or null when the tracker is off or incomplete. */
    public function trackerTag(): ?TrackerTag
    {
        $server = $this->trackerServer();
        if (!$this->trackerEnabled || null === $server || !ApiKey::isValid(ApiKey::PUBLIC, $this->publicKey)) {
            return null;
        }

        return TrackerTag::create($server, $this->publicKey);
    }

    /**
     * @return array{region: string, server_url: string, tracker_url: string, public_key: string, tracker_enabled: bool,
     *               consent_mode: string, auto_analyze: bool}
     */
    public function toArray(): array
    {
        return [
            'region' => $this->region->value,
            'server_url' => $this->serverUrl,
            'tracker_url' => $this->trackerUrl,
            'public_key' => $this->publicKey,
            'tracker_enabled' => $this->trackerEnabled,
            'consent_mode' => $this->consentMode->value,
            'auto_analyze' => $this->autoAnalyze,
        ];
    }

    private static function checked(mixed $value): bool
    {
        return true === $value || '1' === $value || 'on' === $value;
    }
}
