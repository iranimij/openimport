<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Image;

use Iranimij\OpenImport\Model\Image\ImageSniffer;
use PHPUnit\Framework\TestCase;

class ImageSnifferTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function heads(): array
    {
        return [
            'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", 'jpg'],
            'png' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR", 'png'],
            'gif87' => ['GIF87a' . "\x01\x00", 'gif'],
            'gif89' => ['GIF89a' . "\x01\x00", 'gif'],
            'webp' => ['RIFF' . "\x24\x00\x00\x00" . 'WEBPVP8 ', 'webp'],
            'avif' => ["\x00\x00\x00\x1C" . 'ftypavif' . "\x00\x00", 'avif'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg">', 'svg'],
            'svg with xml declaration' => ['<?xml version="1.0"?><svg ', 'svg'],
            'error page' => ["\xEF\xBB\xBF  <!DOCTYPE html><html><body>Not found", 'html'],
            'html without doctype' => ['<html><head><title>', 'html'],
            'text' => ['Just some text', ''],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider heads
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('heads')]
    public function testType(string $head, string $type): void
    {
        self::assertSame($type, (new ImageSniffer())->type($head));
    }

    public function testProblems(): void
    {
        $sniffer = new ImageSniffer();
        foreach (ImageSniffer::SUPPORTED as $type) {
            self::assertSame('', $sniffer->problem($type));
        }
        self::assertSame('The address answered with a web page, not a picture.', $sniffer->problem('html'));
        self::assertSame('The address did not answer with a picture (JPG, PNG or GIF).', $sniffer->problem(''));
        self::assertSame(
            'The picture is WEBP; Magento\'s product import accepts JPG, PNG and GIF.',
            $sniffer->problem('webp')
        );
    }
}
