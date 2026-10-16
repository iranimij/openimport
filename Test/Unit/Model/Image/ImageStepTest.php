<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Image;

use Iranimij\OpenImport\Model\Image\ImageStep;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;
use Iranimij\OpenImport\Test\Unit\Model\Image\Fake\FakeImageColumns;
use Iranimij\OpenImport\Test\Unit\Model\Image\Fake\FakeImageFetcher;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

/**
 * Which cells the picture step touches and what it does when a picture cannot be read.
 */
class ImageStepTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     * @return ProfileStub
     */
    private function profile(array $data = []): ProfileStub
    {
        return new ProfileStub($data + ['entity' => 'catalog_product']);
    }

    public function testOnlyProductRunsThatKeepRecords(): void
    {
        $step = new ImageStep(new FakeImageFetcher(), new FakeImageColumns());
        self::assertTrue($step->begin($this->profile(), 'add_update', false));
        self::assertTrue($step->begin($this->profile(), 'replace', true));
        self::assertFalse($step->begin($this->profile(), 'delete', false));
        self::assertFalse($step->begin($this->profile(['entity' => 'customer']), 'add_update', false));
    }

    public function testAddressesBecomeFilesAndOtherCellsStay(): void
    {
        $fetcher = new FakeImageFetcher();
        $step = new ImageStep($fetcher, new FakeImageColumns());
        $step->begin($this->profile(), 'add_update', false);
        $record = new StepRecord(2, 'A-1', [
            [
                'sku' => 'A-1',
                'description' => 'See https://example.com/a.jpg',
                'base_image' => ' https://cdn.example.com/a.jpg ',
                'small_image' => 'local/a.jpg',
                'thumbnail_image' => '',
                'hover_image' => 'HTTP://cdn.example.com/hover.png',
                'additional_images' => 'https://cdn.example.com/b.jpg, local/c.jpg,https://cdn.example.com/d.gif',
                'hide_from_product_page' => 'https://cdn.example.com/b.jpg',
                'base_image_label' => 'https://not-a-picture-column.example/x.jpg',
            ],
            ['sku' => 'A-1', 'store_view_code' => 'de', 'base_image' => 'https://cdn.example.com/de.jpg'],
        ]);
        $ticks = 0;
        $step->apply([$record], static function () use (&$ticks): void {
            $ticks++;
        });

        self::assertFalse($record->isFailed());
        self::assertFalse($record->isIncomplete());
        self::assertSame('See https://example.com/a.jpg', $record->rows[0]['description']);
        self::assertSame('openimport/ab/cdef0123/a.jpg', $record->rows[0]['base_image']);
        self::assertSame('local/a.jpg', $record->rows[0]['small_image']);
        self::assertSame('', $record->rows[0]['thumbnail_image']);
        self::assertSame('openimport/ab/cdef0123/hover.png', $record->rows[0]['hover_image']);
        self::assertSame(
            'openimport/ab/cdef0123/b.jpg,local/c.jpg,openimport/ab/cdef0123/d.gif',
            $record->rows[0]['additional_images']
        );
        self::assertSame('openimport/ab/cdef0123/b.jpg', $record->rows[0]['hide_from_product_page']);
        self::assertSame('https://not-a-picture-column.example/x.jpg', $record->rows[0]['base_image_label']);
        self::assertSame('openimport/ab/cdef0123/de.jpg', $record->rows[1]['base_image']);
        self::assertGreaterThanOrEqual(1, $ticks);
        self::assertSame(1, $fetcher->flushes);
        self::assertStringStartsWith('Pictures: ', $step->summary());
    }

    public function testAPictureThatCannotBeReadFailsTheRecord(): void
    {
        $step = new ImageStep(new FakeImageFetcher(['https://cdn.example.com/gone.jpg']), new FakeImageColumns());
        $step->begin($this->profile(), 'add_update', false);
        $broken = new StepRecord(3, 'A-2', [[
            'sku' => 'A-2',
            'base_image' => 'https://cdn.example.com/ok.jpg',
            'additional_images' => 'https://cdn.example.com/gone.jpg',
        ]]);
        $fine = new StepRecord(4, 'A-3', [['sku' => 'A-3', 'base_image' => 'https://cdn.example.com/ok.jpg']]);
        $step->apply([$broken, $fine], static function (): void {
        });

        self::assertTrue($broken->isFailed());
        self::assertSame('additional_images', $broken->errorColumn());
        self::assertSame(
            'Picture https://cdn.example.com/gone.jpg: https://cdn.example.com/gone.jpg answered with HTTP 404.',
            $broken->error()
        );
        self::assertFalse($fine->isFailed(), 'the next record is still handled');
        self::assertSame('openimport/ab/cdef0123/ok.jpg', $fine->rows[0]['base_image']);
    }

    public function testSkipImportsTheRecordWithoutThePicture(): void
    {
        $step = new ImageStep(new FakeImageFetcher(['https://cdn.example.com/gone.jpg']), new FakeImageColumns());
        $step->begin($this->profile(['import_options' => ['images_on_error' => 'skip']]), 'add_update', false);
        $records = [];
        for ($i = 1; $i <= 7; $i++) {
            $records[] = new StepRecord($i + 1, 'S-' . $i, [[
                'sku' => 'S-' . $i,
                'base_image' => 'https://cdn.example.com/gone.jpg',
                'additional_images' => 'https://cdn.example.com/gone.jpg,https://cdn.example.com/ok.jpg',
            ]]);
        }
        $step->apply($records, static function (): void {
        });

        self::assertFalse($records[0]->isFailed());
        self::assertTrue($records[0]->isIncomplete(), 'the delta must try the record again');
        self::assertSame('', $records[0]->rows[0]['base_image'], 'an empty cell leaves the current picture');
        self::assertSame('openimport/ab/cdef0123/ok.jpg', $records[0]->rows[0]['additional_images']);
        $summary = $step->summary();
        self::assertStringContainsString(
            'Imported without the picture: S-1 (https://cdn.example.com/gone.jpg): https://cdn.example.com/gone.jpg answered with HTTP 404.',
            $summary
        );
        self::assertStringEndsWith(' and 9 more', $summary, '14 pictures were left out, 5 are named');
    }

    public function testNoSummaryWithoutAddresses(): void
    {
        $step = new ImageStep(new FakeImageFetcher(), new FakeImageColumns());
        $step->begin($this->profile(), 'add_update', false);
        $step->apply([new StepRecord(2, 'A', [['sku' => 'A', 'base_image' => 'a.jpg']])], static function (): void {
        });
        self::assertSame('', $step->summary());
    }
}
