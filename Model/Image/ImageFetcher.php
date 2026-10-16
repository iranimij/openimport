<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Image;

use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Http\SafeDownloader;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * Turns a picture's web address into a file in the import images folder:
 * - a picture downloaded less than "refresh hours" ago is used as it is (no request);
 * - an older one is asked for again with If-None-Match / If-Modified-Since; 304 keeps the file;
 * - anything else is downloaded through the SafeDownloader (SSRF guard on every hop, size cap, timeout), checked by
 *   its first bytes and kept under a name from its content's SHA-1.
 * When the server cannot be reached but an earlier download exists, the earlier file is used.
 *
 * One fetcher serves one run at a time: begin() resets the run's memory and counters.
 */
class ImageFetcher
{
    /**
     * @var array<string, string> url => file, or "!" + message for addresses that failed in this run
     */
    private array $memo = [];

    /**
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @var string[] addresses served from the cache without a request (their used_at is updated in bulk)
     */
    private array $used = [];

    /**
     * @param SafeDownloader $downloader
     * @param ImageCache $cache
     * @param ImageFolder $folder
     * @param ImageSniffer $sniffer
     * @param ImageName $names
     * @param Config $config
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly SafeDownloader $downloader,
        private readonly ImageCache $cache,
        private readonly ImageFolder $folder,
        private readonly ImageSniffer $sniffer,
        private readonly ImageName $names,
        private readonly Config $config,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @return void
     */
    public function begin(): void
    {
        $this->memo = [];
        $this->used = [];
        $this->counts = ['downloaded' => 0, 'unchanged' => 0, 'failed' => 0];
    }

    /**
     * @param string $url
     * @return string the file, relative to the import images folder
     * @throws PipelineException when the address gives no usable picture
     */
    public function fetch(string $url): string
    {
        if (isset($this->memo[$url])) {
            if (str_starts_with($this->memo[$url], '!')) {
                throw new PipelineException(substr($this->memo[$url], 1));
            }

            return $this->memo[$url];
        }
        try {
            $file = $this->resolve($url);
        } catch (PipelineException $exception) {
            $this->counts['failed']++;
            $this->memo[$url] = '!' . $exception->getMessage();
            throw $exception;
        }

        return $this->memo[$url] = $file;
    }

    /**
     * Saves when the cached pictures were last used; call at the end of a batch.
     *
     * @return void
     */
    public function flush(): void
    {
        if ($this->used !== []) {
            $this->cache->markUsed($this->used);
            $this->used = [];
        }
    }

    /**
     * @return array<string, int> downloaded, unchanged (cache or 304), failed (distinct addresses)
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Whether a picture checked at $checkedAt (UTC) may be used without asking the server.
     *
     * @param string $checkedAt Y-m-d H:i:s, UTC
     * @param int $hours
     * @param \DateTimeImmutable $now
     * @return bool
     */
    public function isFresh(string $checkedAt, int $hours, \DateTimeImmutable $now): bool
    {
        if ($hours <= 0 || $checkedAt === '') {
            return false;
        }
        try {
            $checked = new \DateTimeImmutable($checkedAt, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return false;
        }

        return $checked->getTimestamp() + $hours * 3600 > $now->getTimestamp();
    }

    /**
     * @param string $url
     * @return string
     * @throws PipelineException
     */
    private function resolve(string $url): string
    {
        $cached = $this->cache->find($url);
        if ($cached !== null && !$this->folder->exists($cached['file'])) {
            $cached = null;
        }
        $hours = $this->config->getImageRefreshHours();
        if ($cached !== null && $this->isFresh($cached['checked_at'], $hours, $this->now())) {
            $this->counts['unchanged']++;
            $this->used[] = $url;

            return $cached['file'];
        }
        $headers = ['Accept' => 'image/jpeg, image/png, image/gif;q=0.9, */*;q=0.5'];
        if ($cached !== null && $cached['etag'] !== '') {
            $headers['If-None-Match'] = $cached['etag'];
        }
        if ($cached !== null && $cached['last_modified'] !== '') {
            $headers['If-Modified-Since'] = $cached['last_modified'];
        }
        $this->folder->create(ImageName::FOLDER . '/tmp');
        $temporary = $this->folder->absolute(ImageName::FOLDER . '/tmp/' . bin2hex(random_bytes(8)));
        try {
            $result = $this->downloader->download(
                $url,
                $temporary,
                $this->config->getImageMaxBytes(),
                $this->config->getImageTimeout(),
                $headers
            );
        } catch (PipelineException $exception) {
            if ($cached !== null) {
                // The server is away or refuses now; the earlier download is better than no picture.
                $this->counts['unchanged']++;
                $this->used[] = $url;

                return $cached['file'];
            }
            throw $exception;
        }
        if ($result->notModified()) {
            if ($cached === null) {
                throw new PipelineException(sprintf('%s answered "not modified" to a first request.', $url));
            }
            $this->cache->markChecked($url);
            $this->counts['unchanged']++;

            return $cached['file'];
        }

        try {
            $file = $this->keep($url, $temporary);
        } finally {
            $this->remove($temporary);
        }
        $this->cache->store($url, $file, $result->header('etag'), $result->header('last-modified'), $result->bytes);
        $this->counts['downloaded']++;

        return $file;
    }

    /**
     * Checks the downloaded file and moves it to its content-addressed place.
     *
     * @param string $url
     * @param string $temporary absolute path
     * @return string relative to the images folder
     * @throws PipelineException
     */
    private function keep(string $url, string $temporary): string
    {
        $driver = $this->driver();
        $handle = $driver->fileOpen($temporary, 'r');
        $head = (string) $driver->fileRead($handle, ImageSniffer::HEAD_BYTES);
        $driver->fileClose($handle);
        $type = $this->sniffer->type($head);
        $problem = $this->sniffer->problem($type);
        if ($problem !== '') {
            throw new PipelineException($problem);
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- hashing a local file; streams, unlike the drivers
        $sha1 = (string) sha1_file($temporary);
        $file = $this->names->path($url, $sha1, $type);
        if (!$this->folder->exists($file)) {
            $this->folder->create(substr($file, 0, (int) strrpos($file, '/')));
            $driver->rename($temporary, $this->folder->absolute($file));
        }

        return $file;
    }

    /**
     * @param string $path absolute
     * @return void
     */
    private function remove(string $path): void
    {
        $driver = $this->driver();
        if ($driver->isExists($path)) {
            $driver->deleteFile($path);
        }
    }

    private function driver(): \Magento\Framework\Filesystem\DriverInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
