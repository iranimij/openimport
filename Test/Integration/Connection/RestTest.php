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
use Iranimij\OpenImport\Api\Data\RunInterfaceFactory;
use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Rest\RestClient;
use Iranimij\OpenImport\Model\Rest\RestDestination;
use Iranimij\OpenImport\Model\Rest\RestSource;
use Iranimij\OpenImport\Model\Writer\WriterFactory;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * A REST API as source and destination, against Test/Integration/_files/rest-server.php: every kind of paging, three
 * kinds of login, retries after 429, the page limit, file and batch exports.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class RestTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $process = null;

    private ?string $stateFile = null;

    private ?string $base = null;

    /**
     * @var int[]|null seconds the client waited
     */
    private ?array $waits = [];

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/openimport-rest-' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($this->stateFile, '{}');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        // phpcs:ignore Magento2.Security.InsecureFunction -- starts the test web server, fixed command, no user input
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../_files/rest-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['OPENIMPORT_REST_STATE' => $this->stateFile]
        );
        $this->base = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 50; $i++) {
            // phpcs:ignore Generic.PHP.NoSilencedErrors -- "not listening yet" is expected while the server starts
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->get(MutableScopeConfigInterface::class)->setValue(Config::XML_ALLOWED_HOSTS, '127.0.0.1');
        $this->waits = [];
        $client = $objectManager->create(RestClient::class, ['wait' => function (int $seconds): void {
            $this->waits[] = $seconds;
        }]);
        $objectManager->addSharedInstance($client, RestClient::class);
        foreach ([RestSource::class, RestDestination::class, ConnectionTester::class] as $shared) {
            $objectManager->removeSharedInstance($shared);
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            // phpcs:ignore Magento2.Security.InsecureFunction -- stops the test web server
            proc_terminate($this->process);
            // phpcs:ignore Magento2.Security.InsecureFunction -- stops the test web server
            proc_close($this->process);
        }
        foreach ([RestClient::class, RestSource::class, RestDestination::class, ConnectionTester::class] as $shared) {
            Bootstrap::getObjectManager()->removeSharedInstance($shared);
        }
        if (is_string($this->stateFile) && is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /**
     * @param array<string, string> $settings
     * @return ConnectionInterface
     */
    private function connection(array $settings = []): ConnectionInterface
    {
        $connection = Bootstrap::getObjectManager()->get(ConnectionFormMapper::class)->fromForm([
            'name' => 'API ' . uniqid(),
            'type' => 'rest',
            'settings' => $settings + ['base_url' => $this->base . '/v1', 'auth_rest' => 'header',
                'header_name' => 'X-Api-Key', 'token' => 'key-1'],
        ], Bootstrap::getObjectManager()->get(ConnectionInterfaceFactory::class)->create());

        return Bootstrap::getObjectManager()->get(ConnectionRepositoryInterface::class)->save($connection);
    }

    /**
     * @param ConnectionInterface $connection
     * @param array<string, string> $source
     * @param array<string, string> $export
     * @return ProfileInterface
     */
    private function profile(ConnectionInterface $connection, array $source, array $export = []): ProfileInterface
    {
        return Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('API profile ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat('json')
            ->setSourceType('rest')
            ->setSourceConfig(['connection_id' => (string) $connection->getConnectionId()] + $source)
            ->setExportOptions($export === [] ? [] : ['connection_id' => (string) $connection->getConnectionId()] + $export);
    }

    /**
     * @param ProfileInterface $profile
     * @return string[] the SKUs the source delivered
     */
    private function skus(ProfileInterface $profile): array
    {
        $folder = sys_get_temp_dir() . '/openimport-rest-run-' . uniqid();
        mkdir($folder);
        $fetched = Bootstrap::getObjectManager()->get(RestSource::class)->fetch($profile, $folder);
        self::assertSame(RestSource::FILE_NAME, $fetched->name);

        return array_column(json_decode((string) file_get_contents($fetched->path), true), 'sku');
    }

    public function testEveryKindOfPaging(): void
    {
        $connection = $this->connection();
        $all = ['P-1', 'P-2', 'P-3', 'P-4', 'P-5'];
        self::assertSame($all, $this->skus($this->profile($connection, [
            'path' => '/products?status=active', 'records' => 'data.items', 'paging' => 'page',
            'size_param' => 'per_page', 'page_size' => '2',
        ])));
        self::assertSame($all, $this->skus($this->profile($connection, [
            'path' => 'offset', 'paging' => 'offset', 'page_param' => 'offset', 'size_param' => 'limit', 'page_size' => '2',
        ])));
        self::assertSame($all, $this->skus($this->profile($connection, [
            'path' => 'cursor', 'records' => 'results', 'paging' => 'cursor', 'cursor_path' => 'meta.next_cursor',
        ])));
        self::assertSame($all, $this->skus($this->profile($connection, [
            'path' => 'next', 'paging' => 'next_url', 'next_path' => 'links.next',
        ])), 'the first list of the answer when no records path is set');
        self::assertSame($all, $this->skus($this->profile($connection, ['path' => 'link', 'paging' => 'link'])));

        $requests = (array) (json_decode((string) file_get_contents((string) $this->stateFile), true)['requests'] ?? []);
        self::assertContains('GET /v1/products?page=2&per_page=2&status=active', $requests);
        self::assertSame(
            'Connected. The first page has 2 record(s) with the fields sku, name, stock.',
            Bootstrap::getObjectManager()->get(RestSource::class)->test($this->profile($connection, [
                'path' => 'link', 'paging' => 'link',
            ]))
        );
    }

    public function testLoginsRetriesAndLimits(): void
    {
        $basic = $this->connection(['auth_rest' => 'basic', 'username' => 'user', 'password' => 'pass', 'token' => '']);
        self::assertCount(5, $this->skus($this->profile($basic, ['path' => 'slow'])));
        self::assertSame([1, 1], $this->waits, 'waited as Retry-After asked, twice');

        $oauth = $this->connection([
            'auth_rest' => 'oauth2', 'token_url' => $this->base . '/token', 'client_id' => 'cid', 'client_secret' => 'csecret',
        ]);
        self::assertSame(
            sprintf('Reached %s/v1 (HTTP 200).', $this->base),
            Bootstrap::getObjectManager()->get(ConnectionTester::class)->test($oauth)
        );

        try {
            $this->skus($this->profile($this->connection(['token' => 'wrong']), ['path' => 'link']));
            self::fail('a refused login is an error');
        } catch (PipelineException $exception) {
            self::assertStringStartsWith('link: the login was refused.', $exception->getMessage());
        }

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('The API has more than 3 pages; nothing was imported.');
        $this->skus($this->profile($basic, ['path' => 'forever', 'paging' => 'next_url', 'next_path' => 'links.next', 'max_pages' => '3']));
    }

    public function testExportsAsFileAndInBatches(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $this->connection();
        $file = sys_get_temp_dir() . '/openimport-rest-export-' . uniqid() . '.json';
        file_put_contents($file, (string) json_encode([['sku' => 'E-1'], ['sku' => 'E-2'], ['sku' => 'E-3']]));
        $writer = $objectManager->get(WriterFactory::class)->create('json');
        $run = $objectManager->get(RunInterfaceFactory::class)->create();
        $destination = $objectManager->get(RestDestination::class);

        $message = $destination->deliver(
            $this->profile($connection, [], ['path' => 'import', 'method' => 'PUT', 'send' => 'batches', 'batch_size' => '2']),
            $run,
            $file,
            $writer
        );
        self::assertSame(sprintf('Sent 3 record(s) in 2 batch(es) to PUT %s/v1/import.', $this->base), $message);
        self::assertSame(
            sprintf('Sent the file to POST %s/v1/import.', $this->base),
            $destination->deliver($this->profile($connection, [], ['path' => 'import']), $run, $file, $writer)
        );
        $received = (array) (json_decode((string) file_get_contents((string) $this->stateFile), true)['received'] ?? []);
        self::assertSame(['PUT', 'PUT', 'POST'], array_column($received, 'method'));
        self::assertSame('[{"sku":"E-1"},{"sku":"E-2"}]', $received[0]['body']);
        self::assertSame('[{"sku":"E-3"}]', $received[1]['body']);
        self::assertSame(file_get_contents($file), $received[2]['body']);
        self::assertStringStartsWith('application/json', (string) $received[2]['type']);

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('The API refused the file at missing: HTTP 404.');
        $destination->deliver($this->profile($connection, [], ['path' => 'missing']), $run, $file, $writer);
    }
}
