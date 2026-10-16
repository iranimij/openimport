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
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettingsBuilder;
use Iranimij\OpenImport\Model\Execution\Source\Remote\S3Signer;
use Iranimij\OpenImport\Model\Execution\SourceResolver;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The S3 client against a real S3-compatible server (CI starts one and sets OPENIMPORT_TEST_S3 to
 * "endpoint|access key|secret key"): signed list, upload, download, move and delete, the tester, and a profile
 * that reads its file from a bucket.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class S3Test extends TestCase
{
    private string $endpoint = '';
    private string $accessKey = '';
    private string $secretKey = '';
    private string $bucket = '';

    protected function setUp(): void
    {
        $server = (string) getenv('OPENIMPORT_TEST_S3');
        if ($server === '') {
            self::markTestSkipped('OPENIMPORT_TEST_S3 is not set (CI starts an S3-compatible server).');
        }
        [$this->endpoint, $this->accessKey, $this->secretKey] = array_pad(explode('|', $server), 3, '');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue(Config::XML_ALLOWED_HOSTS, $host);
        $this->bucket = 'openimport-' . bin2hex(random_bytes(5));
        $this->createBucket();
    }

    /**
     * PUT /bucket, signed like every other request.
     */
    private function createBucket(): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parts = parse_url($this->endpoint);
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $headers = (new S3Signer())->sign(
            'PUT',
            $host,
            '/' . $this->bucket,
            [],
            [],
            S3Signer::EMPTY_PAYLOAD,
            $this->accessKey,
            $this->secretKey,
            'us-east-1',
            new \DateTimeImmutable()
        );
        $curl = Bootstrap::getObjectManager()->get(CurlFactory::class)->create();
        foreach ($headers + ['Content-Length' => '0'] as $name => $value) {
            $curl->addHeader($name, $value);
        }
        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
        $curl->get(rtrim($this->endpoint, '/') . '/' . $this->bucket);
        self::assertSame(200, $curl->getStatus(), 'create the bucket: ' . $curl->getBody());
    }

    /**
     * @param string $secret
     * @return ConnectionInterface
     */
    private function connection(string $secret = ''): ConnectionInterface
    {
        $connection = Bootstrap::getObjectManager()->get(ConnectionFormMapper::class)->fromForm([
            'name' => 'Bucket ' . $this->bucket . ' ' . uniqid(),
            'type' => 's3',
            'settings' => [
                'endpoint' => $this->endpoint,
                'region' => 'us-east-1',
                'bucket' => $this->bucket,
                'path_style' => '1',
                'access_key' => $this->accessKey,
                'secret_key' => $secret !== '' ? $secret : $this->secretKey,
            ],
        ], Bootstrap::getObjectManager()->get(ConnectionInterfaceFactory::class)->create());

        return Bootstrap::getObjectManager()->get(ConnectionRepositoryInterface::class)->save($connection);
    }

    private function local(string $content): string
    {
        $file = sys_get_temp_dir() . '/openimport-s3-' . bin2hex(random_bytes(5));
        file_put_contents($file, $content);

        return $file;
    }

    public function testUploadListDownloadMoveDelete(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $this->connection();
        $client = $objectManager->get(RemoteClientFactory::class)->create('s3');
        $client->connect($objectManager->get(RemoteSettingsBuilder::class)->build($connection->getSettings(), 's3'));

        self::assertSame([], $client->listFiles('incoming'));
        $client->upload($this->local("sku,qty\nS3-1,5\n"), '/incoming/stock 1.csv');
        $client->upload($this->local('{}'), 'incoming/deeper/other.json');
        $files = $client->listFiles('/incoming/');
        self::assertSame(['stock 1.csv'], array_keys($files), 'files of the folder, not of its subfolders');
        self::assertGreaterThan(time() - 3600, (int) $files['stock 1.csv']);

        $copy = $this->local('');
        $client->download('incoming/stock 1.csv', $copy);
        self::assertSame("sku,qty\nS3-1,5\n", file_get_contents($copy));

        $client->move('incoming/stock 1.csv', 'incoming/processed/stock 1.csv');
        self::assertSame([], $client->listFiles('incoming'));
        self::assertSame(['stock 1.csv'], array_keys($client->listFiles('incoming/processed')));

        $client->delete('incoming/processed/stock 1.csv');
        self::assertSame([], $client->listFiles('incoming/processed'));
        try {
            $client->download('incoming/gone.csv', $this->local(''));
            self::fail('a missing file is an error');
        } catch (PipelineException $exception) {
            self::assertStringContainsString('incoming/gone.csv', $exception->getMessage());
            self::assertStringContainsString('the file does not exist', $exception->getMessage());
        }
        $client->close();
    }

    public function testTheTesterAndAWrongSecret(): void
    {
        $tester = Bootstrap::getObjectManager()->get(ConnectionTester::class);
        self::assertMatchesRegularExpression(
            '/^Connected to bucket openimport-[0-9a-f]+ \(.+\)\. The login folder has 0 file\(s\)\.$/',
            $tester->test($this->connection())
        );

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('the secret key does not fit the access key');
        $tester->test($this->connection('not-the-secret'));
    }

    public function testAProfileReadsItsFileFromTheBucket(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $this->connection();
        $client = $objectManager->get(RemoteClientFactory::class)->create('s3');
        $client->connect($objectManager->get(RemoteSettingsBuilder::class)->build($connection->getSettings(), 's3'));
        $client->upload($this->local("sku,name\nS3-2,Bucket product\n"), 'feeds/products-1.csv');
        $client->close();

        $profile = $objectManager->get(ProfileInterfaceFactory::class)->create()
            ->setName('From a bucket ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setSourceType('s3')
            ->setSourceConfig([
                'connection_id' => (string) $connection->getConnectionId(),
                'path' => 'feeds',
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

        $folder = sys_get_temp_dir() . '/openimport-s3-run-' . uniqid();
        mkdir($folder);
        $fetched = $source->fetch($profile, $folder);
        self::assertSame("sku,name\nS3-2,Bucket product\n", file_get_contents($fetched->path));
        $source->finish($profile, $fetched->reference);
        self::assertStringContainsString('none matches', $source->test($profile), 'moved to feeds/processed');
    }
}
