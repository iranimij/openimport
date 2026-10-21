<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\OAuth;

use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Form posts to OAuth token endpoints (code exchange, refresh) and access tokens kept in memory until shortly before
 * they expire.
 */
class OAuthTokens
{
    /**
     * @var array<string, array{0: string, 1: int}>
     */
    private array $cache = [];

    /**
     * @param ApiClient $api
     */
    public function __construct(
        private readonly ApiClient $api
    ) {
    }

    /**
     * An access token from a refresh token.
     *
     * @param string $url token endpoint
     * @param array<string, string> $fields client_id, client_secret, refresh_token (+ scope)
     * @param string $service for messages ("Dropbox")
     * @return string
     * @throws PipelineException
     */
    public function refresh(string $url, array $fields, string $service): string
    {
        if (($fields['refresh_token'] ?? '') === '') {
            throw new PipelineException(sprintf(
                'The %s connection is not signed in yet: open it and click "Sign in".',
                $service
            ));
        }
        $key = hash('sha256', $url . "\n" . json_encode($fields));
        if (isset($this->cache[$key]) && $this->cache[$key][1] > time() + 60) {
            return $this->cache[$key][0];
        }
        $answer = $this->post($url, ['grant_type' => 'refresh_token'] + $fields, $service);
        $token = (string) ($answer['access_token'] ?? '');
        if ($token === '') {
            throw new PipelineException(sprintf('%s answered without an access token.', $service));
        }
        $this->cache[$key] = [$token, time() + (int) ($answer['expires_in'] ?? 3600)];

        return $token;
    }

    /**
     * An access token of the client itself (OAuth 2 client credentials grant), for APIs without a person signing in.
     *
     * @param string $url token endpoint
     * @param array<string, string> $fields client_id, client_secret (+ scope)
     * @param string $service for messages
     * @return string
     * @throws PipelineException
     */
    public function clientCredentials(string $url, array $fields, string $service): string
    {
        $key = hash('sha256', 'cc' . $url . "\n" . json_encode($fields));
        if (isset($this->cache[$key]) && $this->cache[$key][1] > time() + 60) {
            return $this->cache[$key][0];
        }
        $answer = $this->post($url, ['grant_type' => 'client_credentials'] + array_filter($fields), $service);
        $token = (string) ($answer['access_token'] ?? '');
        if ($token === '') {
            throw new PipelineException(sprintf('%s answered without an access token.', $service));
        }
        $this->cache[$key] = [$token, time() + (int) ($answer['expires_in'] ?? 3600)];

        return $token;
    }

    /**
     * @param string $url
     * @param array<string, string> $fields
     * @param string $service
     * @return array<string, mixed>
     * @throws PipelineException
     */
    public function post(string $url, array $fields, string $service): array
    {
        $answer = $this->api->send(
            'POST',
            $url,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );
        $data = $answer->json();
        if (!$answer->ok()) {
            $error = (string) ($data['error'] ?? 'HTTP ' . $answer->status);
            $description = (string) ($data['error_description'] ?? '');
            throw new PipelineException(sprintf(
                '%s refused the login: %s%s',
                $service,
                $error,
                $description !== '' ? ' (' . $description . ')' : ''
            ));
        }

        return $data;
    }

    /**
     * @param string $verifier
     * @return string base64url(SHA-256(verifier))
     */
    public function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
