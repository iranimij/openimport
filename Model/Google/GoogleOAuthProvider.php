<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Google;

use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\OAuth\OAuthProviderInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * "Sign in with Google" (connections that log in as a Google account, not as a service account).
 */
class GoogleOAuthProvider implements OAuthProviderInterface
{
    /**
     * @param GoogleAuth $auth
     * @param ApiClient $api
     * @param GoogleEndpoints $endpoints
     */
    public function __construct(
        private readonly GoogleAuth $auth,
        private readonly ApiClient $api,
        private readonly GoogleEndpoints $endpoints
    ) {
    }

    /**
     * @inheritDoc
     */
    public function signsIn(array $settings): bool
    {
        return ($settings['auth_google'] ?? '') === GoogleAuth::OAUTH;
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
        return $this->auth->consentUrl($clientId, $redirectUri, $state, $verifier);
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
        return $this->auth->exchange(
            ['client_id' => $clientId, 'client_secret' => $clientSecret],
            $code,
            $verifier,
            $redirectUri
        );
    }

    /**
     * @inheritDoc
     */
    public function account(string $accessToken): string
    {
        try {
            $answer = $this->api->send(
                'GET',
                $this->endpoints->drive . '/about?fields=user(emailAddress)',
                ['Authorization' => 'Bearer ' . $accessToken]
            );
        } catch (PipelineException $exception) {
            return '';
        }

        return (string) ($answer->json()['user']['emailAddress'] ?? '');
    }
}
