<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Controller;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\UploadStore;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Filesystem;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The admin pages and endpoints: grids, form, save, fields, preview, run, run page, status, confirm, cancel, error
 * report and file download.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
class AdminTest extends AbstractBackendController
{
    private function upload(string $content, string $extension = 'csv'): string
    {
        $token = bin2hex(random_bytes(16)) . '.' . $extension;
        $directory = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->writeFile(UploadStore::FOLDER . '/' . $token, $content);

        return $token;
    }

    private function profile(string $token, string $behavior = 'add_update'): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Admin test ' . bin2hex(random_bytes(4)))
            ->setEntity('catalog_product')
            ->setSourceType('upload')
            ->setSourceConfig(['file' => $token, 'file_name' => 'products.csv'])
            ->setBehavior($behavior)
            ->setMapping([
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'price', 'source' => 'price'],
                ['target' => 'attribute_set_code', 'static' => 'Default'],
                ['target' => 'product_type', 'static' => 'simple'],
                ['target' => 'product_websites', 'static' => 'base'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param string $uri
     * @param array<string, mixed> $data
     * @return void
     */
    /**
     * A fresh request and an empty response body (Magento appends every rendered page to the shared response).
     */
    private function fresh(): void
    {
        $this->fresh();
        $this->_objectManager->removeSharedInstance(\Magento\Framework\App\ResponseInterface::class);
        $this->_objectManager->removeSharedInstance(\Magento\Framework\App\Response\Http::class);
        $this->_response = null;
    }

    private function post(string $uri, array $data): void
    {
        $formKey = $this->_objectManager->get(FormKey::class)->getFormKey();
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST)->setPostValue($data + ['form_key' => $formKey]);
        $this->dispatch($uri);
    }

    private function redirectedRunId(): int
    {
        $header = $this->getResponse()->getHeader('Location');
        $location = $header === false || $header === null ? '' : (string) $header->getFieldValue();
        preg_match('#/id/(\d+)#', $location, $match);

        return (int) ($match[1] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return (array) json_decode((string) $this->getResponse()->getBody(), true);
    }

    public function testGridsAndForm(): void
    {
        $this->dispatch('backend/openimport/profile/index');
        self::assertStringContainsString('openimport_profile_listing', (string) $this->getResponse()->getBody());

        $this->fresh();
        $this->dispatch('backend/openimport/run/index');
        self::assertStringContainsString('openimport_run_listing', (string) $this->getResponse()->getBody());

        $this->fresh();
        $this->dispatch('backend/openimport/profile/new');
        $body = (string) $this->getResponse()->getBody();
        self::assertStringContainsString('openimport_profile_form', $body);
        self::assertStringNotContainsString('<script>alert', $body);
    }

    public function testSaveCreatesAProfileAndKeepsTheFormOnErrors(): void
    {
        $name = 'Saved ' . bin2hex(random_bytes(4));
        $this->post('backend/openimport/profile/save', [
            'name' => $name,
            'direction' => 'import',
            'entity' => 'catalog_product',
            'source_type' => 'upload',
            'format' => 'csv',
            'format_options' => ['delimiter' => ';', 'encoding' => 'auto'],
            'behavior' => 'only_update',
            'on_error' => 'skip',
            'allowed_errors' => '5',
            'delta' => '1',
            'schedule_preset' => 'custom',
            'schedule_custom' => '15 3 * * *',
            'filter_mode' => 'exclude',
            'filter_match' => 'any',
            'row_filters' => [['column' => 'Status', 'operator' => 'equals', 'value' => 'inactive']],
            'mapping' => [
                ['target' => 'sku', 'source' => 'Artikelnummer', 'transforms' => '["trim"]', 'value_map' => ''],
                ['target' => 'status', 'source' => 'Aktiv', 'transforms' => '["map"]', 'value_map' => "ja => 1\nnein => 2",
                    'value_map_strict' => '1'],
                ['target' => 'store:default:name', 'source' => '', 'static' => 'Fixed', 'transforms' => '[]'],
            ],
        ]);
        $this->assertRedirect($this->stringContains('openimport/profile/index'));
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->getByName($name);
        self::assertSame('only_update', $profile->getBehavior());
        self::assertSame('15 3 * * *', $profile->getSchedule());
        self::assertSame(['delimiter' => ';', 'encoding' => 'auto'], $profile->getFormatOptions());
        self::assertSame(['status' => ['ja' => '1', 'nein' => '2']], $profile->getValueMaps());
        self::assertSame('Fixed', $profile->getMapping()[2]['static']);
        self::assertTrue($profile->getMapping()[1]['value_map_strict']);
        self::assertSame('exclude', $profile->getRowFilters()['mode']);

        $this->fresh();
        $this->post('backend/openimport/profile/save', ['name' => '', 'entity' => 'catalog_product', 'behavior' => 'merge']);
        $this->assertSessionMessages(self::containsEqual('Give the profile a name.'), MessageInterface::TYPE_ERROR);
        $this->assertRedirect($this->stringContains('openimport/profile/new'));
    }

    public function testFieldsAndAutoMatch(): void
    {
        $this->post('backend/openimport/profile/fields', [
            'entity' => 'catalog_product',
            'columns' => ['Artikelnummer', 'Bezeichnung', 'Preis', 'name_default'],
        ]);
        $data = $this->json();
        self::assertSame('sku', $data['fields'][0]['code']);
        self::assertTrue($data['fields'][0]['identifier']);
        self::assertSame('Artikelnummer', $data['matches']['sku']);
        self::assertSame('Preis', $data['matches']['price']);
        self::assertSame('name_default', $data['matches']['store:default:name'] ?? null);
        self::assertContains('default', array_column($data['stores'], 'code'));
    }

    public function testSampleAndPreview(): void
    {
        $token = $this->upload("sku;name;price\nA-1;One;\"10,5\"\nA-2;Two;abc\n");
        $this->post('backend/openimport/profile/sample', ['file' => $token, 'file_name' => 'x.csv', 'format' => 'auto']);
        $sample = $this->json();
        self::assertSame(['sku', 'name', 'price'], $sample['columns']);
        self::assertSame(';', $sample['detected']['delimiter']);
        self::assertSame('2', $sample['rows'][0]['__row']);

        $this->fresh();
        $this->post('backend/openimport/profile/preview', [
            'entity' => 'catalog_product',
            'format' => 'auto',
            'source_config' => ['file' => $token, 'file_name' => 'x.csv'],
            'mapping' => [
                ['target' => 'sku', 'source' => 'sku', 'transforms' => '[]'],
                ['target' => 'price', 'source' => 'price', 'transforms' => '["decimal_comma"]'],
            ],
        ]);
        $preview = $this->json();
        self::assertSame([], $preview['problems']);
        self::assertSame(['sku' => 'A-1', 'price' => '10.5'], $preview['rows'][0]['values']);
        self::assertStringContainsString('"abc" is not a number', $preview['rows'][1]['errors'][0]);

        $this->fresh();
        $this->post('backend/openimport/profile/sample', ['file' => '../../app/etc/env.php']);
        self::assertSame('Upload a file first.', $this->json()['error']);
    }

    public function testRunPageConfirmAndReports(): void
    {
        $prefix = 'OI-ADM-' . bin2hex(random_bytes(3));
        $token = $this->upload("sku,name,price\n{$prefix}-1,One,10\n{$prefix}-2,Two,abc\n");
        $profile = $this->profile($token);

        $this->post('backend/openimport/profile/run', ['id' => $profile->getProfileId(), 'mode' => 'dry_run']);
        $this->assertRedirect($this->stringContains('openimport/run/view/id/'));
        $runs = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class);
        $runId = $this->redirectedRunId();
        self::assertSame(RunInterface::STATUS_QUEUED, $runs->getById($runId)->getStatus());

        $this->fresh();
        $this->dispatch('backend/openimport/run/status/id/' . $runId);
        self::assertSame('queued', $this->json()['status']);

        Bootstrap::getObjectManager()->get(RunProcessor::class)->process($runId);
        $this->fresh();
        $this->dispatch('backend/openimport/run/view/id/' . $runId);
        $body = (string) $this->getResponse()->getBody();
        self::assertStringContainsString('Failed', $body);
        self::assertStringContainsString($prefix . '-2', $body);
        self::assertStringContainsString('Download error report', $body);

        $this->fresh();
        $this->dispatch('backend/openimport/run/errorReport/id/' . $runId);
        $report = (string) $this->getResponse()->getBody();
        if ($report === '') {
            $report = (string) file_get_contents(Bootstrap::getObjectManager()->get(Filesystem::class)
                ->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath('openimport/runs/' . $runId . '/errors.csv'));
        }
        self::assertStringContainsString('Row,Record,Field,Error,sku,name,price', $report);

        // A fixed file runs for real from the run page.
        $fixed = $this->upload("sku,name,price\n{$prefix}-1,One,10\n{$prefix}-2,Two,12\n");
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save(
            $profile->setSourceConfig(['file' => $fixed, 'file_name' => 'products.csv'])
        );
        $this->fresh();
        $this->post('backend/openimport/profile/run', ['id' => $profile->getProfileId(), 'mode' => 'dry_run']);
        $dryId = $this->redirectedRunId();
        Bootstrap::getObjectManager()->get(RunProcessor::class)->process($dryId);
        $this->fresh();
        $this->dispatch('backend/openimport/run/view/id/' . $dryId);
        self::assertStringContainsString('Run for real', (string) $this->getResponse()->getBody());
        self::assertStringContainsString('What would change', (string) $this->getResponse()->getBody());

        $this->fresh();
        $this->post('backend/openimport/run/confirm', ['id' => $dryId]);
        $live = $runs->getById($this->redirectedRunId());
        self::assertFalse($live->isDryRun());
        self::assertSame($dryId, $live->getParentRunId());

        $this->fresh();
        $this->post('backend/openimport/run/cancel', ['id' => $live->getRunId()]);
        self::assertSame(RunInterface::STATUS_CANCELLED, $runs->getById((int) $live->getRunId())->getStatus());

        $this->fresh();
        $this->dispatch('backend/openimport/run/download/id/' . $dryId);
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
    }

    public function testDeleteNeedsTheTypedWord(): void
    {
        $prefix = 'OI-ADD-' . bin2hex(random_bytes(3));
        $token = $this->upload("sku\n{$prefix}-1\n");
        $profile = $this->profile($token, 'delete')->setSafetyThreshold(1);
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
        $runs = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class);
        $run = $runs->getById(
            (int) Bootstrap::getObjectManager()->get(\Iranimij\OpenImport\Model\Execution\RunQueue::class)
                ->queue($profile, RunInterface::MODE_LIVE, RunInterface::TRIGGER_MANUAL, [
                    'file' => Bootstrap::getObjectManager()->get(UploadStore::class)->path($token),
                ])->getRunId()
        );
        Bootstrap::getObjectManager()->get(RunResource::class)->claim((int) $run->getRunId());
        $run->setStatus(RunInterface::STATUS_NEEDS_CONFIRMATION)->setSourceFile('openimport/runs/x/source.csv');
        $runs->save($run);

        $this->post('backend/openimport/run/confirm', ['id' => $run->getRunId(), 'confirmation' => 'delete']);
        $this->assertSessionMessages(self::containsEqual('Type DELETE to confirm.'), MessageInterface::TYPE_ERROR);
    }
}
