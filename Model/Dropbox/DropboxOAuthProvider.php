<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Dropbox;

use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\OAuth\OAuthProviderInterface;
use Iranimij\OpenImport\Model\OAuth\OAuthTokens;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * "Sign in with Dropbox" with the shop's own Dropbox app (offline access, PKCE).
 */
class DropboxOAuthProvider implements OAuthProviderInterface
{
    /**
     * @param OAuthTokens $tokens
     * @param ApiClient $api
     * @param DropboxEndpoints $endpoints
     */
    public function __construct(
        private readonly OAuthTokens $tokens,
        private readonly ApiClient $api,
        private readonly DropboxEndpoints $endpoints
    ) {
    }

    /**
     * @inheritDoc
     */
    public function signsIn(array $settings): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function consentUrl(
        array $settings,
        string $clientId,
        string $redirectUri,
        string $state,
        string $verifier
    ): string {
        return $this->endpoints->authorize . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'token_access_type' => 'offline',
            'state' => $state,
            'code_challenge' => $this->tokens->challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @inheritDoc
     */
    public function exchange(
        array $settings,
        string $clientId,
        string $clientSecret,
        string $code,
        string $verifier,
        string $redirectUri
    ): array {
        return $this->tokens->post($this->endpoints->token, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ], 'Dropbox');
    }

    /**
     * @inheritDoc
     */
    public function account(string $accessToken): string
    {
        try {
            $answer = $this->api->send(
                'POST',
                $this->endpoints->api . '/users/get_current_account',
                ['Authorization' => 'Bearer ' . $accessToken]
            );
        } catch (PipelineException $exception) {
            return '';
        }

        return (string) ($answer->json()['email'] ?? '');
    }
}
