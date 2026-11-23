<?php

/**
 * WordPress adapter of the client port: wp_remote_request to the configured server only, fixed API paths,
 * no redirects, bounded time and response size. The secret goes into the Authorization header and nowhere else.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

use PhpFasty\Module\SitePulse\Domain\ClientInterface;
use PhpFasty\Module\SitePulse\Domain\HttpResponse;
use PhpFasty\Module\SitePulse\Domain\ServerUrl;
use PhpFasty\Module\SitePulse\Domain\UpstreamException;

final class WpHttpClient implements ClientInterface
{
    public const USER_AGENT = 'SitePulse-WordPress/0.1.0';

    public function __construct(
        private readonly ServerUrl $server,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $timeout = 15,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['server' => $this->server->base(), 'secret' => '[hidden]'];
    }

    public function request(string $method, string $path, array $query = [], ?array $json = null): HttpResponse
    {
        $url = $this->server->endpoint($path);
        if ([] !== $query) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $loopback = ServerUrl::isLoopback($this->server->host());
        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'redirection' => 0,
            'limit_response_size' => 4 * 1024 * 1024,
            'user-agent' => self::USER_AGENT,
            // Public servers go through WordPress' SSRF guard; loopback is allowed for local development only.
            'reject_unsafe_urls' => (bool) apply_filters('sitepulse_reject_unsafe_urls', !$loopback, $this->server->base()),
            'headers' => [
                'Authorization' => 'Bearer '.$this->secret,
                'Accept' => 'application/json',
            ],
        ];
        if (null !== $json) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = (string) wp_json_encode($json);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new UpstreamException('sitepulse_unreachable', sprintf('Could not reach the SitePulse server (%s).', (string) $response->get_error_code()), 502);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $retry = wp_remote_retrieve_header($response, 'retry-after');

        return new HttpResponse($status, is_array($body) ? $body : [], is_string($retry) && ctype_digit($retry) ? (int) $retry : 0);
    }
}
