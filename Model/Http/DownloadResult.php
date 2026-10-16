<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Http;

/**
 * What a download through SafeDownloader brought back.
 */
class DownloadResult
{
    /**
     * @param string $url the address that answered (after redirects)
     * @param int $status HTTP status of the final answer (200, or 304 when nothing changed)
     * @param int $bytes size of the downloaded file (0 for 304)
     * @param string $contentType lower-cased, without parameters
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly int $bytes,
        public readonly string $contentType,
        public readonly array $headers
    ) {
    }

    /**
     * @return bool the server said the file did not change (conditional request)
     */
    public function notModified(): bool
    {
        return $this->status === 304;
    }

    /**
     * @param string $name lower-cased header name
     * @return string
     */
    public function header(string $name): string
    {
        return (string) ($this->headers[$name] ?? '');
    }
}
