<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Execution\FetchedFile;
use Iranimij\OpenImport\Model\Execution\SourceInterface;
use Iranimij\OpenImport\Model\Http\SafeDownloader;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;

/**
 * A file behind a web address: http(s), optional basic auth or bearer token, Google Sheets links (a shared sheet's
 * edit link or a published link become its CSV export). Every redirect hop goes through the SSRF guard again and
 * connects to the address the guard checked. The download is streamed to the run folder, capped at max_mb.
 *
 * source_config: url, auth (none | basic | token), username, sheet_gid, timeout, max_mb; secrets: password, token.
 */
class UrlSource implements SourceInterface
{
    private const DEFAULT_MAX_MB = 256;
    private const EXTENSIONS = [
        'text/csv' => 'csv',
        'text/tab-separated-values' => 'tsv',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    ];

    /**
     * @param SafeDownloader $downloader
     * @param Secrets $secrets
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly SafeDownloader $downloader,
        private readonly Secrets $secrets,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetch(ProfileInterface $profile, string $folder): FetchedFile
    {
        $config = $profile->getSourceConfig();
        $target = rtrim($folder, '/') . '/download.tmp';
        [$name] = $this->download($config, $target);
        $driver = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
        $final = rtrim($folder, '/') . '/download-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $driver->rename($target, $final);

        return new FetchedFile($final, $name);
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        $config = $profile->getSourceConfig();
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $var->create('openimport/tmp');
        $target = $var->getAbsolutePath('openimport/tmp/test-' . bin2hex(random_bytes(6)));
        try {
            [$name, $bytes, $type] = $this->download($config, $target);
        } finally {
            if ($var->getDriver()->isExists($target)) {
                $var->getDriver()->deleteFile($target);
            }
        }

        return sprintf('Downloaded %s (%s%s).', $name, $this->downloader->size($bytes), $type === '' ? '' : ', ' . $type);
    }

    /**
     * Web files stay where they are.
     *
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, string $reference): void
    {
    }

    /**
     * The address a profile reads, with Google Sheets links turned into CSV export links.
     *
     * @param array<string, mixed> $config
     * @return string
     * @throws PipelineException
     */
    public function url(array $config): string
    {
        $url = trim((string) ($config['url'] ?? ''));
        if ($url === '') {
            throw new PipelineException('Enter the web address of the file.');
        }
        if (preg_match('#^https://docs\.google\.com/spreadsheets/d/(?!e/)([A-Za-z0-9_-]+)(?:/[^?\#]*)?(?:\?[^\#]*)?#', $url, $match) === 1) {
            $gid = trim((string) ($config['sheet_gid'] ?? ''));
            if ($gid === '' && preg_match('/[?&#]gid=(\d+)/', $url, $gidMatch) === 1) {
                $gid = $gidMatch[1];
            }

            return 'https://docs.google.com/spreadsheets/d/' . $match[1] . '/export?format=csv'
                . ($gid === '' ? '' : '&gid=' . rawurlencode($gid));
        }
        if (preg_match('#^https://docs\.google\.com/spreadsheets/d/e/([A-Za-z0-9_-]+)/pub#', $url, $match) === 1
            && !str_contains($url, 'output=')) {
            return 'https://docs.google.com/spreadsheets/d/e/' . $match[1] . '/pub?output=csv';
        }

        return $url;
    }

    /**
     * Downloads to a local file through the safe downloader (SSRF guard on every hop, size cap).
     *
     * @param array<string, mixed> $config
     * @param string $target
     * @return array{0: string, 1: int, 2: string} file name, bytes, content type
     * @throws PipelineException
     */
    private function download(array $config, string $target): array
    {
        $url = $this->url($config);
        $result = $this->downloader->download(
            $url,
            $target,
            max(1, (int) ($config['max_mb'] ?? self::DEFAULT_MAX_MB)) * 1048576,
            (int) ($config['timeout'] ?? 60) ?: 60,
            ['Accept' => 'text/csv, application/json, application/xml, text/xml, */*'],
            function (Curl $curl, bool $first) use ($config): void {
                $this->authenticate($curl, $config, $first);
            }
        );
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the framework has no URL parser without a request
        $parts = (array) parse_url($result->url);

        return [$this->fileName($result->headers, $parts, $result->contentType), $result->bytes, $result->contentType];
    }

    /**
     * Credentials go only to the first host: a redirect to another host must not receive them.
     *
     * @param Curl $curl
     * @param array<string, mixed> $config
     * @param bool $first
     * @return void
     */
    private function authenticate(Curl $curl, array $config, bool $first): void
    {
        if (!$first) {
            return;
        }
        $auth = (string) ($config['auth'] ?? 'none');
        if ($auth === 'basic') {
            $curl->setCredentials((string) ($config['username'] ?? ''), $this->secrets->get($config, 'password'));
        } elseif ($auth === 'token') {
            $curl->addHeader('Authorization', 'Bearer ' . $this->secrets->get($config, 'token'));
        }
    }

    /**
     * @param array<string, string> $headers lower-cased
     * @param array<string, mixed> $parts
     * @param string $type content type
     * @return string
     */
    private function fileName(array $headers, array $parts, string $type): string
    {
        $disposition = (string) ($headers['content-disposition'] ?? '');
        if (preg_match('/filename\*=(?:UTF-8\'\')?([^;]+)/i', $disposition, $match) === 1
            || preg_match('/filename="?([^";]+)"?/i', $disposition, $match) === 1) {
            $name = urldecode(trim($match[1], " \t\""));
            $name = $this->lastSegment(str_replace('\\', '/', $name));
            if ($name !== '') {
                return $name;
            }
        }
        $path = (string) ($parts['path'] ?? '');
        $last = $this->lastSegment($path);
        if ($last !== '' && str_contains($last, '.')) {
            return $last;
        }

        return 'download.' . (self::EXTENSIONS[$type] ?? 'csv');
    }

    /**
     * The part after the last slash (SplFileInfo keeps the slash of "/name" on some PHP versions).
     *
     * @param string $path
     * @return string
     */
    private function lastSegment(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }
}
