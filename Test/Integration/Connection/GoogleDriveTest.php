<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Connection;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterfaceFactory;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettingsBuilder;
use Iranimij\OpenImport\Model\Execution\SourceResolver;
use Iranimij\OpenImport\Model\Google\DriveClient;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Google Drive against a stand-in for Google's API: the service account's signed JWT, folders by link, id and path,
 * Sheets read as Excel files, uploads (new, replaced, as a Google Sheet), moves into "processed", the bin.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class GoogleDriveTest extends TestCase
{
    private const FOLDER = '1AbCdEfGhIjKlMnOpQrStUvWxYz012345';

    private ?GoogleServer $server = null;

    protected function setUp(): void
    {
        $this->server = new GoogleServer(Bootstrap::getObjectManager());
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    private function connection(?string $key = null): ConnectionInterface
    {
        $connection = Bootstrap::getObjectManager()->get(ConnectionFormMapper::class)->fromForm([
            'name' => 'Drive ' . uniqid(),
            'type' => 'google_drive',
            'settings' => [
                'auth_google' => 'service_account',
                'service_account_json' => $key ?? $this->server?->serviceAccountKey(),
            ],
        ], Bootstrap::getObjectManager()->get(ConnectionInterfaceFactory::class)->create());

        return Bootstrap::getObjectManager()->get(ConnectionRepositoryInterface::class)->save($connection);
    }

    /**
     * @param ConnectionInterface $connection
     * @param array<string, string> $extra profile settings (as_sheet)
     * @return DriveClient
     */
    private function client(ConnectionInterface $connection, array $extra = []): DriveClient
    {
        $objectManager = Bootstrap::getObjectManager();
        $client = $objectManager->get(RemoteClientFactory::class)->create('google_drive');
        self::assertInstanceOf(DriveClient::class, $client);
        $client->connect($objectManager->get(RemoteSettingsBuilder::class)->build($connection->getSettings() + $extra, 'google_drive'));

        return $client;
    }

    private function local(string $content): string
    {
        $file = sys_get_temp_dir() . '/openimport-drive-' . bin2hex(random_bytes(5)) . '.csv';
        file_put_contents($file, $content);

        return $file;
    }

    public function testFoldersByLinkIdAndPathAndSheetsAsExcel(): void
    {
        $connection = $this->connection();
        self::assertSame('importer@openimport-test.iam.gserviceaccount.com', $connection->getSettings()['account']);
        $this->server?->addFile('fileStock000000000000000001', 'stock.csv', self::FOLDER, "sku,qty\nG-1,4\n");
        $this->server?->addFile('fileSheet000000000000000001', 'Prices', self::FOLDER, 'sheet', DriveClient::SHEET);
        $this->server?->addFile('fileDoc0000000000000000001', 'Notes', self::FOLDER, '', 'application/vnd.google-apps.document');
        $this->server?->addFile(self::FOLDER, 'Supplier', 'root', '', DriveClient::FOLDER);
        $client = $this->client($connection);

        $byLink = $client->listFiles('https://drive.google.com/drive/folders/' . self::FOLDER . '?usp=sharing');
        self::assertSame(['stock.csv', 'Prices.xlsx'], array_keys($byLink));
        self::assertSame(array_keys($byLink), array_keys($client->listFiles(self::FOLDER)));
        self::assertSame(array_keys($byLink), array_keys($client->listFiles('Supplier')), 'a path from My Drive');

        $copy = $this->local('');
        $client->download(self::FOLDER . '/Prices.xlsx', $copy);
        self::assertSame('XLSX:sheet', file_get_contents($copy), 'a Sheet is exported');
        $client->download('Supplier/stock.csv', $copy);
        self::assertSame("sku,qty\nG-1,4\n", file_get_contents($copy));

        $client->move(self::FOLDER . '/stock.csv', self::FOLDER . '/processed/stock.csv');
        self::assertSame(['Prices.xlsx'], array_keys($client->listFiles(self::FOLDER)));
        self::assertSame(['stock.csv'], array_keys($client->listFiles(self::FOLDER . '/processed')), 'the folder was made');
        $client->delete(self::FOLDER . '/processed/stock.csv');
        self::assertSame([], $client->listFiles(self::FOLDER . '/processed'));
        self::assertTrue((bool) ($this->server?->state()['files']['fileStock000000000000000001']['trashed'] ?? false), 'in the bin');

        try {
            $client->listFiles('Supplier/Missing');
            self::fail('a missing folder is an error');
        } catch (PipelineException $exception) {
            self::assertSame('Google Drive has no folder "Missing" in Supplier/Missing.', $exception->getMessage());
        }
    }

    public function testUploadsReplaceAFileOfTheSameNameAndCanBecomeASheet(): void
    {
        $connection = $this->connection();
        $client = $this->client($connection);
        $client->upload($this->local("a\n1\n"), self::FOLDER . '/export.csv');
        $client->upload($this->local("a\n2\n"), self::FOLDER . '/export.csv');
        $files = array_filter($this->server?->state()['files'] ?? [], static fn (array $file): bool => $file['name'] === 'export.csv');
        self::assertCount(1, $files, 'the second upload replaced the first');
        self::assertSame("a\n2\n", array_values($files)[0]['content']);

        $sheets = $this->client($connection, ['as_sheet' => '1']);
        $sheets->upload($this->local("a\n3\n"), self::FOLDER . '/report.csv');
        $sheet = array_values(array_filter($this->server?->state()['files'] ?? [], static fn (array $file): bool => $file['name'] === 'report'));
        self::assertSame(DriveClient::SHEET, $sheet[0]['mimeType'] ?? null, 'a Google Sheet named without .csv');
        $sheets->upload($this->local("a\n4\n"), self::FOLDER . '/report.csv');
        $sheet = array_values(array_filter($this->server?->state()['files'] ?? [], static fn (array $file): bool => $file['name'] === 'report'));
        self::assertCount(1, $sheet);
        self::assertSame("a\n4\n", $sheet[0]['content']);
    }

    public function testTheTesterAndAKeyGoogleRefuses(): void
    {
        $tester = Bootstrap::getObjectManager()->get(ConnectionTester::class);
        self::assertSame(
            'Connected to Google Drive (importer@openimport-test.iam.gserviceaccount.com). The login folder has 0 file(s).',
            $tester->test($this->connection())
        );

        // A key the server does not know: the JWT signature does not fit.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $private);
        $key = json_decode($this->server?->serviceAccountKey() ?? '{}', true);
        $key['private_key'] = $private;
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('Google refused the login: invalid_grant (Invalid JWT Signature.)');
        $tester->test($this->connection((string) json_encode($key)));
    }

    public function testAProfileReadsAndMovesItsFile(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $this->connection();
        $this->server?->addFile('fileProducts00000000000001', 'products-1.csv', self::FOLDER, "sku,name\nG-2,Drive product\n");
        $profile = $objectManager->get(ProfileInterfaceFactory::class)->create()
            ->setName('From Drive ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setSourceType('google_drive')
            ->setSourceConfig([
                'connection_id' => (string) $connection->getConnectionId(),
                'path' => 'https://drive.google.com/drive/folders/' . self::FOLDER,
                'pattern' => 'products-*.csv',
            ])
            ->setAfterSuccess('move')
            ->setMapping([
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
                ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
                ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
            ]);
        $profile = $objectManager->get(ProfileRepositoryInterface::class)->save($profile);
        $source = $objectManager->get(SourceResolver::class)->source($profile);
        self::assertStringContainsString('the next run reads products-1.csv', $source->test($profile));

        $folder = sys_get_temp_dir() . '/openimport-drive-run-' . uniqid();
        mkdir($folder);
        $fetched = $source->fetch($profile, $folder);
        self::assertSame("sku,name\nG-2,Drive product\n", file_get_contents($fetched->path));
        $source->finish($profile, $fetched->reference);
        self::assertStringContainsString('none matches', $source->test($profile));
    }
}
