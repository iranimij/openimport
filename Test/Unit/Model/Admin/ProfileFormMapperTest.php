<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Admin;

use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Iranimij\OpenImport\Model\JsonField;
use Iranimij\OpenImport\Model\Security\Secrets;
use Iranimij\OpenImport\Test\Unit\Model\Security\FakeEncryptor;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

class ProfileFormMapperTest extends TestCase
{
    public function testFormRoundTrip(): void
    {
        $mapper = new ProfileFormMapper(new JsonField(), new Secrets(new FakeEncryptor()));
        $posted = [
            'name' => '  Supplier A ',
            'direction' => 'import',
            'entity' => 'catalog_product',
            'is_active' => '1',
            'source_type' => 'upload',
            'source_config' => ['file' => 'abc.csv', 'file_name' => ' a.csv '],
            'format' => 'csv',
            'format_options' => ['delimiter' => ';', 'encoding' => '', 'has_header' => '1'],
            'behavior' => 'only_update',
            'on_error' => 'skip',
            'allowed_errors' => '7',
            'delta' => '1',
            'safety_threshold' => '',
            'schedule_preset' => '0 2 * * *',
            'schedule_custom' => '5 5 * * *',
            'notify' => 'always',
            'notify_emails' => 'a@b.de',
            'after_success' => 'move',
            'filter_mode' => 'exclude',
            'filter_match' => 'any',
            'row_filters' => [
                ['column' => 'Status', 'operator' => 'equals', 'value' => 'x'],
                ['column' => '', 'operator' => 'equals', 'value' => 'ignored'],
                ['column' => '', 'operator' => 'expression', 'value' => '{Qty} > 0'],
                'junk',
            ],
            'mapping' => [
                ['target' => 'sku', 'source' => 'Art', 'static' => '', 'transforms' => '["trim"]', 'required' => '1'],
                ['target' => 'status', 'source' => 'Aktiv', 'transforms' => '["map"]',
                    'value_map' => "ja => 1\n\nnein=>2\nkein pfeil\nja => doppelt", 'value_map_strict' => '1'],
                ['target' => 'attribute_set_code', 'source' => '', 'static' => 'Default', 'transforms' => '[]'],
                ['target' => '', 'source' => '', 'static' => ''],
                ['target' => 'x', 'delete' => '1'],
            ],
        ];

        $profile = $mapper->fromForm($posted, new ProfileStub(['source_config' => ['secrets' => ['password' => 'enc']]]));

        self::assertSame('Supplier A', $profile->getName());
        self::assertTrue($profile->isActive());
        self::assertSame('0 2 * * *', $profile->getSchedule());
        self::assertSame(['file' => 'abc.csv', 'file_name' => 'a.csv', 'secrets' => ['password' => 'enc']], $profile->getSourceConfig());
        self::assertSame(['delimiter' => ';', 'has_header' => '1'], $profile->getFormatOptions());
        self::assertNull($profile->getSafetyThreshold());
        self::assertSame(7, $profile->getAllowedErrors());
        self::assertSame(
            ['mode' => 'exclude', 'match' => 'any', 'conditions' => [
                ['column' => 'Status', 'operator' => 'equals', 'value' => 'x'],
                ['expression' => '{Qty} > 0'],
            ]],
            $profile->getRowFilters()
        );
        self::assertCount(3, $profile->getMapping());
        self::assertSame(['trim'], $profile->getMapping()[0]['transforms']);
        self::assertTrue($profile->getMapping()[0]['required']);
        self::assertNull($profile->getMapping()[2]['source']);
        self::assertSame('Default', $profile->getMapping()[2]['static']);
        self::assertSame(['status' => ['ja' => '1', 'nein' => '2']], $profile->getValueMaps());

        $form = $mapper->toForm($profile);
        self::assertSame('Supplier A', $form['name']);
        self::assertSame('0 2 * * *', $form['schedule_preset']);
        self::assertSame(
            ['file' => 'abc.csv', 'file_name' => 'a.csv', 'password_stored' => '1', 'private_key_stored' => '0',
                'passphrase_stored' => '0', 'token_stored' => '0'],
            $form['source_config'],
            'secrets never reach the form, only whether one is stored'
        );
        self::assertSame("ja => 1\nnein => 2", $form['mapping'][1]['value_map']);
        self::assertSame('1', $form['mapping'][1]['value_map_strict']);
        self::assertSame('["trim"]', $form['mapping'][0]['transforms']);
        self::assertSame('expression', $form['row_filters'][1]['operator']);
        self::assertSame('{Qty} > 0', $form['row_filters'][1]['value']);
    }

    public function testEveryDayAtATime(): void
    {
        $mapper = new ProfileFormMapper(new JsonField(), new Secrets(new FakeEncryptor()));
        $profile = $mapper->fromForm(['schedule_preset' => 'daily', 'schedule_time' => '7:05'], new ProfileStub());

        self::assertSame('5 7 * * *', $profile->getSchedule());
        $form = $mapper->toForm($profile);
        self::assertSame('daily', $form['schedule_preset']);
        self::assertSame('07:05', $form['schedule_time']);
        // A listed preset stays the preset; other daily times come back as "daily".
        self::assertSame('0 2 * * *', $mapper->toForm(new ProfileStub(['schedule' => '0 2 * * *']))['schedule_preset']);
        self::assertSame('*/15 * * * *', $mapper->toForm(new ProfileStub(['schedule' => '*/15 * * * *']))['schedule_preset']);
        self::assertSame('custom', $mapper->toForm(new ProfileStub(['schedule' => '0 25 * * *']))['schedule_preset']);
    }

    public function testCustomScheduleAndNoFilters(): void
    {
        $mapper = new ProfileFormMapper(new JsonField(), new Secrets(new FakeEncryptor()));
        $profile = $mapper->fromForm(
            ['schedule_preset' => 'custom', 'schedule_custom' => ' 30 5 * * 1-6 ', 'row_filters' => []],
            new ProfileStub()
        );

        self::assertSame('30 5 * * 1-6', $profile->getSchedule());
        self::assertSame([], $profile->getRowFilters());
        $form = $mapper->toForm($profile);
        self::assertSame('custom', $form['schedule_preset']);
        self::assertSame('30 5 * * 1-6', $form['schedule_custom']);
        self::assertSame('', $mapper->toForm(new ProfileStub())['schedule_preset']);
        // A new profile (and one that never stored has_header) shows the header toggle on.
        self::assertSame('1', $mapper->toForm(new ProfileStub())['format_options']['has_header']);
        self::assertSame('0', $mapper->toForm(new ProfileStub(['format_options' => ['has_header' => '0']]))['format_options']['has_header']);
    }
}
