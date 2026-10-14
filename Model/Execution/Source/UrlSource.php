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
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Iranimij\OpenImport\Model\Security\UrlGuard;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * A file behind a web address: http(s), optional basic auth or bearer token, Google Sheets links (a shared sheet's
 * edit link or a published link become its CSV export). Every redirect hop goes through the SSRF guard again and
 * connects to the address the guard checked. The download is streamed to the run folder, capped at max_mb.
 *
 * source_config: url, auth (none | basic | token), username, sheet_gid, timeout, max_mb; secrets: password, token.
 */
class UrlSource implements SourceInterface
{
    private const MAX_HOPS = 5;
    private const DEFAULT_MAX_MB = 256;
    private const EXTENSIONS = [
        'text/csv' => 'csv',
        'text/tab-separated-values' => 'tsv',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    /**
     * @param CurlFactory $curlFactory
     * @param UrlGuard $guard
     * @param Secrets $secrets
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly UrlGuard $guard,
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

        return sprintf('Downloaded %s (%s%s).', $name, $this->size($bytes), $type === '' ? '' : ', ' . $type);
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
     * Downloads to a local file, following at most MAX_HOPS redirects.
     *
     * @param array<string, mixed> $config
     * @param string $target
     * @return array{0: string, 1: int, 2: string} file name, bytes, content type
     * @throws PipelineException
     */
    private function download(array $config, string $target): array
    {
        $url = $this->url($config);
        $maxBytes = max(1, (int) ($config['max_mb'] ?? self::DEFAULT_MAX_MB)) * 1048576;
        $timeout = max(5, min(600, (int) ($config['timeout'] ?? 60)));
        $driver = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            [$parts, $address] = $this->guard->check($url);
            $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
            $handle = $driver->fileOpen($target, 'w');
            $curl = $this->curlFactory->create();
            $curl->setTimeout($timeout);
            $curl->addHeader('User-Agent', 'OpenImport/1.0 (+https://github.com/iranimij/openimport)');
            $curl->addHeader('Accept', 'text/csv, application/json, application/xml, text/xml, */*');
            $this->authenticate($curl, $config, $hop === 0);
            $curl->setOptions([
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXFILESIZE => $maxBytes,
                CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
                CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $address],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            try {
                $curl->get($url);
            } catch (\Exception $exception) {
                $driver->fileClose($handle);
                throw new PipelineException(sprintf('%s could not be downloaded: %s', $url, $exception->getMessage()));
            }
            $driver->fileClose($handle);
            $status = (int) $curl->getStatus();
            $headers = array_change_key_case($curl->getHeaders(), CASE_LOWER);
            if (in_array($status, [301, 302, 303, 307, 308], true) && isset($headers['location'])) {
                $url = $this->absolute((string) $headers['location'], $parts);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new PipelineException(sprintf('%s answered with HTTP %d.', $url, $status));
            }
            $stat = $driver->stat($target);
            $bytes = (int) ($stat['size'] ?? 0);
            if ($bytes > $maxBytes) {
                throw new PipelineException(sprintf('The file is larger than %d MB.', (int) ($maxBytes / 1048576)));
            }
            $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));

            return [$this->fileName($headers, $parts, $type), $bytes, $type];
        }
        throw new PipelineException(sprintf('%s redirects too often (more than %d times).', $url, self::MAX_HOPS));
    }

    /**
     * Basic auth or a bearer token, only on the first request (not sent along to another host after a redirect).
     *
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param array<string, mixed> $config
     * @param bool $first
     * @return void
     */
    private function authenticate(\Magento\Framework\HTTP\Client\Curl $curl, array $config, bool $first): void
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
     * @param string $location
     * @param array<string, mixed> $parts of the current URL
     * @return string
     */
    private function absolute(string $location, array $parts): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $base . $location;
        }
        $path = (string) ($parts['path'] ?? '/');

        return $base . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
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
            $name = self::lastSegment(str_replace('\\', '/', $name));
            if ($name !== '') {
                return $name;
            }
        }
        $path = (string) ($parts['path'] ?? '');
        $last = self::lastSegment($path);
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
    private static function lastSegment(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }

    /**
     * @param int $bytes
     * @return string
     */
    private function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1f MB', $bytes / 1048576)
            : ($bytes >= 1024 ? sprintf('%.0f KB', $bytes / 1024) : $bytes . ' bytes');
    }
}
