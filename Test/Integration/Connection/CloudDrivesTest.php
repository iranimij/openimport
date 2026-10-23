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
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettingsBuilder;
use Iranimij\OpenImport\Model\Execution\SourceResolver;
use Iranimij\OpenImport\Model\OAuth\OAuthFlow;
use Iranimij\OpenImport\Model\OAuth\OAuthTokens;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Dropbox and OneDrive/SharePoint against a stand-in for their APIs: "Sign in" (state, PKCE, refresh token stored
 * encrypted), folders, downloads (OneDrive's redirect), uploads (OneDrive's upload sessions in parts), moves into
 * "processed", deletes, and a profile run.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class CloudDrivesTest extends TestCase
{
    private ?CloudServer $server = null;

    protected function setUp(): void
    {
        $this->server = new CloudServer(Bootstrap::getObjectManager());
        $this->server->start([
            'files' => ['Supplier/incoming/products-1.csv' => "sku,name\nC-1,Cloud product\n"],
            'folders' => ['Supplier', 'Supplier/incoming'],
        ]);
        Bootstrap::getObjectManager()->removeSharedInstance(ConnectionTester::class);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        Bootstrap::getObjectManager()->removeSharedInstance(ConnectionTester::class);
    }

    /**
     * A connection signed in through OAuthFlow, as the admin pages do it.
     *
     * @param string $type dropbox or onedrive
     * @param array<string, string> $settings
     * @return ConnectionInterface
     */
    private function signedIn(string $type, array $settings = []): ConnectionInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $objectManager->get(ConnectionFormMapper::class)->fromForm([
            'name' => $type . ' ' . uniqid(),
            'type' => $type,
            'settings' => ['client_id' => 'client-1', 'client_secret' => 'client-secret'] + $settings,
        ], $objectManager->get(ConnectionInterfaceFactory::class)->create());
        $connection = $objectManager->get(ConnectionRepositoryInterface::class)->save($connection);

        $flow = $objectManager->get(OAuthFlow::class);
        $consent = $flow->start($connection);
        parse_str((string) parse_url($consent, PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('client-1', $query['client_id']);
        $state = $this->server?->state() ?? [];
        $state['challenge'] = $query['code_challenge'];
        $this->server?->write($state);

        return $flow->finish((string) $query['state'], 'good-code');
    }

    private function client(ConnectionInterface $connection): RemoteClientInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $client = $objectManager->get(RemoteClientFactory::class)->create($connection->getType());
        $client->connect(
            $objectManager->get(RemoteSettingsBuilder::class)->build($connection->getSettings(), $connection->getType())
        );

        return $client;
    }

    private function local(string $content): string
    {
        $file = sys_get_temp_dir() . '/openimport-cloud-' . bin2hex(random_bytes(5));
        file_put_contents($file, $content);

        return $file;
    }

    public function testDropbox(): void
    {
        $connection = $this->signedIn('dropbox');
        $settings = $connection->getSettings();
        self::assertSame('shop@example.com', $settings['account']);
        self::assertSame('refresh-dbx', Bootstrap::getObjectManager()->get(Secrets::class)->get($settings, 'refresh_token'));
        self::assertArrayNotHasKey(OAuthFlow::PENDING, $settings);
        self::assertSame(
            'Connected to Dropbox (shop@example.com). The login folder has 0 file(s).',
            Bootstrap::getObjectManager()->get(ConnectionTester::class)->test($connection)
        );

        $client = $this->client($connection);
        self::assertSame(['products-1.csv'], array_keys($client->listFiles('/Supplier/incoming/')));
        $copy = $this->local('');
        $client->download('Supplier/incoming/products-1.csv', $copy);
        self::assertSame("sku,name\nC-1,Cloud product\n", file_get_contents($copy));
        $client->upload($this->local('a;b'), 'Exports/stock ä.csv');
        self::assertSame('a;b', $this->server?->state()['files']['Exports/stock ä.csv'] ?? null);
        $client->move('Supplier/incoming/products-1.csv', 'Supplier/incoming/processed/products-1.csv');
        self::assertSame([], $client->listFiles('Supplier/incoming'));
        self::assertSame(['products-1.csv'], array_keys($client->listFiles('Supplier/incoming/processed')));
        $client->delete('Supplier/incoming/processed/products-1.csv');
        self::assertSame([], $client->listFiles('Supplier/incoming/processed'));

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('Dropbox, Missing: not found.');
        $client->listFiles('Missing');
    }

    public function testOneDriveAndSharePoint(): void
    {
        $connection = $this->signedIn('onedrive', ['tenant' => 'contoso.onmicrosoft.com']);
        self::assertSame('shop@example.com', $connection->getSettings()['account']);
        $client = $this->client($connection);
        self::assertSame(['products-1.csv'], array_keys($client->listFiles('Supplier/incoming')));
        $copy = $this->local('');
        $client->download('Supplier/incoming/products-1.csv', $copy);
        self::assertSame("sku,name\nC-1,Cloud product\n", file_get_contents($copy), 'through the redirect');

        // Larger than one upload part (10 MB): two parts.
        $big = str_repeat('x', 10485760 + 100);
        $client->upload($this->local($big), 'Exports/big.csv');
        $state = $this->server?->state() ?? [];
        self::assertSame(strlen($big), strlen((string) ($state['files']['Exports/big.csv'] ?? '')));
        $parts = array_values(array_filter($state['requests'], static fn (string $r): bool => str_starts_with($r, 'PUT /ms/upload/')));
        self::assertCount(2, $parts);
        self::assertStringEndsWith('bytes 10485760-10485859/10485860', $parts[1]);
        $client->upload($this->local(''), 'Exports/empty.csv');
        self::assertSame('', $this->server?->state()['files']['Exports/empty.csv'] ?? null);

        $client->move('Supplier/incoming/products-1.csv', 'Supplier/incoming/processed/products-1.csv');
        self::assertSame(['products-1.csv'], array_keys($client->listFiles('Supplier/incoming/processed')));
        $client->delete('Supplier/incoming/processed/products-1.csv');
        self::assertSame([], $client->listFiles('Supplier/incoming/processed'));

        $site = $this->signedIn('onedrive', ['site' => 'https://contoso.sharepoint.com/sites/Shop']);
        $this->client($site)->listFiles('');
        $requests = implode("\n", $this->server?->state()['requests'] ?? []);
        self::assertStringContainsString('GET /ms/graph/sites/contoso.sharepoint.com:/sites/Shop', $requests);
        self::assertStringContainsString('Sites.ReadWrite.All', urldecode($requests) . $this->scopeOf($site));
    }

    private function scopeOf(ConnectionInterface $connection): string
    {
        $consent = Bootstrap::getObjectManager()->get(OAuthFlow::class)->start($connection);
        parse_str((string) parse_url($consent, PHP_URL_QUERY), $query);

        return (string) $query['scope'];
    }

    public function testAProfileReadsFromDropboxAndAWrongCodeIsRefused(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $this->signedIn('dropbox');
        $profile = $objectManager->get(ProfileInterfaceFactory::class)->create()
            ->setName('From Dropbox ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setSourceType('dropbox')
            ->setSourceConfig([
                'connection_id' => (string) $connection->getConnectionId(),
                'path' => 'Supplier/incoming',
                'pattern' => 'products-*.csv',
            ])
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

        $flow = $objectManager->get(OAuthFlow::class);
        parse_str((string) parse_url($flow->start($connection), PHP_URL_QUERY), $query);
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('Dropbox refused the login: invalid_grant');
        $flow->finish((string) $query['state'], 'bad-code');
    }

    public function testPkceChallenge(): void
    {
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            Bootstrap::getObjectManager()->get(OAuthTokens::class)->challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')
        );
    }
}
