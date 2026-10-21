<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Google;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Math\Random;

/**
 * "Sign in with Google" for a connection: the consent page gets a random state ("<connection id>.<random>") and a
 * PKCE challenge; the connection keeps the state's hash and the encrypted verifier for 15 minutes. The answer
 * (code + state) is traded for a refresh token, which is stored encrypted with the connection.
 */
class OAuthFlow
{
    public const CALLBACK_ROUTE = 'openimport/connection/oauthCallback';
    public const PENDING = 'oauth_pending';
    private const LIFETIME = 900;

    /**
     * @param ConnectionRepositoryInterface $connections
     * @param GoogleAuth $auth
     * @param Secrets $secrets
     * @param EncryptorInterface $encryptor
     * @param UrlInterface $url
     * @param Random $random
     * @param ApiClient $api
     * @param GoogleEndpoints $endpoints
     */
    public function __construct(
        private readonly ConnectionRepositoryInterface $connections,
        private readonly GoogleAuth $auth,
        private readonly Secrets $secrets,
        private readonly EncryptorInterface $encryptor,
        private readonly UrlInterface $url,
        private readonly Random $random,
        private readonly ApiClient $api,
        private readonly GoogleEndpoints $endpoints
    ) {
    }

    /**
     * The address to register as "Authorized redirect URI" of the OAuth client in Google Cloud.
     *
     * @return string
     */
    public function redirectUri(): string
    {
        return $this->url->getUrl(self::CALLBACK_ROUTE, ['_nosecret' => true]);
    }

    /**
     * @param ConnectionInterface $connection
     * @return string the consent page to send the admin to
     * @throws PipelineException when the connection has no OAuth client yet
     */
    public function start(ConnectionInterface $connection): string
    {
        $settings = $connection->getSettings();
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        if ($connection->getType() !== 'google_drive' || ($settings['auth_google'] ?? '') !== GoogleAuth::OAUTH
            || $clientId === '' || $this->secrets->get($settings, 'client_secret') === ''
        ) {
            throw new PipelineException('Save the client ID and client secret of the connection first.');
        }
        $state = $connection->getConnectionId() . '.' . $this->random->getRandomString(32);
        $verifier = $this->random->getRandomString(
            64,
            Random::CHARS_LOWERS . Random::CHARS_UPPERS . Random::CHARS_DIGITS
        );
        $settings[self::PENDING] = [
            'state' => hash('sha256', $state),
            'verifier' => $this->encryptor->encrypt($verifier),
            'at' => time(),
        ];
        $this->connections->save($connection->setSettings($settings));

        return $this->auth->consentUrl($clientId, $this->redirectUri(), $state, $verifier);
    }

    /**
     * Stores the refresh token Google gave for this state and code.
     *
     * @param string $state
     * @param string $code
     * @return ConnectionInterface the signed-in connection
     * @throws PipelineException
     */
    public function finish(string $state, string $code): ConnectionInterface
    {
        $unknown = 'This sign-in is unknown or older than 15 minutes. Start it again from the connection.';
        try {
            $connection = $this->connections->getById((int) strtok($state, '.'));
        } catch (NoSuchEntityException $exception) {
            throw new PipelineException($unknown);
        }
        $settings = $connection->getSettings();
        $pending = is_array($settings[self::PENDING] ?? null) ? $settings[self::PENDING] : [];
        if (!hash_equals((string) ($pending['state'] ?? ''), hash('sha256', $state))
            || (int) ($pending['at'] ?? 0) < time() - self::LIFETIME
        ) {
            throw new PipelineException($unknown);
        }
        unset($settings[self::PENDING]);
        $tokens = $this->auth->exchange(
            [
                'client_id' => (string) ($settings['client_id'] ?? ''),
                'client_secret' => $this->secrets->get($settings, 'client_secret'),
            ],
            $code,
            (string) $this->encryptor->decrypt((string) ($pending['verifier'] ?? '')),
            $this->redirectUri()
        );
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new PipelineException(
                'Google gave no lasting access. Remove OpenImport under myaccount.google.com › Security › '
                . 'Third-party access and sign in again.'
            );
        }
        $settings = $this->secrets->merge(['refresh_token' => $refresh] + $this->secrets->strip($settings), $settings);
        $settings['account'] = $this->email((string) ($tokens['access_token'] ?? ''));

        return $this->connections->save($connection->setSettings($settings));
    }

    /**
     * @param string $token
     * @return string the signed-in account's e-mail address ('' when Google does not tell)
     */
    private function email(string $token): string
    {
        try {
            $answer = $this->api->send(
                'GET',
                $this->endpoints->drive . '/about?fields=user(emailAddress)',
                ['Authorization' => 'Bearer ' . $token]
            );
        } catch (PipelineException $exception) {
            return '';
        }

        return (string) ($answer->json()['user']['emailAddress'] ?? '');
    }
}
