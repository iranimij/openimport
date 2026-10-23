<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Google;

use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Access tokens for Google's APIs: a service account signs a JWT (RS256) and trades it for a token; a signed-in
 * account trades its refresh token. Tokens are kept in memory until shortly before they expire.
 */
class GoogleAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive';
    public const SERVICE_ACCOUNT = 'service_account';
    public const OAUTH = 'oauth';

    /**
     * @var array<string, array{0: string, 1: int}> cache key => token and its expiry
     */
    private array $tokens = [];

    /**
     * @param ApiClient $api
     * @param GoogleEndpoints $endpoints
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly GoogleEndpoints $endpoints
    ) {
    }

    /**
     * @param array<string, string> $options auth, service_account_json | client_id, client_secret, refresh_token
     * @return string an access token
     * @throws PipelineException
     */
    public function token(array $options): string
    {
        $key = hash('sha256', (string) json_encode($options));
        if (isset($this->tokens[$key]) && $this->tokens[$key][1] > time() + 60) {
            return $this->tokens[$key][0];
        }
        $answer = ($options['auth'] ?? '') === self::OAUTH
            ? $this->refresh($options)
            : $this->serviceAccount((string) ($options['service_account_json'] ?? ''));
        $token = (string) ($answer['access_token'] ?? '');
        if ($token === '') {
            throw new PipelineException('Google answered without an access token.');
        }
        $this->tokens[$key] = [$token, time() + (int) ($answer['expires_in'] ?? 3600)];

        return $token;
    }

    /**
     * The service account's e-mail address, to share folders with.
     *
     * @param string $json the key file
     * @return string
     * @throws PipelineException
     */
    public function serviceAccountEmail(string $json): string
    {
        return (string) $this->key($json)['client_email'];
    }

    /**
     * Trades the code of the consent page for tokens (with the PKCE verifier).
     *
     * @param array<string, string> $options client_id, client_secret
     * @param string $code
     * @param string $verifier
     * @param string $redirectUri
     * @return array<string, mixed> access_token, refresh_token, expires_in
     * @throws PipelineException
     */
    public function exchange(array $options, string $code, string $verifier, string $redirectUri): array
    {
        return $this->post($this->endpoints->token, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => (string) ($options['client_id'] ?? ''),
            'client_secret' => (string) ($options['client_secret'] ?? ''),
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * The address of Google's consent page.
     *
     * @param string $clientId
     * @param string $redirectUri
     * @param string $state
     * @param string $verifier PKCE code verifier (the page gets its S256 challenge)
     * @return string
     */
    public function consentUrl(string $clientId, string $redirectUri, string $state, string $verifier): string
    {
        return $this->endpoints->authorize . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param string $verifier
     * @return string base64url(SHA-256(verifier))
     */
    public function challenge(string $verifier): string
    {
        return $this->base64url(hash('sha256', $verifier, true));
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     * @throws PipelineException
     */
    private function refresh(array $options): array
    {
        if (($options['refresh_token'] ?? '') === '') {
            throw new PipelineException(
                'The Google connection is not signed in yet: open it and click "Sign in with Google".'
            );
        }

        return $this->post($this->endpoints->token, [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $options['refresh_token'],
            'client_id' => (string) ($options['client_id'] ?? ''),
            'client_secret' => (string) ($options['client_secret'] ?? ''),
        ]);
    }

    /**
     * @param string $json
     * @return array<string, mixed>
     * @throws PipelineException
     */
    private function serviceAccount(string $json): array
    {
        $key = $this->key($json);
        $now = time();
        $header = $this->base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64url((string) json_encode([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => $key['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, (string) $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new PipelineException(
                'The private key of the service account cannot sign (is the key file complete?).'
            );
        }

        return $this->post((string) $key['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $header . '.' . $claims . '.' . $this->base64url($signature),
        ]);
    }

    /**
     * @param string $json
     * @return array<string, mixed> client_email, private_key, token_uri
     * @throws PipelineException
     */
    private function key(string $json): array
    {
        $key = json_decode($json, true);
        if (!is_array($key) || ($key['type'] ?? '') !== 'service_account' || empty($key['client_email'])
            || empty($key['private_key'])
        ) {
            throw new PipelineException(
                'Paste the JSON key of a Google service account (Google Cloud console › IAM › Service accounts '
                . '› Keys).'
            );
        }
        $key['token_uri'] = (string) ($key['token_uri'] ?? '') ?: $this->endpoints->token;

        return $key;
    }

    /**
     * @param string $url
     * @param array<string, string> $fields
     * @return array<string, mixed>
     * @throws PipelineException
     */
    private function post(string $url, array $fields): array
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
            throw new PipelineException($description !== ''
                ? sprintf('Google refused the login: %s (%s)', $error, $description)
                : sprintf('Google refused the login: %s', $error));
        }

        return $data;
    }

    /**
     * @param string $data
     * @return string
     */
    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
