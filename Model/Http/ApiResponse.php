<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Http;

/**
 * The answer of an API request.
 */
class ApiResponse
{
    /**
     * @param int $status
     * @param string $body
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    /**
     * @return bool 2xx
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed> the body as JSON object, [] when it is none
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }
}
