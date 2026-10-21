<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Rest;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Execution\FetchedFile;
use Iranimij\OpenImport\Model\Execution\SourceInterface;
use Iranimij\OpenImport\Model\Http\ApiResponse;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Records from a REST API: GET the profile's address below the connection's base address, page by page (page
 * numbers, offsets, cursors, a "next" address in the answer or the Link header), and hand the records to the JSON
 * reader as one file. A run stops with an error rather than import a partial list when the page limit is reached.
 */
class RestSource implements SourceInterface
{
    public const FILE_NAME = 'rest-api.json';

    /**
     * @param RestClient $client
     * @param RestPaging $paging
     * @param ConnectionConfig $connections
     * @param File $files
     */
    public function __construct(
        private readonly RestClient $client,
        private readonly RestPaging $paging,
        private readonly ConnectionConfig $connections,
        private readonly File $files
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetch(ProfileInterface $profile, string $folder): FetchedFile
    {
        $records = $this->records($profile, 0);
        $local = rtrim($folder, '/') . '/' . self::FILE_NAME;
        $this->files->filePutContents(
            $local,
            (string) json_encode(
                $records,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            )
        );

        return new FetchedFile($local, self::FILE_NAME);
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        $records = $this->records($profile, 1);
        $first = is_array($records[0] ?? null) ? array_keys($records[0]) : [];

        return sprintf(
            'Connected. The first page has %d record(s)%s.',
            count($records),
            $first === [] ? '' : ' with the fields ' . implode(', ', array_slice(array_map('strval', $first), 0, 12))
        );
    }

    /**
     * An API is no folder: nothing is moved or deleted after a run.
     *
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, string $reference): void
    {
    }

    /**
     * @param ProfileInterface $profile
     * @param int $pages pages to read, 0 = all (up to the profile's limit)
     * @return array<int, mixed>
     * @throws PipelineException
     */
    private function records(ProfileInterface $profile, int $pages): array
    {
        $config = $this->connections->resolve($profile->getSourceConfig(), 'rest');
        $text = static fn (string $key, string $default = ''): string
            => is_scalar($config[$key] ?? null) && trim((string) $config[$key]) !== ''
                ? trim((string) $config[$key])
                : $default;
        $mode = $text('paging', RestPaging::NONE);
        $limit = max(1, min(10000, (int) $text('max_pages', '100')));
        $size = max(1, (int) $text('page_size', '100'));
        $sizeParam = $text('size_param');
        $page = (int) $text('page_start', $mode === RestPaging::OFFSET ? '0' : '1');
        $path = $text('path');
        $next = $path;
        $all = [];
        for ($count = 1;; $count++) {
            $url = $next;
            if ($mode === RestPaging::PAGE || $mode === RestPaging::OFFSET) {
                $parameters = [$text('page_param', $mode === RestPaging::PAGE ? 'page' : 'offset') => (string) $page];
                if ($sizeParam !== '') {
                    $parameters[$sizeParam] = (string) $size;
                }
                $url = $this->paging->withQuery($path, $parameters);
            }
            $answer = $this->client->send($config, 'GET', $url);
            $this->check($answer, $url);
            $data = json_decode($answer->body, true);
            if (!is_array($data)) {
                throw new PipelineException(sprintf('%s did not answer with JSON.', $url));
            }
            $records = $this->paging->records($data, $text('records'));
            if ($records === null) {
                throw new PipelineException($text('records') === ''
                    ? sprintf('The answer of %s holds no list of records; enter where the records are.', $url)
                    : sprintf('The answer of %s has no list at "%s".', $url, $text('records')));
            }
            array_push($all, ...$records);
            $next = $this->next($mode, $records, $data, $answer, [
                'path' => $path,
                'more' => $sizeParam === '' || count($records) >= $size,
                'cursor_param' => $text('cursor_param', 'cursor'),
                'cursor_path' => $text('cursor_path'),
                'next_path' => $text('next_path'),
            ]);
            $page += $mode === RestPaging::OFFSET ? count($records) : 1;
            if ($next === '' || ($pages > 0 && $count >= $pages)) {
                return $all;
            }
            if ($count >= $limit) {
                throw new PipelineException(sprintf(
                    'The API has more than %d pages; nothing was imported. Raise "Pages at most" if that is right.',
                    $limit
                ));
            }
        }
    }

    /**
     * Where the next page is ('' = this was the last one).
     *
     * @param string $mode
     * @param array<int, mixed> $records
     * @param array<mixed> $data
     * @param ApiResponse $answer
     * @param array<string, mixed> $settings path, more (a full page came back), cursor_param, cursor_path, next_path
     * @return string
     */
    private function next(string $mode, array $records, array $data, ApiResponse $answer, array $settings): string
    {
        if ($records === []) {
            return '';
        }
        $nextUrl = $this->paging->value($data, (string) $settings['next_path']);

        return match ($mode) {
            RestPaging::PAGE, RestPaging::OFFSET => $settings['more'] ? 'more' : '',
            RestPaging::CURSOR => $this->cursor(
                (string) $settings['path'],
                (string) $settings['cursor_param'],
                $this->paging->value($data, (string) $settings['cursor_path'])
            ),
            RestPaging::NEXT_URL => is_string($nextUrl) ? $nextUrl : '',
            RestPaging::LINK => $this->paging->nextLink($answer->headers['link'] ?? ''),
            default => '',
        };
    }

    /**
     * @param string $path
     * @param string $parameter
     * @param mixed $cursor
     * @return string the next page, '' when there is no cursor
     */
    private function cursor(string $path, string $parameter, mixed $cursor): string
    {
        return is_scalar($cursor) && $cursor !== false && (string) $cursor !== ''
            ? $this->paging->withQuery($path, [$parameter => (string) $cursor])
            : '';
    }

    /**
     * @param ApiResponse $answer
     * @param string $url
     * @return void
     * @throws PipelineException
     */
    private function check(ApiResponse $answer, string $url): void
    {
        if ($answer->ok()) {
            return;
        }
        $hint = match (true) {
            $answer->status === 401 => 'the login was refused',
            $answer->status === 403 => 'access denied',
            $answer->status === 404 => 'not found',
            $answer->status === 429 => 'too many requests, also after waiting',
            default => 'HTTP ' . $answer->status,
        };
        $detail = trim(substr(strip_tags($answer->body), 0, 200));

        throw new PipelineException(sprintf('%s: %s.%s', $url, $hint, $detail !== '' ? ' ' . $detail : ''));
    }
}
