<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\OAuth;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Math\Random;

/**
 * "Sign in" for a connection (Google, Dropbox, Microsoft; providers by connection type in di.xml): the consent page
 * gets a random state ("<connection id>.<random>") and a PKCE challenge; the connection keeps the state's hash and the
 * encrypted verifier for 15 minutes. The answer (code + state) is traded for a refresh token, which is stored
 * encrypted with the connection.
 */
class OAuthFlow
{
    public const CALLBACK_ROUTE = 'openimport/connection/oauthCallback';
    public const PENDING = 'oauth_pending';
    private const LIFETIME = 900;

    /**
     * @param ConnectionRepositoryInterface $connections
     * @param Secrets $secrets
     * @param EncryptorInterface $encryptor
     * @param UrlInterface $url
     * @param Random $random
     * @param array<string, OAuthProviderInterface> $providers connection type => provider
     */
    public function __construct(
        private readonly ConnectionRepositoryInterface $connections,
        private readonly Secrets $secrets,
        private readonly EncryptorInterface $encryptor,
        private readonly UrlInterface $url,
        private readonly Random $random,
        private readonly array $providers = []
    ) {
    }

    /**
     * @param ConnectionInterface $connection
     * @return OAuthProviderInterface|null the provider when the connection signs in
     */
    public function provider(ConnectionInterface $connection): ?OAuthProviderInterface
    {
        $provider = $this->providers[$connection->getType()] ?? null;

        return $provider instanceof OAuthProviderInterface && $provider->signsIn($connection->getSettings())
            ? $provider
            : null;
    }

    /**
     * The address to register as redirect URI of the shop's OAuth client (Google, Dropbox, Microsoft).
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
        $provider = $this->provider($connection);
        if ($provider === null || $clientId === '' || $this->secrets->get($settings, 'client_secret') === '') {
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

        return $provider->consentUrl($settings, $clientId, $this->redirectUri(), $state, $verifier);
    }

    /**
     * Stores the refresh token the service gave for this state and code.
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
        $provider = $this->provider($connection);
        if ($provider === null) {
            throw new PipelineException($unknown);
        }
        $tokens = $provider->exchange(
            $settings,
            (string) ($settings['client_id'] ?? ''),
            $this->secrets->get($settings, 'client_secret'),
            $code,
            (string) $this->encryptor->decrypt((string) ($pending['verifier'] ?? '')),
            $this->redirectUri()
        );
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new PipelineException(
                'The service gave no lasting access. Remove the app from the account\'s connected apps and sign '
                . 'in again.'
            );
        }
        $settings = $this->secrets->merge(['refresh_token' => $refresh] + $this->secrets->strip($settings), $settings);
        $settings['account'] = $provider->account((string) ($tokens['access_token'] ?? ''));

        return $this->connections->save($connection->setSettings($settings));
    }
}
