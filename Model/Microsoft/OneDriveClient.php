<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Microsoft;

use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Http\ApiResponse;
use Iranimij\OpenImport\Model\OAuth\OAuthTokens;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * OneDrive and SharePoint document libraries through Microsoft Graph. The drive is the signed-in user's OneDrive, or
 * the default library of a SharePoint site ("contoso.sharepoint.com/sites/Shop"). Folders are paths in the drive.
 * Uploads replace a file of the same name (upload sessions in 10 MB parts); deletes go to the recycle bin.
 */
class OneDriveClient implements RemoteClientInterface
{
    private const PART = 10485760;

    /**
     * @var RemoteSettings|null
     */
    private ?RemoteSettings $settings = null;

    private string $drive = '';

    /**
     * @param ApiClient $api
     * @param OAuthTokens $tokens
     * @param MicrosoftEndpoints $endpoints
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly OAuthTokens $tokens,
        private readonly MicrosoftEndpoints $endpoints
    ) {
    }

    /**
     * @inheritDoc
     */
    public function connect(RemoteSettings $settings): void
    {
        $this->settings = $settings;
        $site = trim((string) ($settings->options['site'] ?? ''));
        if ($site === '') {
            $this->drive = $this->endpoints->graph . '/me/drive';
            $this->token();

            return;
        }
        // "https://contoso.sharepoint.com/sites/Shop" or "contoso.sharepoint.com/sites/Shop"
        $site = (string) preg_replace('#^https?://#i', '', rtrim($site, '/'));
        [$host, $path] = array_pad(explode('/', $site, 2), 2, '');
        $answer = $this->get(
            $this->endpoints->graph . '/sites/' . rawurlencode($host)
                . ($path !== '' ? ':/' . $this->encode($path) : ''),
            'the SharePoint site ' . $site
        );
        $id = (string) ($answer->json()['id'] ?? '');
        if ($id === '') {
            throw new PipelineException(sprintf('Microsoft did not name the SharePoint site %s.', $site));
        }
        $this->drive = $this->endpoints->graph . '/sites/' . rawurlencode($id) . '/drive';
    }

