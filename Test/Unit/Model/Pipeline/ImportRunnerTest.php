<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Entity\RecordReaderPool;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Pipeline\ImportRunner;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\PreviewBuilder;
use Iranimij\OpenImport\Model\Pipeline\ProfileCompiler;
use Iranimij\OpenImport\Model\Pipeline\RunOutcome;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\PreviewRecord;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeConfig;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeCoreImporter;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeErrorLog;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakePreviewStore;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeRecordReader;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeRowHashStore;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeRunResource;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Test\Unit\Model\RunStub;
use PHPUnit\Framework\TestCase;

/**
 * The pipeline with Magento replaced by fakes: what reaches core, what is counted, stored and reported.
 */
class ImportRunnerTest extends TestCase
{
    private string $folder = '';

    private ?FakeCoreImporter $core = null;

    private ?FakeErrorLog $errors = null;

    private ?FakePreviewStore $preview = null;

    private ?FakeRowHashStore $hashes = null;

    private ?FakeRunResource $runResource = null;

    private ?FakeRecordReader $shop = null;

    private ?FakeConfig $config = null;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/openimport-runner-' . bin2hex(random_bytes(4));
        mkdir($this->folder);
        $this->core = new FakeCoreImporter();
        $this->errors = new FakeErrorLog();
        $this->preview = new FakePreviewStore();
        $this->hashes = new FakeRowHashStore();
        $this->runResource = new FakeRunResource();
        $this->shop = new FakeRecordReader([
            'a-1' => ['name' => 'One', 'price' => '10', 'qty' => '1'],
            'a-2' => ['name' => 'Two', 'price' => '20', 'qty' => '2'],
        ], ['de' => ['a-2' => ['name' => 'Zwei']]], 10);
        $this->config = new FakeConfig();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->folder)) {
            rmdir($this->folder);
        }
    }

    private function runner(): ImportRunner
    {
        return new ImportRunner(
            new ReaderFactory(),
            new ProfileCompiler(new MappingCompiler(new TransformPool()), new RowFilterCompiler(), new EntityRegistry()),
            new RecordReaderPool([
                'catalog_product' => $this->shop,
                'openimport_stock_price' => $this->shop,
            ]),
            $this->core,
            $this->errors,
            $this->preview,
            $this->hashes,
            $this->runResource,
            $this->config,
            new PreviewBuilder()
        );
    }

    private function file(string $content, string $extension = 'csv'): string
    {
        $file = $this->folder . '/source.' . $extension;
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * @param array<string, mixed> $data
     * @return ProfileStub
     */
    private function profile(array $data = []): ProfileStub
    {
        return new ProfileStub($data + [
            'profile_id' => 5,
            'name' => 'Test',
            'entity' => 'catalog_product',
            'format' => 'csv',
            'mapping' => [
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'store:de:name', 'source' => 'name_de'],
                ['target' => 'price', 'source' => 'price', 'transforms' => ['decimal_comma']],
            ],
        ]);
    }

    /**
     * @param string $mode
     * @param array<string, mixed> $options
     * @return RunStub
     */
    private function newRun(string $mode = RunInterface::MODE_LIVE, array $options = []): RunStub
    {
        return new RunStub(['run_id' => 9, 'mode' => $mode, 'options' => $options]);
    }

    private function execute(ProfileStub $profile, RunStub $run, string $file): RunOutcome
    {
        return $this->runner()->run($profile, $run, $file, $this->folder, basename($file));
    }

    public function testRowsReachCoreAndAreCounted(): void
    {
        $heartbeats = 0;
        $outcome = $this->runner()->run(
            $this->profile(),
            $this->newRun(),
            $this->file("sku,name,name_de,price\nA-1,One,,\"10,5\"\nA-3,Three,Drei,7\n"),
            $this->folder,
            'lieferung.csv',
            static function () use (&$heartbeats): void {
                $heartbeats++;
            }
        );

        self::assertSame(RunInterface::STATUS_SUCCESS, $outcome->status, $outcome->message);
        self::assertSame(['sku', 'name', 'price', 'store_view_code'], $this->core->columns);
        self::assertSame(
            [
                ['sku' => 'A-1', 'name' => 'One', 'price' => '10.5', 'store_view_code' => ''],
                ['sku' => 'A-3', 'name' => 'Three', 'price' => '7', 'store_view_code' => ''],
                ['sku' => 'A-3', 'name' => 'Drei', 'price' => '', 'store_view_code' => 'de'],
            ],
            $this->core->rows
        );
        self::assertSame('catalog_product', $this->core->lastRequest()->entity);
        self::assertSame('append', $this->core->lastRequest()->behavior);
        self::assertFalse($this->core->lastRequest()->dryRun);
        self::assertSame('openimport-run-9.csv', $this->core->lastRequest()->historyLabel);
        self::assertSame(2, $outcome->counts['read']);
        self::assertSame(2, $outcome->counts['created']);
        self::assertSame(77, $outcome->historyId);
        self::assertStringStartsWith('Created 2, updated 0, deleted 0.', $outcome->message);
        self::assertSame([], $this->hashes->hashes, 'no delta, no hashes');
        self::assertGreaterThan(0, $heartbeats);
        self::assertNotEmpty($this->runResource->progress);
    }

    public function testDeltaSkipsWhatTheLastRunSent(): void
    {
        $profile = $this->profile(['delta' => true]);
        $file = $this->file("sku,name,name_de,price\nA-1,One,,10\nA-2,Two,Zwei,20\n");

        $this->execute($profile, $this->newRun(), $file);
        self::assertCount(2, $this->hashes->hashes[5]);

        $second = $this->execute($profile, $this->newRun(), $file);
        self::assertSame(2, $second->counts['unchanged']);
        self::assertStringStartsWith('Nothing to import: 2 row(s) read, 2 unchanged', $second->message);
        self::assertSame(1, $this->core->calls);

        $changed = $this->execute($profile, $this->newRun(), $this->file("sku,name,name_de,price\nA-1,One,,10\nA-2,Two,Zwo,20\n"));
        self::assertSame(1, $changed->counts['unchanged']);
        self::assertCount(2, $this->core->rows, 'A-2 with its store-view row');

        $full = $this->execute($profile, $this->newRun(RunInterface::MODE_LIVE, ['full' => true]), $file);
        self::assertSame(0, $full->counts['unchanged']);
    }

    public function testDeltaKeepsSeparateHashesForStoreViewLinesOfTheSameRecord(): void
    {
        $profile = $this->profile([
            'delta' => true,
            'mapping' => [
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'store_view_code', 'source' => 'store'],
                ['target' => 'name', 'source' => 'name'],
            ],
        ]);
        $content = "sku,store,name\nA-1,,One\nA-1,de,Eins\nA-1,fr,Un\nA-2,,Two\n";

        $this->execute($profile, $this->newRun(), $this->file($content));
        self::assertCount(4, $this->hashes->hashes[5], 'one hash per line');

        $second = $this->execute($profile, $this->newRun(), $this->file($content));
        self::assertSame(4, $second->counts['unchanged']);
        self::assertSame(1, $this->core->calls);

        $this->execute($profile, $this->newRun(), $this->file(str_replace('Eins', 'Eins!', $content)));
        self::assertSame([['sku' => 'A-1', 'store_view_code' => 'de', 'name' => 'Eins!']], array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip(['sku', 'store_view_code', 'name'])),
            $this->core->rows
        ));
    }

    public function testCoreErrorsPointAtSourceRowsAndKeepTheirHashesOut(): void
    {
        $this->core->errors = [
            new RowError(2, 'invalidPrice', 'Invalid price', 'price', false),
            new RowError(null, 'general', 'Something general', null, true),
        ];
        $outcome = $this->execute(
            $this->profile(['delta' => true, 'on_error' => 'skip', 'allowed_errors' => 5]),
            $this->newRun(),
            $this->file("sku,name,name_de,price\nA-1,One,Eins,10\nA-2,Two,,20\n")
        );

        self::assertSame(RunInterface::STATUS_PARTIAL, $outcome->status);
        self::assertSame(1, $outcome->counts['invalid']);
        self::assertSame(2, $outcome->counts['errors']);
        self::assertSame([3, 0], [$this->errors->stored[0]->sourceRow, $this->errors->stored[1]->sourceRow]);
        self::assertSame('A-2', $this->errors->stored[0]->identifier);
        self::assertSame('price', $this->errors->stored[0]->field);
        self::assertSame(['A-1'], array_keys($this->hashes->hashes[5]));
        self::assertTrue($this->core->lastRequest()->skipErrors);
        self::assertSame(5, $this->core->lastRequest()->allowedErrors);
    }

    public function testOwnErrorsStopTheRunOrAreSkipped(): void
    {
        $file = $this->file("sku,name,name_de,price\nA-1,One,,abc\nA-2,Two,,20\n,Nameless,,1\n");

        $stopped = $this->execute($this->profile(), $this->newRun(), $file);
        self::assertSame(RunInterface::STATUS_FAILED, $stopped->status);
        self::assertSame('2 row(s) could not be prepared (allowed: 0). Nothing was imported. See the errors below.', $stopped->message);
        self::assertSame(0, $this->core->calls);
        self::assertSame([2, 4], [$this->errors->stored[0]->sourceRow, $this->errors->stored[1]->sourceRow]);
        self::assertSame(['sku' => 'A-1', 'name' => 'One', 'name_de' => '', 'price' => 'abc'], $this->errors->stored[0]->sourceData);
        self::assertSame('sku', $this->errors->stored[1]->field);

        $this->errors->stored = [];
        $skipped = $this->execute($this->profile(['on_error' => 'skip', 'allowed_errors' => 3]), $this->newRun(), $file);
        self::assertSame(RunInterface::STATUS_PARTIAL, $skipped->status);
        self::assertSame([['sku' => 'A-2', 'name' => 'Two', 'price' => '20']], array_map(
            static fn (array $row): array => array_intersect_key($row, ['sku' => 1, 'name' => 1, 'price' => 1]),
            $this->core->rows
        ));
        self::assertSame(1, $this->core->lastRequest()->allowedErrors);
    }

    public function testErrorCap(): void
    {
        $this->config->maxErrors = 3;
        $rows = "sku,name,name_de,price\n";
        for ($i = 1; $i <= 1200; $i++) {
            $rows .= "A-$i,Name,,x\n";
        }

        $outcome = $this->execute($this->profile(), $this->newRun(), $this->file($rows));

        self::assertSame(1200, $outcome->counts['errors']);
        self::assertCount(3, $this->errors->stored);
    }

    public function testRowFiltersAndBehaviours(): void
    {
        $file = $this->file("sku,name,name_de,price\nA-1,One,,10\nA-2,Two,,20\nA-3,Three,,30\nA-4,skip me,,40\n");
        $filters = ['mode' => 'exclude', 'conditions' => [['column' => 'name', 'operator' => 'equals', 'value' => 'skip me']]];

        $add = $this->execute($this->profile(['behavior' => 'only_add', 'row_filters' => $filters]), $this->newRun(), $file);
        self::assertSame(1, $add->counts['filtered']);
        self::assertSame(2, $add->counts['skipped']);
        self::assertSame(['A-3'], array_column($this->core->rows, 'sku'));

        $update = $this->execute($this->profile(['behavior' => 'only_update', 'row_filters' => $filters]), $this->newRun(), $file);
        self::assertSame(1, $update->counts['skipped']);
        self::assertSame(['A-1', 'A-2'], array_column($this->core->rows, 'sku'));
    }

    public function testFilterExpressionErrorsAreRowErrors(): void
    {
        $filters = ['conditions' => [['expression' => '{price} / 0 > 1']]];

        $outcome = $this->execute(
            $this->profile(['row_filters' => $filters, 'on_error' => 'skip', 'allowed_errors' => 10]),
            $this->newRun(),
            $this->file("sku,name,name_de,price\nA-1,One,,10\n")
        );

        self::assertSame(1, $outcome->counts['errors']);
        self::assertStringContainsString('Division by zero', $this->errors->stored[0]->message);
        self::assertStringStartsWith('Nothing to import', $outcome->message);
    }

    public function testDryRunPreview(): void
    {
        $outcome = $this->execute(
            $this->profile(),
            $this->newRun(RunInterface::MODE_DRY_RUN),
            $this->file("sku,name,name_de,price\nA-1,One,,\"12,00\"\nA-2,Two,Zwei,20\nA-3,Three,Drei,30\n")
        );

        self::assertSame(RunInterface::STATUS_SUCCESS, $outcome->status, $outcome->message);
        self::assertTrue($this->core->lastRequest()->dryRun);
        self::assertSame(
            [1, 1, 1],
            [$outcome->counts['created'], $outcome->counts['updated'], $outcome->counts['unchanged']]
        );
        self::assertStringStartsWith('Dry run: 1 new, 1 changed, 1 unchanged, 0 deleted. Magento accepts the rows.', $outcome->message);
        $records = $this->preview->byIdentifier();
        self::assertSame(['price' => ['10', '12']], $records['A-1']->diff);
        self::assertSame(PreviewRecord::UNCHANGED, $records['A-2']->changeType);
        self::assertSame(PreviewRecord::UNCHANGED, $records['A-2 [de]']->changeType);
        self::assertSame(PreviewRecord::NEW, $records['A-3']->changeType);
        self::assertSame(PreviewRecord::NEW, $records['A-3 [de]']->changeType);
        self::assertSame(4, $records['A-3']->sourceRow);
    }

    public function testDryRunThatMagentoRefuses(): void
    {
        $this->core->valid = false;
        $outcome = $this->execute(
            $this->profile(),
            $this->newRun(RunInterface::MODE_DRY_RUN),
            $this->file("sku,name,name_de,price\nA-1,One,,1\n")
        );

        self::assertSame(RunInterface::STATUS_FAILED, $outcome->status);
        self::assertStringContainsString('Magento refuses the rows', $outcome->message);
    }

    public function testLiveRunThatMagentoRefuses(): void
    {
        $this->core->valid = false;
        $outcome = $this->execute($this->profile(), $this->newRun(), $this->file("sku,name,name_de,price\nA-1,One,,1\n"));

        self::assertSame(RunInterface::STATUS_FAILED, $outcome->status);
        self::assertSame('Magento refused the rows. Nothing was imported.', $outcome->message);
    }

    public function testDeleteNeedsConfirmationAboveTheThreshold(): void
    {
        $profile = $this->profile(['behavior' => 'delete', 'safety_threshold' => 10, 'delta' => true]);
        $this->hashes->hashes[5] = ['A-1' => 'x', 'A-9' => 'y'];
        $file = $this->file("sku,name,name_de,price\nA-1,,,\nA-2,,,\nA-7,,,\n");

        $dry = $this->execute($profile, $this->newRun(RunInterface::MODE_DRY_RUN), $file);
        self::assertSame(2, $dry->counts['deleted']);
        self::assertStringContainsString(
            'A live run will ask for confirmation: This run would delete 2 of 10 records (20%, limit 10%). Type DELETE to confirm.',
            $dry->message
        );

        $blocked = $this->execute($profile, $this->newRun(), $file);
        self::assertSame(RunInterface::STATUS_NEEDS_CONFIRMATION, $blocked->status);
        self::assertSame(1, $this->core->calls, 'only the dry run reached core');

        $confirmed = $this->execute($profile, $this->newRun(RunInterface::MODE_LIVE, ['confirmed' => true]), $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $confirmed->status, $confirmed->message);
        self::assertSame('delete', $this->core->lastRequest()->behavior);
        self::assertSame(['A-9' => 'y'], $this->hashes->hashes[5], 'deleted records forget their hashes');

        $this->shop->total = 100;
        $below = $this->execute($profile, $this->newRun(), $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $below->status);
    }

    public function testReplaceOnAnEntityWithoutReaderAlwaysAsks(): void
    {
        $profile = $this->profile([
            'entity' => 'advanced_pricing',
            'behavior' => 'replace',
            'mapping' => [
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'tier_price_website', 'static' => 'All Websites [USD]'],
                ['target' => 'tier_price_customer_group', 'static' => 'ALL GROUPS'],
                ['target' => 'tier_price_qty', 'static' => '1'],
            ],
        ]);

        $outcome = $this->execute($profile, $this->newRun(), $this->file("sku\nA-1\nA-2\n"));

        self::assertSame(RunInterface::STATUS_NEEDS_CONFIRMATION, $outcome->status);
        self::assertStringContainsString('replace up to 2 record(s)', $outcome->message);
        $replace = $this->execute($profile, $this->newRun(RunInterface::MODE_LIVE, ['confirmed' => true]), $this->file("sku\nA-1\n"));
        self::assertSame(RunInterface::STATUS_SUCCESS, $replace->status);
        self::assertSame('replace', $this->core->lastRequest()->behavior);
        self::assertSame('advanced_pricing', $this->core->lastRequest()->entity);
    }

    public function testDryRunWithoutReaderChecksOnly(): void
    {
        $profile = $this->profile([
            'entity' => 'advanced_pricing',
            'mapping' => [
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'tier_price_website', 'static' => 'All Websites [USD]'],
                ['target' => 'tier_price_customer_group', 'static' => 'ALL GROUPS'],
                ['target' => 'tier_price_qty', 'static' => '1'],
            ],
        ]);

        $outcome = $this->execute($profile, $this->newRun(RunInterface::MODE_DRY_RUN), $this->file("sku\nA-1\n"));

        self::assertSame('Dry run: 1 row(s) checked by Magento. Magento accepts the rows.', $outcome->message);
        self::assertSame([], $this->preview->stored);
    }

    public function testStockAndPricesOnlyUpdateExistingProducts(): void
    {
        $profile = $this->profile([
            'entity' => 'openimport_stock_price',
            'mapping' => [['target' => 'sku', 'source' => 'sku'], ['target' => 'qty', 'source' => 'qty']],
        ]);

        $outcome = $this->execute($profile, $this->newRun(), $this->file("sku,qty\nA-1,5\nX-9,1\n"));

        self::assertSame(1, $outcome->counts['skipped']);
        self::assertSame('catalog_product', $this->core->lastRequest()->entity);
        self::assertSame([['sku' => 'A-1', 'qty' => '5']], $this->core->rows);

        // An empty price cell means "leave unchanged": Magento gets the current price instead of an empty required cell.
        $this->shop->records['a-1']['price'] = '12.5';
        $this->execute(
            $this->profile([
                'entity' => 'openimport_stock_price',
                'mapping' => [['target' => 'sku', 'source' => 'sku'], ['target' => 'qty', 'source' => 'qty'], ['target' => 'price', 'source' => 'price']],
            ]),
            $this->newRun(),
            $this->file("sku,qty,price\nA-1,5,\n")
        );
        self::assertSame([['sku' => 'A-1', 'qty' => '5', 'price' => '12.5']], $this->core->rows);

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('openimport_stock_price only updates existing records');
        $this->execute(
            $this->profile(['entity' => 'openimport_stock_price', 'behavior' => 'delete', 'mapping' => [['target' => 'sku', 'source' => 'sku']]]),
            $this->newRun(),
            $this->file("sku\nA-1\n")
        );
    }

    public function testOnlyAddNeedsAReader(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('"Only add new" is not available for customer_address. Use Add/Update.');
        $this->execute(
            $this->profile([
                'entity' => 'customer_address',
                'behavior' => 'only_add',
                'mapping' => [
                    ['target' => '_email', 'source' => 'sku'],
                    ['target' => '_website', 'static' => 'base'],
                    ['target' => '_entity_id', 'static' => ''],
                ],
            ]),
            $this->newRun(),
            $this->file("sku\nA-1\n")
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function unusableProfiles(): array
    {
        return [
            'no mapping' => [['mapping' => []], "sku\nA\n", 'The profile has no mapping yet.'],
            'mapping does not fit' => [[], "SKU,Name\nA,B\n", "The mapping does not fit this file:\nsku: the file has no column \"sku\""],
            'filters do not fit' => [
                ['row_filters' => ['conditions' => [['column' => 'Nope', 'operator' => 'empty']]]],
                "sku,name,name_de,price\nA,B,,1\n",
                "The row filters do not fit this file:\nFilter 1: the file has no column \"Nope\".",
            ],
            'broken xml' => [['format' => 'xml'], '<a><b></a>', 'The XML file is not valid'],
        ];
    }

    /**
     * @dataProvider unusableProfiles
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableProfiles')]
    public function testUnusableProfilesOrFiles(array $data, string $content, string $message): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage($message);
        $this->execute($this->profile($data), $this->newRun(), $this->file($content, ($data['format'] ?? 'csv') === 'xml' ? 'xml' : 'csv'));
    }

    public function testJsonFilesMayAddFieldsLater(): void
    {
        $profile = $this->profile(['format' => 'auto', 'mapping' => [
            ['target' => 'sku', 'source' => 'id'],
            ['target' => 'name', 'source' => 'late'],
        ]]);

        $outcome = $this->execute($profile, $this->newRun(), $this->file('[{"id":"A-1"},{"id":"A-2","late":"Two"}]', 'json'));

        self::assertSame(RunInterface::STATUS_SUCCESS, $outcome->status, $outcome->message);
        self::assertSame([['sku' => 'A-1', 'name' => ''], ['sku' => 'A-2', 'name' => 'Two']], $this->core->rows);
    }
}
