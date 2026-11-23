<?php

/**
 * Base URL of a SitePulse server. Only https, or plain http on loopback hosts for local development; no
 * credentials, query or fragment. Requests are built from this base and fixed API paths only (no SSRF through
 * user input).
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class ServerUrl
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    private function __construct(private readonly string $base, private readonly string $host)
    {
    }

    public static function fromString(string $url): self
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ('' === $url || false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidInputException('sitepulse_invalid_url', 'The server URL must be an absolute URL, e.g. https://pulse.example.com.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidInputException('sitepulse_invalid_url', 'The server URL must not contain credentials, a query or a fragment.');
        }
        if ('https' !== $scheme && !('http' === $scheme && self::isLoopback($host))) {
            throw new InvalidInputException('sitepulse_insecure_url', 'The server URL must use https (plain http is allowed for localhost only).');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (1 !== preg_match('#^(/(?!\.+(/|$))[A-Za-z0-9._~-]+)*$#', $path)) {
            throw new InvalidInputException('sitepulse_invalid_url', 'The server URL path may contain letters, digits and . _ ~ - only.');
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return new self($scheme.'://'.$host.$port.$path, $host);
    }

    public static function isLoopback(string $host): bool
    {
        return in_array($host, self::LOOPBACK, true) || str_ends_with($host, '.localhost');
    }

    public function base(): string
    {
        return $this->base;
    }

    public function host(): string
    {
        return $this->host;
    }

    /** Absolute URL of a fixed API path ("/v1/…" or "/sitepulse.js"); path segments are validated, never raw input. */
    public function endpoint(string $path): string
    {
        if (1 !== preg_match('#^/(v1(/[A-Za-z0-9_:-][A-Za-z0-9_.:-]*)+|sitepulse\.js)$#', $path)) {
            throw new InvalidInputException('sitepulse_invalid_path', 'Unsupported SitePulse API path.');
        }

        return $this->base.$path;
    }
}
