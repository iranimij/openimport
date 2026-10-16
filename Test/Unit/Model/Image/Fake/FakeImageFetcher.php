<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Image\Fake;

use Iranimij\OpenImport\Model\Image\ImageFetcher;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Answers every address with a file named after it, except the broken ones.
 */
class FakeImageFetcher extends ImageFetcher
{
    /**
     * @var string[]
     */
    public array $asked = [];

    public int $flushes = 0;

    /**
     * @param string[] $broken addresses that fail
     */
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct(private readonly array $broken = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function begin(): void
    {
        $this->asked = [];
    }

    /**
     * @inheritDoc
     */
    public function fetch(string $url): string
    {
        $this->asked[] = $url;
        if (in_array($url, $this->broken, true)) {
            throw new PipelineException($url . ' answered with HTTP 404.');
        }
        $slash = strrpos($url, '/');

        return 'openimport/ab/cdef0123/' . ($slash === false ? $url : substr($url, $slash + 1));
    }

    /**
     * @inheritDoc
     */
    public function flush(): void
    {
        $this->flushes++;
    }

    /**
     * @inheritDoc
     */
    public function counts(): array
    {
        return [
            'downloaded' => count(array_diff(array_unique($this->asked), $this->broken)),
            'unchanged' => 0,
            'failed' => count(array_intersect($this->broken, $this->asked)),
        ];
    }
}
