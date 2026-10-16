<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Image;

use Iranimij\OpenImport\Model\Image\ImageFetcher;
use Iranimij\OpenImport\Model\Image\ImageName;
use PHPUnit\Framework\TestCase;

class ImageNameTest extends TestCase
{
    public function testPathComesFromTheContentAndTheAddress(): void
    {
        $sha1 = sha1('picture');
        self::assertSame(
            'openimport/' . substr($sha1, 0, 2) . '/' . substr($sha1, 2, 8) . '/red-shoe_2.jpg',
            (new ImageName())->path('https://cdn.example.com/media/Red%20Shoe_2.JPEG?w=800#top', $sha1, 'jpg')
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['https://a.example/p/blue.png', 'blue'],
            'no path' => ['https://a.example', 'image'],
            'folder only' => ['https://a.example/images/', 'image'],
            'no extension' => ['https://a.example/img/12345', '12345'],
            'only symbols' => ['https://a.example/%E2%9C%93.png', 'image'],
            'dots inside' => ['https://a.example/v1.2/shirt.front.large.jpg', 'shirt-front-large'],
            'hidden file' => ['https://a.example/.png', 'png'],
            'long' => ['https://a.example/' . str_repeat('a', 120) . '.jpg', str_repeat('a', 80)],
        ];
    }

    /**
     * @dataProvider names
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('names')]
    public function testName(string $url, string $name): void
    {
        self::assertSame($name, (new ImageName())->name($url));
    }

    public function testFreshness(): void
    {
        $fetcher = (new \ReflectionClass(ImageFetcher::class))->newInstanceWithoutConstructor();
        $now = new \DateTimeImmutable('2026-10-16 12:00:00', new \DateTimeZone('UTC'));
        self::assertTrue($fetcher->isFresh('2026-10-16 01:00:00', 24, $now));
        self::assertTrue($fetcher->isFresh('2026-10-15 12:00:01', 24, $now));
        self::assertFalse($fetcher->isFresh('2026-10-15 12:00:00', 24, $now), 'exactly 24 hours old is stale');
        self::assertFalse($fetcher->isFresh('2026-10-16 11:59:00', 0, $now), '0 hours = ask every run');
        self::assertFalse($fetcher->isFresh('', 24, $now));
        self::assertFalse($fetcher->isFresh('not a date', 24, $now));
    }
}
