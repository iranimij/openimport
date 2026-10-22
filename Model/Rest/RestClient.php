<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Rest;

use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Http\ApiResponse;
use Iranimij\OpenImport\Model\OAuth\OAuthTokens;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;

/**
 * Requests to a REST API of a saved connection: the base address, the login (header token, basic, OAuth 2 client
 * credentials) and retries when the API asks to slow down (429, 503 with Retry-After).
 */
class RestClient
{
    public const AUTH_NONE = 'none';
    public const AUTH_HEADER = 'header';
    public const AUTH_BASIC = 'basic';
    public const AUTH_OAUTH2 = 'oauth2';

    private const RETRIES = 3;
    private const RETRY_STATUSES = [429, 502, 503, 504];

    /**
     * @var callable(int): void
     */
    private $wait;

    /**
     * @param ApiClient $api
     * @param OAuthTokens $tokens
     * @param Secrets $secrets
     * @param callable(int): void|null $wait seconds to wait before a retry (tests pass a recorder)
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly OAuthTokens $tokens,
        private readonly Secrets $secrets,
        ?callable $wait = null
    ) {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- waits as the API asked (Retry-After)
        $this->wait = $wait ?? static fn (int $seconds) => sleep($seconds);
    }

    /**
     * The full address of a path below the connection's base address. An absolute address (a "next page" link) must
     * be on the same host.
     *
     * @param array<string, mixed> $config settings with the connection's base_url
     * @param string $path
     * @return string
     * @throws PipelineException
     */
    public function url(array $config, string $path): string
    {
        $base = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        if (preg_match('#^https?://[^/]+#i', $base, $baseHost) !== 1) {
            throw new PipelineException($base === '' && empty($config['connection_id'])
                ? 'Choose a saved REST connection under "Saved connection" (add one under System › Data Transfer › '
                    . 'OpenImport Connections).'
                : 'The REST connection needs a base address such as https://api.example.com/v1.');
        }
        $path = trim($path);
        if (preg_match('#^https?://[^/]+#i', $path, $host) === 1) {
            if (strcasecmp($host[0], $baseHost[0]) !== 0) {
                throw new PipelineException(sprintf('%s is not on the API\'s host %s.', $path, $baseHost[0]));
            }

            return $path;
        }

        return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * @param array<string, mixed> $config
     * @param string $method
     * @param string $path path below the base address, or an absolute address on its host
     * @param array<string, string> $headers
     * @param string|null $body
     * @param string|null $bodyFile
     * @param string|null $saveTo
     * @return ApiResponse
     * @throws PipelineException
     */
    public function send(
        array $config,
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        ?string $bodyFile = null,
        ?string $saveTo = null
    ): ApiResponse {
        $url = $this->url($config, $path);
        $headers += ['Accept' => 'application/json'] + $this->login($config);
        $timeout = max(5, min(600, (int) ($config['timeout'] ?? 60)));
        for ($attempt = 0;; $attempt++) {
            $answer = $this->api->send($method, $url, $headers, $body, $bodyFile, $saveTo, $timeout);
            if (!in_array($answer->status, self::RETRY_STATUSES, true) || $attempt >= self::RETRIES) {
                return $answer;
            }
            $after = (int) ($answer->headers['retry-after'] ?? 0);
            ($this->wait)(max(1, min(30, $after > 0 ? $after : 2 ** $attempt)));
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string> the login header
     * @throws PipelineException
     */
    private function login(array $config): array
    {
        $text = static fn (string $key): string
            => is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';

        return match ($text('auth_rest') ?: self::AUTH_NONE) {
            self::AUTH_HEADER => [
                ($text('header_name') ?: 'Authorization') => $this->secrets->get($config, 'token'),
            ],
            self::AUTH_BASIC => [
                'Authorization' => 'Basic ' . base64_encode(
                    $text('username') . ':' . $this->secrets->get($config, 'password')
                ),
            ],
            self::AUTH_OAUTH2 => [
                'Authorization' => 'Bearer ' . $this->tokens->clientCredentials($text('token_url'), [
                    'client_id' => $text('client_id'),
                    'client_secret' => $this->secrets->get($config, 'client_secret'),
                    'scope' => $text('scope'),
                ], 'The API'),
            ],
            default => [],
        };
    }
}
