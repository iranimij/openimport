<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Import;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * URL keys from a pattern and 301 redirects for changed URL keys against a real store.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class UrlKeyImportTest extends TestCase
{
    /**
     * @var string[]|null
     */
    private ?array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files ?? [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return string
     */
    private function csv(array $rows): string
    {
        $file = sys_get_temp_dir() . '/openimport-url-' . bin2hex(random_bytes(5)) . '.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param array<string, string> $options
     * @return ProfileInterface
     */
    private function profile(array $options): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create();
        $profile->setName('URL keys ' . bin2hex(random_bytes(4)))
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setBehavior('add_update')
            ->setOnError('skip')
            ->setAllowedErrors(10)
            ->setImportOptions($options)
            ->setMapping([
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'url_key', 'source' => 'key'],
                ['target' => 'price', 'source' => null, 'static' => '5'],
                ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
                ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
                ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
                ['target' => 'visibility', 'source' => null, 'static' => 'Catalog, Search'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function runImport(ProfileInterface $profile, string $file): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CLI,
            ['file' => $file, 'original_name' => 'keys.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    private function describe(RunInterface $run): string
    {
        $lines = [$run->getMessage()];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId()) as $error) {
            $lines[] = sprintf('row %d %s %s: %s', $error->sourceRow, $error->identifier, $error->field, $error->message);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $requestPath
     * @return array{target: string, redirect: int}|null
     */
    private function rewrite(string $requestPath): ?array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $row = $connection->fetchRow(
            'SELECT target_path, redirect_type FROM ' . $connection->getTableName('url_rewrite')
            . ' WHERE request_path = ? AND entity_type = ?',
            [$requestPath, 'product']
        );

        return $row === false ? null : ['target' => (string) $row['target_path'], 'redirect' => (int) $row['redirect_type']];
    }

    public function testKeysFromThePatternAndRedirectsForChangedKeys(): void
    {
        $sku = 'OI-URL-' . bin2hex(random_bytes(3));
        $profile = $this->profile(['url_key_pattern' => '{name}-{sku}', 'url_redirects' => '1']);

        $first = $this->runImport($profile, $this->csv([['sku' => $sku, 'name' => 'Blaue Jacke', 'key' => '']]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $first->getStatus(), $this->describe($first));
        $old = 'blaue-jacke-' . strtolower($sku) . '.html';
        self::assertSame(0, $this->rewrite($old)['redirect'] ?? null, $this->describe($first));

        $second = $this->runImport($profile, $this->csv([['sku' => $sku, 'name' => 'Rote Jacke', 'key' => '']]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $second->getStatus(), $this->describe($second));
        $new = 'rote-jacke-' . strtolower($sku) . '.html';
        self::assertSame(0, $this->rewrite($new)['redirect'] ?? null);
        self::assertSame(['target' => $new, 'redirect' => 301], $this->rewrite($old), 'the old URL redirects');
    }

    public function testAKeyAnotherProductHasFailsTheRow(): void
    {
        $sku = 'OI-URLD-' . bin2hex(random_bytes(3));
        $profile = $this->profile(['url_key_pattern' => '{name}']);
        $this->runImport($profile, $this->csv([['sku' => $sku . '-A', 'name' => 'Unique ' . $sku, 'key' => '']]));

        $run = $this->runImport($profile, $this->csv([['sku' => $sku . '-B', 'name' => 'Unique ' . $sku, 'key' => '']]));
        $errors = Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId());
        self::assertCount(1, $errors, $this->describe($run));
        self::assertSame('url_key', $errors[0]->field);
        self::assertStringContainsString('belongs to ' . $sku . '-A already', $errors[0]->message);
    }
}
