<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Dropbox;

use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Http\ApiResponse;
use Iranimij\OpenImport\Model\OAuth\OAuthTokens;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Dropbox (API v2): folders are paths from the top of the Dropbox ("/Supplier/incoming"; an app with "App folder"
 * access sees its own folder as the top). Uploads overwrite a file of the same name; deletes go to Dropbox's deleted
 * files (kept for 30 days or more).
 */
class DropboxClient implements RemoteClientInterface
{
    /**
     * @var RemoteSettings|null
     */
    private ?RemoteSettings $settings = null;

    /**
     * @param ApiClient $api
     * @param OAuthTokens $tokens
     * @param DropboxEndpoints $endpoints
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly OAuthTokens $tokens,
        private readonly DropboxEndpoints $endpoints
    ) {
    }

    /**
     * @inheritDoc
     */
    public function connect(RemoteSettings $settings): void
    {
        $this->settings = $settings;
        $this->token();
    }

    /**
     * @inheritDoc
     */
    public function listFiles(string $path): array
    {
        $files = [];
        $answer = $this->rpc('files/list_folder', ['path' => $this->path($path), 'limit' => 2000], $path);
        do {
            $data = $answer->json();
            foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $entry) {
                if (($entry['.tag'] ?? '') !== 'file') {
                    continue;
                }
                $modified = strtotime((string) ($entry['server_modified'] ?? ''));
                $files[(string) $entry['name']] = $modified === false ? null : $modified;
            }
            if (empty($data['has_more'])) {
                break;
            }
            $answer = $this->rpc('files/list_folder/continue', ['cursor' => (string) ($data['cursor'] ?? '')], $path);
        } while (true);

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function download(string $remote, string $local): void
    {
        $answer = $this->request(
            $this->endpoints->content . '/files/download',
            ['Dropbox-API-Arg' => $this->arg(['path' => $this->path($remote)])],
            null,
            null,
            $local
        );
        $this->check($answer, $remote);
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local, string $remote): void
    {
        $answer = $this->request(
            $this->endpoints->content . '/files/upload',
            [
                'Dropbox-API-Arg' => $this->arg([
                    'path' => $this->path($remote),
                    'mode' => 'overwrite',
                    'mute' => true,
                ]),
                'Content-Type' => 'application/octet-stream',
            ],
            null,
            $local
        );
        $this->check($answer, $remote);
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): void
    {
        $folder = (string) substr($this->path($to), 0, (int) strrpos($this->path($to), '/'));
        if ($folder !== '') {
            // Made when missing; "already there" is fine.
            $made = $this->request(
                $this->endpoints->api . '/files/create_folder_v2',
                ['Content-Type' => 'application/json'],
                (string) json_encode(['path' => $folder, 'autorename' => false], JSON_UNESCAPED_SLASHES)
            );
            if (!$made->ok() && !str_contains((string) ($made->json()['error_summary'] ?? ''), 'conflict')) {
                $this->check($made, $folder);
            }
        }
        $this->rpc('files/move_v2', [
            'from_path' => $this->path($from),
            'to_path' => $this->path($to),
            'autorename' => true,
        ], $from);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote): void
    {
        $this->rpc('files/delete_v2', ['path' => $this->path($remote)], $remote);
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->settings = null;
    }

    /**
     * Dropbox paths start with "/" and the top is "".
     *
     * @param string $path
     * @return string
     */
    private function path(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : '/' . $path;
    }

    /**
     * Dropbox-API-Arg is HTTP header JSON: characters outside ASCII are escaped.
     *
     * @param array<string, mixed> $value
     * @return string
     */
    private function arg(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param string $endpoint e.g. files/list_folder
     * @param array<string, mixed> $body
     * @param string $what for messages
     * @return ApiResponse
     * @throws PipelineException
     */
    private function rpc(string $endpoint, array $body, string $what): ApiResponse
    {
        $answer = $this->request(
            $this->endpoints->api . '/' . $endpoint,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->check($answer, $what);

        return $answer;
    }

    /**
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @param string|null $bodyFile
     * @param string|null $saveTo
     * @return ApiResponse
     * @throws PipelineException
     */
    private function request(
        string $url,
        array $headers,
        ?string $body = null,
        ?string $bodyFile = null,
        ?string $saveTo = null
    ): ApiResponse {
        $headers['Authorization'] = 'Bearer ' . $this->token();

        return $this->api->send('POST', $url, $headers, $body, $bodyFile, $saveTo, $this->settings->timeout ?? 60);
    }

    /**
     * @return string
     * @throws PipelineException
     */
    private function token(): string
    {
        $options = $this->settings->options ?? [];
        if ($this->settings === null) {
            throw new PipelineException('The Dropbox connection is not open.');
        }

        return $this->tokens->refresh($this->endpoints->token, [
            'refresh_token' => (string) ($options['refresh_token'] ?? ''),
            'client_id' => (string) ($options['client_id'] ?? ''),
            'client_secret' => (string) ($options['client_secret'] ?? ''),
        ], 'Dropbox');
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
        $summary = (string) ($answer->json()['error_summary'] ?? '');
        $hint = match (true) {
            str_contains($summary, 'not_found') => 'not found',
            str_contains($summary, 'insufficient_space') => 'the Dropbox is full',
            str_contains($summary, 'missing_scope') => 'the Dropbox app lacks a permission (files.content.read/write)',
            $answer->status === 401 => 'the login is no longer valid; sign in again',
            $summary !== '' => rtrim($summary, '/.'),
            default => 'HTTP ' . $answer->status,
        };

        throw new PipelineException(sprintf('Dropbox, %s: %s.', $what === '' ? 'the top folder' : $what, $hint));
    }
}
