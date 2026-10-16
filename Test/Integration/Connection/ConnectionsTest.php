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
use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Console\Command\SecretsReencryptCommand;
use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Connection\ConnectionUsage;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Model\Execution\Source\RemoteSource;
use Iranimij\OpenImport\Model\Export\Destination\RemoteDestination;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Saved connections: stored encrypted, used by sources and destinations instead of their own login, travel by name
 * in profile files, cannot be deleted while a profile uses them.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class ConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        FakeRemoteClient::reset();
    }

    private function repository(): ConnectionRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(ConnectionRepositoryInterface::class);
    }

    /**
     * @param array<string, mixed> $settings
     * @param string $type
     * @return ConnectionInterface
     */
    private function connection(array $settings, string $type = 'sftp'): ConnectionInterface
    {
        $connection = Bootstrap::getObjectManager()->get(ConnectionFormMapper::class)->fromForm([
            'name' => 'Supplier ' . uniqid(),
            'type' => $type,
            'settings' => $settings,
        ], Bootstrap::getObjectManager()->get(ConnectionInterfaceFactory::class)->create());

        return $this->repository()->save($connection);
    }

    /**
     * @param array<string, mixed> $source
     * @param string $direction
     * @param array<string, mixed> $export
     * @return ProfileInterface
     */
    private function profile(array $source, string $direction = 'import', array $export = []): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Connection user ' . uniqid())
            ->setDirection($direction)
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setSourceType($direction === 'import' ? 'sftp' : 'upload')
            ->setSourceConfig($source)
            ->setExportOptions($export)
            ->setMapping($direction === 'import' ? [
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
                ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
                ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
            ] : []);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function fakeClients(): RemoteClientFactory
    {
        $objectManager = Bootstrap::getObjectManager();

        return new RemoteClientFactory($objectManager, ['sftp' => FakeRemoteClient::class, 'ftp' => FakeRemoteClient::class]);
    }

    public function testSecretsAreStoredEncryptedAndNamesAreUnique(): void
    {
        $connection = $this->connection(['host' => 'sftp.example.com', 'username' => 'shop', 'password' => 'hunter2']);
        $raw = (string) Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection()->fetchOne(
            'SELECT settings FROM ' . Bootstrap::getObjectManager()->get(ResourceConnection::class)
                ->getTableName('openimport_connection') . ' WHERE connection_id = ?',
            [$connection->getConnectionId()]
        );
        self::assertStringContainsString('sftp.example.com', $raw);
        self::assertStringNotContainsString('hunter2', $raw);
        self::assertSame('hunter2', Bootstrap::getObjectManager()->get(Secrets::class)->get($connection->getSettings(), 'password'));
        self::assertSame($connection->getConnectionId(), $this->repository()->getByName(strtoupper($connection->getName()))->getConnectionId());

        $copy = Bootstrap::getObjectManager()->get(ConnectionInterfaceFactory::class)->create()
            ->setName(' ' . $connection->getName() . ' ')
            ->setType('ftp')
            ->setSettings(['host' => 'ftp.example.com', 'username' => 'u']);
        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage(sprintf('A connection named "%s" already exists.', $connection->getName()));
        $this->repository()->save($copy);
    }

    public function testSourcesAndDestinationsLogInWithTheConnection(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $this->connection(['host' => 'sftp.example.com', 'port' => '2222', 'username' => 'shop', 'password' => 'hunter2']);
        FakeRemoteClient::$files['/out'] = ['stock.csv' => "sku,name\nC-1,One\n"];
        $profile = $this->profile(['connection_id' => (string) $connection->getConnectionId(), 'path' => '/out', 'pattern' => '*.csv']);

        /** @var RemoteSource $source */
        $source = $objectManager->create(RemoteSource::class, ['clients' => $this->fakeClients(), 'protocol' => 'sftp']);
        $folder = sys_get_temp_dir() . '/openimport-connection-' . uniqid();
        mkdir($folder);
        $fetched = $source->fetch($profile, $folder);
        self::assertSame('stock.csv', $fetched->name);
        self::assertSame("sku,name\nC-1,One\n", file_get_contents($fetched->path));
        self::assertSame('sftp.example.com:2222', FakeRemoteClient::$connected?->label());
        self::assertSame('hunter2', FakeRemoteClient::$connected?->password);
        self::assertStringStartsWith('Connected. 1 file(s) in /out match', $source->test($profile));

        $export = $this->profile([], 'export', [
            'destination' => 'sftp', 'connection_id' => (string) $connection->getConnectionId(), 'path' => '/in',
        ]);
        /** @var RemoteDestination $destination */
        $destination = $objectManager->create(RemoteDestination::class, ['clients' => $this->fakeClients(), 'protocol' => 'sftp']);
        self::assertSame('Connected. The folder /in has 0 file(s).', $destination->test($export));

        $ftp = $this->profile(['connection_id' => (string) $connection->getConnectionId(), 'path' => '/out']);
        $ftpSource = $objectManager->create(RemoteSource::class, ['clients' => $this->fakeClients(), 'protocol' => 'ftp']);
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('is for SFTP, not for FTP');
        $ftpSource->test($ftp);
    }

    public function testTheTesterUsesTheConnectionAlone(): void
    {
        $connection = $this->connection(['host' => 'ftp.example.com', 'username' => 'shop', 'password' => 'pw'], 'ftp');
        FakeRemoteClient::$files[''] = ['a.csv' => '', 'b.csv' => ''];
        $tester = Bootstrap::getObjectManager()->create(ConnectionTester::class, ['clients' => $this->fakeClients()]);
        self::assertSame('Connected to ftp.example.com:21. The login folder has 2 file(s).', $tester->test($connection));

        FakeRemoteClient::$refuse = 'pw';
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('The login to ftp.example.com:21 was refused.');
        $tester->test($connection);
    }

    public function testAConnectionInUseCannotBeDeleted(): void
    {
        $connection = $this->connection(['host' => 'sftp.example.com', 'username' => 'shop']);
        $profile = $this->profile(['connection_id' => (string) $connection->getConnectionId(), 'path' => '/out']);
        self::assertSame([$profile->getName()], Bootstrap::getObjectManager()->get(ConnectionUsage::class)->profiles((int) $connection->getConnectionId()));
        try {
            $this->repository()->delete($connection);
            self::fail('a connection in use must not be deleted');
        } catch (CouldNotDeleteException $exception) {
            self::assertStringContainsString($profile->getName(), $exception->getMessage());
        }

        Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save(
            $profile->setSourceConfig(['host' => 'own.example.com', 'username' => 'u', 'path' => '/out'])
        );
        self::assertTrue($this->repository()->delete($connection));
    }

    public function testProfileFilesNameTheConnection(): void
    {
        $connection = $this->connection(['host' => 'sftp.example.com', 'username' => 'shop', 'password' => 'secret']);
        $profile = $this->profile(['connection_id' => (string) $connection->getConnectionId(), 'path' => '/out']);
        $json = Bootstrap::getObjectManager()->get(ProfileJson::class);
        $file = $json->encode([$profile]);
        self::assertStringContainsString('"connection": "' . $connection->getName() . '"', $file);
        self::assertStringNotContainsString('connection_id', $file);
        self::assertStringNotContainsString('secret', $file);

        $prepared = $json->prepare($file);
        self::assertSame((string) $connection->getConnectionId(), $prepared[0][0]->getSourceConfig()['connection_id']);
    }

    public function testReencryptCoversConnectionsAndDestinations(): void
    {
        $this->connection(['host' => 'sftp.example.com', 'username' => 'shop', 'password' => 'again']);
        $output = new BufferedOutput();
        Bootstrap::getObjectManager()->create(SecretsReencryptCommand::class)->run(new ArrayInput([]), $output);
        self::assertMatchesRegularExpression('/\d+ profile\(s\) and [1-9]\d* connection\(s\) with secrets encrypted again\./', $output->fetch());
    }
}
