<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\OAuth;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * A service whose connections sign in with the shop's own OAuth client (Google, Dropbox, Microsoft).
 */
interface OAuthProviderInterface
{
    /**
     * Whether a connection with these settings signs in (Google also has service accounts).
     *
     * @param array<string, mixed> $settings
     * @return bool
     */
    public function signsIn(array $settings): bool;

    /**
     * @param array<string, mixed> $settings the connection's settings (tenant for Microsoft)
     * @param string $clientId
     * @param string $redirectUri
     * @param string $state
     * @param string $verifier PKCE verifier (the page gets its S256 challenge)
     * @return string the consent page
     */
    public function consentUrl(
        array $settings,
        string $clientId,
        string $redirectUri,
        string $state,
        string $verifier
    ): string;

    /**
     * Trades the code for tokens.
     *
     * @param array<string, mixed> $settings
     * @param string $clientId
     * @param string $clientSecret
     * @param string $code
     * @param string $verifier
     * @param string $redirectUri
     * @return array<string, mixed> access_token, refresh_token
     * @throws PipelineException
     */
    public function exchange(
        array $settings,
        string $clientId,
        string $clientSecret,
        string $code,
        string $verifier,
        string $redirectUri
    ): array;

    /**
     * @param string $accessToken
     * @return string the signed-in account ('' when the service does not tell)
     */
    public function account(string $accessToken): string;
}
