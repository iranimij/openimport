<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Google;

use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Http\ApiResponse;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Google Drive (and Shared Drives) as a folder of files. A folder is named by its link
 * (https://drive.google.com/drive/folders/<id>), its id, or a path from "My Drive" (Supplier/incoming).
 * Google Sheets in the folder appear as "<name>.xlsx" and are exported as Excel files (every sheet).
 * Deleting moves a file to the bin (Drive keeps it for 30 days).
 */
class DriveClient implements RemoteClientInterface
{
    public const FOLDER = 'application/vnd.google-apps.folder';
    public const SHEET = 'application/vnd.google-apps.spreadsheet';
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const DRIVES = 'supportsAllDrives=true';

    /**
     * @var RemoteSettings|null
     */
    private ?RemoteSettings $settings = null;

    /**
     * @var array<string, string> folder reference => id
     */
    private array $folders = [];

    /**
     * @var array<string, array<string, array{id: string, mimeType: string, modified: int|null}>> folder id => files
     */
    private array $listings = [];

    /**
     * @param ApiClient $api
     * @param GoogleAuth $auth
     * @param GoogleEndpoints $endpoints
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly GoogleAuth $auth,
        private readonly GoogleEndpoints $endpoints
    ) {
    }

    /**
     * @inheritDoc
     */
    public function connect(RemoteSettings $settings): void
    {
        $this->settings = $settings;
        $this->folders = [];
        $this->listings = [];
        // Logs in now, so a wrong key is reported as such.
        $this->auth->token($settings->options);
    }

    /**
     * @inheritDoc
     */
    public function listFiles(string $path): array
    {
        $files = [];
        foreach ($this->listing($this->folderId($path)) as $name => $file) {
            $files[$name] = $file['modified'];
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function download(string $remote, string $local): void
    {
        [$folder, $name] = $this->split($remote);
        $file = $this->file($folder, $name);
        $base = $this->endpoints->drive . '/files/' . rawurlencode($file['id']);
        $url = $file['mimeType'] === self::SHEET
            ? $base . '/export?mimeType=' . rawurlencode(self::XLSX)
            : $base . '?alt=media&' . self::DRIVES;
        $this->check($this->request('GET', $url, [], null, null, $local), $name);
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local, string $remote): void
    {
        [$folder, $name] = $this->split($remote);
        $folderId = $this->folderId($folder);
        $sheet = ($this->settings?->options['as_sheet'] ?? '') === '1';
        $title = $sheet ? (string) preg_replace('/\.(csv|xlsx)$/i', '', $name) : $name;
        $existing = $this->listing($folderId)[$sheet ? $title . '.xlsx' : $name] ?? null;
        // A file with this name gets new content and keeps its id (and every link to it).
        if ($existing !== null) {
            $start = $this->request(
                'PATCH',
                $this->endpoints->upload . '/files/' . rawurlencode($existing['id']) . '?uploadType=resumable&'
                    . self::DRIVES,
                ['Content-Type' => 'application/json; charset=UTF-8'],
                '{}'
            );
        } else {
            $metadata = ['name' => $title, 'parents' => [$folderId]];
            if ($sheet) {
                $metadata['mimeType'] = self::SHEET;
            }
            $start = $this->request(
                'POST',
                sprintf('%s/files?uploadType=resumable&%s', $this->endpoints->upload, self::DRIVES),
                ['Content-Type' => 'application/json; charset=UTF-8'],
                (string) json_encode($metadata)
            );
        }
        $this->check($start, $name);
        $session = $start->headers['location'] ?? '';
        if ($session === '') {
            throw new PipelineException(sprintf('Google Drive did not start the upload of %s.', $name));
        }
        $this->check($this->request('PUT', $session, [], null, $local), $name);
        unset($this->listings[$folderId]);
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): void
    {
        [$folder, $name] = $this->split($from);
        [$target] = $this->split($to);
        $file = $this->file($folder, $name);
        $sourceId = $this->folderId($folder);
        $targetId = $this->folderId($target, true);
        $this->check($this->request(
            'PATCH',
            sprintf(
                '%s/files/%s?addParents=%s&removeParents=%s&%s',
                $this->endpoints->drive,
                rawurlencode($file['id']),
                rawurlencode($targetId),
                rawurlencode($sourceId),
                self::DRIVES
            ),
            ['Content-Type' => 'application/json; charset=UTF-8'],
            '{}'
        ), $name);
        unset($this->listings[$sourceId], $this->listings[$targetId]);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote): void
    {
        [$folder, $name] = $this->split($remote);
        $file = $this->file($folder, $name);
        $this->check($this->request(
            'PATCH',
            sprintf('%s/files/%s?%s', $this->endpoints->drive, rawurlencode($file['id']), self::DRIVES),
            ['Content-Type' => 'application/json; charset=UTF-8'],
            '{"trashed":true}'
        ), $name);
        unset($this->listings[$this->folderId($folder)]);
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->settings = null;
    }

    /**
     * The id of a folder from its link, id or path ("" = the top of My Drive).
     *
     * @param string $reference
     * @param bool $create create the last folder of a path when it is missing (for "processed")
     * @return string
     * @throws PipelineException
     */
    public function folderId(string $reference, bool $create = false): string
    {
        $reference = trim($reference);
        if (isset($this->folders[$reference])) {
            return $this->folders[$reference];
        }
        if (preg_match('#/folders/([A-Za-z0-9_-]{10,})([^/]*)(.*)$#', $reference, $match) === 1) {
            // A link may end in "?usp=sharing"; what follows the next "/" is a subfolder path.
            $id = $match[1];
            $rest = trim($match[3], '/');
        } elseif (preg_match('#^([A-Za-z0-9_-]{25,})(?:/(.*))?$#', $reference, $match) === 1) {
            $id = $match[1];
            $rest = trim($match[2] ?? '', '/');
        } else {
            $id = 'root';
            $rest = trim($reference, '/');
        }
        $segments = $rest === '' ? [] : explode('/', $rest);
        foreach ($segments as $position => $segment) {
            $child = $this->childFolder($id, $segment);
            if ($child === null && $create && $position === count($segments) - 1) {
                $child = $this->createFolder($id, $segment);
            }
            if ($child === null) {
                throw new PipelineException(sprintf('Google Drive has no folder "%s" in %s.', $segment, $reference));
            }
            $id = $child;
        }

        return $this->folders[$reference] = $id;
    }

    /**
     * @param string $parent
     * @param string $name
     * @return string|null
     * @throws PipelineException
     */
    private function childFolder(string $parent, string $name): ?string
    {
        $query = sprintf(
            "'%s' in parents and name = '%s' and mimeType = '%s' and trashed = false",
            $this->quote($parent),
            $this->quote($name),
            self::FOLDER
        );
        $answer = $this->request('GET', $this->filesUrl($query, 'files(id)'));
        $this->check($answer, $name);

        return isset($answer->json()['files'][0]['id']) ? (string) $answer->json()['files'][0]['id'] : null;
    }

    /**
     * @param string $parent
     * @param string $name
     * @return string
     * @throws PipelineException
     */
    private function createFolder(string $parent, string $name): string
    {
        $answer = $this->request(
            'POST',
            sprintf('%s/files?%s&fields=id', $this->endpoints->drive, self::DRIVES),
            ['Content-Type' => 'application/json; charset=UTF-8'],
            (string) json_encode(['name' => $name, 'mimeType' => self::FOLDER, 'parents' => [$parent]])
        );
        $this->check($answer, $name);

        return (string) ($answer->json()['id'] ?? '');
    }

    /**
     * Files of a folder by name (the newest one when names repeat); Sheets as "<name>.xlsx", other Google types and
     * folders left out.
     *
     * @param string $folderId
     * @return array<string, array{id: string, mimeType: string, modified: int|null}>
     * @throws PipelineException
     */
    private function listing(string $folderId): array
    {
        if (isset($this->listings[$folderId])) {
            return $this->listings[$folderId];
        }
        $files = [];
        $token = '';
        $query = sprintf("'%s' in parents and trashed = false", $this->quote($folderId));
        do {
            $answer = $this->request(
                'GET',
                $this->filesUrl($query, 'nextPageToken,files(id,name,mimeType,modifiedTime)', $token)
            );
            $this->check($answer, 'the folder');
            $data = $answer->json();
            foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
                $mime = (string) ($file['mimeType'] ?? '');
                $name = (string) ($file['name'] ?? '');
                if ($mime === self::SHEET) {
                    $name .= '.xlsx';
                } elseif ($mime === self::FOLDER || str_starts_with($mime, 'application/vnd.google-apps.')) {
                    continue;
                }
                $modified = strtotime((string) ($file['modifiedTime'] ?? ''));
                $modified = $modified === false ? null : $modified;
                if (!isset($files[$name]) || (int) $files[$name]['modified'] < (int) $modified) {
                    $files[$name] = [
                        'id' => (string) ($file['id'] ?? ''),
                        'mimeType' => $mime,
                        'modified' => $modified,
                    ];
                }
            }
            $token = (string) ($data['nextPageToken'] ?? '');
        } while ($token !== '');

        return $this->listings[$folderId] = $files;
    }

    /**
     * @param string $folder
     * @param string $name
     * @return array{id: string, mimeType: string, modified: int|null}
     * @throws PipelineException
     */
    private function file(string $folder, string $name): array
    {
        $file = $this->listing($this->folderId($folder))[$name] ?? null;
        if ($file === null) {
            throw new PipelineException(
                sprintf('Google Drive has no file %s in %s.', $name, $folder === '' ? 'My Drive' : $folder)
            );
        }

        return $file;
    }

    /**
     * @param string $query
     * @param string $fields
     * @param string $pageToken
     * @return string
     */
    private function filesUrl(string $query, string $fields, string $pageToken = ''): string
    {
        $parameters = [
            'q' => $query,
            'fields' => $fields,
            'pageSize' => '1000',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
        if ($pageToken !== '') {
            $parameters['pageToken'] = $pageToken;
        }

        return $this->endpoints->drive . '/files?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param string $path folder reference and file name, joined by "/"
     * @return array{0: string, 1: string}
     */
    private function split(string $path): array
    {
        $cut = strrpos($path, '/');

        return $cut === false ? ['', $path] : [substr($path, 0, $cut), substr($path, $cut + 1)];
    }

    /**
     * @param string $value
     * @return string for a Drive query
     */
    private function quote(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @param string|null $bodyFile
     * @param string|null $saveTo
     * @return ApiResponse
     * @throws PipelineException
     */
    private function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $bodyFile = null,
        ?string $saveTo = null
    ): ApiResponse {
        $settings = $this->settings;
        if ($settings === null) {
            throw new PipelineException('The Google Drive connection is not open.');
        }
        $headers['Authorization'] = 'Bearer ' . $this->auth->token($settings->options);

        return $this->api->send($method, $url, $headers, $body, $bodyFile, $saveTo, $settings->timeout);
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
            403 => $message !== '' ? $message : 'access denied; share the folder with the account',
            404 => 'not found, or not shared with the account',
            default => $message !== '' ? $message : 'HTTP ' . $answer->status,
        };

        throw new PipelineException(sprintf('Google Drive, %s: %s.', $what, rtrim($hint, '.')));
    }
}