    /**
     * @inheritDoc
     */
    public function listFiles(string $path): array
    {
        $files = [];
        $url = $this->item($path) . (trim($path, '/') === '' ? '/children' : ':/children')
            . '?$select=name,file,lastModifiedDateTime&$top=999';
        while ($url !== '') {
            $data = $this->get($url, $path === '' ? 'the top folder' : $path)->json();
            foreach (is_array($data['value'] ?? null) ? $data['value'] : [] as $item) {
                if (!isset($item['file'])) {
                    continue;
                }
                $modified = strtotime((string) ($item['lastModifiedDateTime'] ?? ''));
                $files[(string) $item['name']] = $modified === false ? null : $modified;
            }
            $url = (string) ($data['@odata.nextLink'] ?? '');
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function download(string $remote, string $local): void
    {
        $answer = $this->send('GET', $this->item($remote) . ':/content', [], null, $local);
        // Graph answers with a redirect to a short-lived, pre-signed address.
        if (in_array($answer->status, [301, 302, 303, 307], true) && isset($answer->headers['location'])) {
            $answer = $this->api->send(
                'GET',
                $answer->headers['location'],
                [],
                null,
                null,
                $local,
                $this->settings->timeout ?? 60
            );
        }
        $this->check($answer, $remote);
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local, string $remote): void
    {
        $size = (int) filesize($local);
        if ($size === 0) {
            $this->check($this->send('PUT', $this->item($remote) . ':/content', [], ''), $remote);

            return;
        }
        $session = $this->send(
            'POST',
            $this->item($remote) . ':/createUploadSession',
            ['Content-Type' => 'application/json'],
            '{"item":{"@microsoft.graph.conflictBehavior":"replace"}}'
        );
        $this->check($session, $remote);
        $target = (string) ($session->json()['uploadUrl'] ?? '');
        if ($target === '') {
            throw new PipelineException(sprintf('Microsoft did not start the upload of %s.', $remote));
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- reads the export file in parts
        $handle = fopen($local, 'rb');
        try {
            for ($offset = 0; $offset < $size; $offset += self::PART) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $part = (string) fread($handle, self::PART);
                $answer = $this->api->send('PUT', $target, [
                    'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + strlen($part) - 1, $size),
                    'Content-Type' => 'application/octet-stream',
                ], $part, null, null, $this->settings->timeout ?? 60);
                $this->check($answer, $remote);
            }
        } finally {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            fclose($handle);
        }
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): void
    {
        $folder = trim((string) substr($to, 0, (int) strrpos($to, '/')), '/');
        $parent = $this->folderId($folder);
        $this->check($this->send(
            'PATCH',
            $this->item($from),
            ['Content-Type' => 'application/json'],
            (string) json_encode([
                'parentReference' => ['id' => $parent],
                'name' => basename($to),
                '@microsoft.graph.conflictBehavior' => 'rename',
            ])
        ), $from);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote): void
    {
        $this->check($this->send('DELETE', $this->item($remote)), $remote);
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->settings = null;
        $this->drive = '';
    }

    /**
     * The id of a folder, made when missing.
     *
     * @param string $folder path in the drive
     * @return string
     * @throws PipelineException
     */
    private function folderId(string $folder): string
    {
        $found = $this->send('GET', $this->item($folder) . '?$select=id');
        if ($found->status === 404 && $folder !== '') {
            $parent = trim((string) substr($folder, 0, (int) strrpos($folder, '/')), '/');
            $made = $this->send(
                'POST',
                $this->item($parent) . ($parent === '' ? '/children' : ':/children'),
                ['Content-Type' => 'application/json'],
                (string) json_encode([
                    'name' => basename($folder),
                    'folder' => new \stdClass(),
                    '@microsoft.graph.conflictBehavior' => 'fail',
                ])
            );
            $this->check($made, $folder);

            return (string) ($made->json()['id'] ?? '');
        }
        $this->check($found, $folder);

        return (string) ($found->json()['id'] ?? '');
    }

    /**
     * Graph's address of an item by path: <drive>/root or <drive>/root:/a/b (add ":/children", ":/content", ...).
     *
     * @param string $path
     * @return string
     */
    private function item(string $path): string
    {
        $path = trim($path, '/');

        return $this->drive . '/root' . ($path === '' ? '' : ':/' . $this->encode($path));
    }

    /**
     * @param string $path
     * @return string every segment percent-encoded
     */
    private function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @param string $url
     * @param string $what
     * @return ApiResponse
     * @throws PipelineException
     */
    private function get(string $url, string $what): ApiResponse
    {
        $answer = $this->send('GET', $url);
        $this->check($answer, $what);

        return $answer;
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @param string|null $saveTo
     * @return ApiResponse
     * @throws PipelineException
     */
    private function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $saveTo = null
    ): ApiResponse {
        $headers['Authorization'] = 'Bearer ' . $this->token();

        return $this->api->send($method, $url, $headers, $body, null, $saveTo, $this->settings->timeout ?? 60);
    }

    /**
     * @return string
     * @throws PipelineException
     */
    private function token(): string
    {
        $options = $this->settings->options ?? [];
        if ($this->settings === null) {
            throw new PipelineException('The Microsoft connection is not open.');
        }

        return $this->tokens->refresh(
            $this->endpoints->oauth((string) ($options['tenant'] ?? ''), 'token'),
            [
                'refresh_token' => (string) ($options['refresh_token'] ?? ''),
                'client_id' => (string) ($options['client_id'] ?? ''),
                'client_secret' => (string) ($options['client_secret'] ?? ''),
                'scope' => $this->endpoints->scope((string) ($options['site'] ?? '')),
            ],
            'Microsoft'
        );
    }

    /**
     * @param ApiResponse $answer
     * @param string $what
     * @return void
     * @throws PipelineException
     */
    private function check(ApiResponse $answer, string $what): void
    {
        if ($answer->ok()) {
            return;
        }
        $message = (string) ($answer->json()['error']['message'] ?? '');
        $hint = match ($answer->status) {
            401 => 'the login is no longer valid; sign in again',
            403 => $message !== '' ? $message : 'access denied',
            404 => 'not found',
            default => $message !== '' ? $message : 'HTTP ' . $answer->status,
        };

        throw new PipelineException(
            sprintf('Microsoft, %s: %s.', $what === '' ? 'the top folder' : $what, rtrim($hint, '.'))
        );
    }
}
