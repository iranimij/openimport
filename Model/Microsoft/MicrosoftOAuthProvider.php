<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Microsoft;

use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\OAuth\OAuthProviderInterface;
use Iranimij\OpenImport\Model\OAuth\OAuthTokens;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * "Sign in with Microsoft" with the shop's own app registration (Microsoft Entra ID), PKCE.
 */
class MicrosoftOAuthProvider implements OAuthProviderInterface
{
    /**
     * @param OAuthTokens $tokens
     * @param ApiClient $api
     * @param MicrosoftEndpoints $endpoints
     */
    public function __construct(
        private readonly OAuthTokens $tokens,
        private readonly ApiClient $api,
        private readonly MicrosoftEndpoints $endpoints
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
        return $this->endpoints->oauth((string) ($settings['tenant'] ?? ''), 'authorize') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => $this->endpoints->scope((string) ($settings['site'] ?? '')),
            'prompt' => 'select_account',
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
        return $this->tokens->post($this->endpoints->oauth((string) ($settings['tenant'] ?? ''), 'token'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'scope' => $this->endpoints->scope((string) ($settings['site'] ?? '')),
        ], 'Microsoft');
    }

    /**
     * @inheritDoc
     */
    public function account(string $accessToken): string
    {
        try {
            $answer = $this->api->send(
                'GET',
                $this->endpoints->graph . '/me?$select=userPrincipalName,mail',
                ['Authorization' => 'Bearer ' . $accessToken]
            );
        } catch (PipelineException $exception) {
            return '';
        }
        $me = $answer->json();

        return (string) ($me['mail'] ?? '') ?: (string) ($me['userPrincipalName'] ?? '');
    }
}
