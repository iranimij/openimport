<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;

/**
 * Server settings from a profile's source or destination settings, or from a saved connection.
 */
class RemoteSettingsBuilder
{
    private const DEFAULT_PORTS = ['sftp' => 22, 'ftp' => 21];

    /**
     * @param Secrets $secrets
     */
    public function __construct(
        private readonly Secrets $secrets
    ) {
    }

    /**
     * @param array<string, mixed> $config host, port, username, auth_sftp, ssl, passive, timeout, secrets; for S3:
     *     endpoint, region, bucket, access_key, path_style and the secret secret_key
     * @param string $protocol sftp, ftp or s3
     * @return RemoteSettings
     * @throws PipelineException without a host
     */
    public function build(array $config, string $protocol): RemoteSettings
    {
        if ($protocol === 's3') {
            return $this->s3($config);
        }
        if ($protocol === 'google_drive') {
            return $this->google($config);
        }
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new PipelineException('Enter the server (host name).');
        }
        $port = (int) ($config['port'] ?? 0);
        $key = ($config['auth_sftp'] ?? 'password') === 'key' ? $this->secrets->get($config, 'private_key') : '';

        return new RemoteSettings(
            $host,
            $port > 0 && $port < 65536 ? $port : self::DEFAULT_PORTS[$protocol] ?? 22,
            trim((string) ($config['username'] ?? '')),
            $this->secrets->get($config, 'password'),
            $key,
            $key === '' ? '' : $this->secrets->get($config, 'passphrase'),
            !empty($config['ssl']),
            !isset($config['passive']) || !empty($config['passive']),
            max(5, min(300, (int) ($config['timeout'] ?? 30)))
        );
    }

    /**
     * S3: the endpoint defaults to AWS's for the region; host and port come from the endpoint.
     *
     * @param array<string, mixed> $config
     * @return RemoteSettings
     * @throws PipelineException
     */
    private function s3(array $config): RemoteSettings
    {
        $text = static fn (string $key): string
            => is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';
        $region = $text('region') !== '' ? $text('region') : 'us-east-1';
        $endpoint = rtrim($text('endpoint'), '/');
        if ($endpoint === '') {
            $endpoint = 'https://s3.' . $region . '.amazonaws.com';
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the framework has no URL parser without a request
        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new PipelineException(
                'The endpoint must be a web address such as https://s3.eu-central-1.amazonaws.com.'
            );
        }
        $bucket = $text('bucket');
        if ($bucket === '') {
            throw new PipelineException('Enter the bucket.');
        }
        $https = $parts['scheme'] === 'https';

        return new RemoteSettings(
            (string) $parts['host'],
            (int) ($parts['port'] ?? ($https ? 443 : 80)),
            $text('access_key'),
            $this->secrets->get($config, 'secret_key'),
            '',
            '',
            $https,
            true,
            max(5, min(300, (int) ($config['timeout'] ?? 30))),
            [
                'endpoint' => $endpoint,
                'region' => $region,
                'bucket' => $bucket,
                'path_style' => !empty($config['path_style']) ? '1' : '0',
                'label' => sprintf('bucket %s (%s)', $bucket, $parts['host']),
            ]
        );
    }

    /**
     * Google Drive: a service account key, or a signed-in account (client id and secret, refresh token).
     *
     * @param array<string, mixed> $config
     * @return RemoteSettings
     */
    private function google(array $config): RemoteSettings
    {
        $auth = ($config['auth_google'] ?? '') === 'oauth' ? 'oauth' : 'service_account';
        $account = is_scalar($config['account'] ?? null) ? trim((string) $config['account']) : '';

        return new RemoteSettings(
            'www.googleapis.com',
            443,
            $account,
            '',
            '',
            '',
            true,
            true,
            max(5, min(300, (int) ($config['timeout'] ?? 60))),
            [
                'auth' => $auth,
                'service_account_json' => $this->secrets->get($config, 'service_account_json'),
                'client_id' => is_scalar($config['client_id'] ?? null) ? trim((string) $config['client_id']) : '',
                'client_secret' => $this->secrets->get($config, 'client_secret'),
                'refresh_token' => $this->secrets->get($config, 'refresh_token'),
                'as_sheet' => !empty($config['as_sheet']) ? '1' : '0',
                'label' => 'Google Drive' . ($account !== '' ? ' (' . $account . ')' : ''),
            ]
        );
    }
}
