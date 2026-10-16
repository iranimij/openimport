<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Http;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\UrlGuard;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Downloads a web address to a local file the safe way, for every part of OpenImport that reads the web (URL sources,
 * pictures): http and https only, every redirect hop checked by the SSRF guard and connected to the address the guard
 * checked, a size cap, a timeout, and conditional requests (ETag, Last-Modified) for caches.
 */
class SafeDownloader
{
    public const USER_AGENT = 'OpenImport (+https://github.com/iranimij/openimport)';
    private const MAX_HOPS = 5;
    private const REDIRECTS = [301, 302, 303, 307, 308];

    /**
     * @param CurlFactory $curlFactory
     * @param UrlGuard $guard
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly UrlGuard $guard,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @param string $url
     * @param string $target absolute path of the file to write
     * @param int $maxBytes
     * @param int $timeout seconds
     * @param array<string, string> $headers request headers (If-None-Match, If-Modified-Since, Accept …)
     * @param callable|null $prepare called with the client and whether it is the first hop (credentials go only
     *                               to the first host)
     * @return DownloadResult status 2xx, or 304 when a conditional request found nothing new (target untouched)
     * @throws PipelineException
     */
    public function download(
        string $url,
        string $target,
        int $maxBytes,
        int $timeout,
        array $headers = [],
        ?callable $prepare = null
    ): DownloadResult {
        $driver = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
        $timeout = max(1, min(600, $timeout));
        $part = $target . '.part';
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            [$parts, $address] = $this->guard->check($url);
            $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
            $handle = $driver->fileOpen($part, 'w');
            $curl = $this->curlFactory->create();
            $curl->setTimeout($timeout);
            $curl->addHeader('User-Agent', self::USER_AGENT);
            foreach ($headers as $name => $value) {
                $curl->addHeader($name, $value);
            }
            if ($prepare !== null) {
                $prepare($curl, $hop === 0);
            }
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
                $this->remove($part);
                throw new PipelineException(sprintf('%s could not be downloaded: %s', $url, $exception->getMessage()));
            }
            $driver->fileClose($handle);
            $status = (int) $curl->getStatus();
            $answer = $this->headers($curl);
            if (in_array($status, self::REDIRECTS, true) && isset($answer['location'])) {
                $url = $this->absolute($answer['location'], $parts);
                continue;
            }
            if ($status === 304) {
                $this->remove($part);
                return new DownloadResult($url, 304, 0, '', $answer);
            }
            if ($status < 200 || $status >= 300) {
                $this->remove($part);
                throw new PipelineException(sprintf('%s answered with HTTP %d.', $url, $status));
            }
            $bytes = (int) ($driver->stat($part)['size'] ?? 0);
            if ($bytes > $maxBytes) {
                $this->remove($part);
                throw new PipelineException(sprintf('%s is larger than %s.', $url, $this->size($maxBytes)));
            }
            $driver->rename($part, $target);
            $type = strtolower(trim(explode(';', $answer['content-type'] ?? '')[0]));

            return new DownloadResult($url, $status, $bytes, $type, $answer);
        }
        throw new PipelineException(sprintf('%s redirects too often (more than %d times).', $url, self::MAX_HOPS));
    }

    /**
     * @param Curl $curl
     * @return array<string, string> lower-cased names
     */
    private function headers(Curl $curl): array
    {
        $headers = [];
        foreach ($curl->getHeaders() as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return $headers;
    }

    /**
     * @param string $location
     * @param array<string, mixed> $parts of the current URL
     * @return string
     */
    public function absolute(string $location, array $parts): string
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
     * @param int $bytes
     * @return string
     */
    public function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1f MB', $bytes / 1048576)
            : ($bytes >= 1024 ? sprintf('%.0f KB', $bytes / 1024) : $bytes . ' bytes');
    }

    /**
     * @param string $path
     * @return void
     */
    private function remove(string $path): void
    {
        $driver = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
        if ($driver->isExists($path)) {
            $driver->deleteFile($path);
        }
    }
}
