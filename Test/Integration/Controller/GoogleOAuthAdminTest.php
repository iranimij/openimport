<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Controller;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterfaceFactory;
use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Security\Secrets;
use Iranimij\OpenImport\Test\Integration\Connection\GoogleServer;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * "Sign in with Google": the consent page gets a state and a PKCE challenge, the callback trades the code for a
 * refresh token that is stored encrypted, and an unknown state is refused.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
class GoogleOAuthAdminTest extends AbstractBackendController
{
    private ?GoogleServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new GoogleServer($this->_objectManager);
        $this->server->start();
        $this->_objectManager->removeSharedInstance(ConnectionTester::class);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->_objectManager->removeSharedInstance(ConnectionTester::class);
        parent::tearDown();
    }

    private function connection(): ConnectionInterface
    {
        $connection = $this->_objectManager->get(ConnectionFormMapper::class)->fromForm([
            'name' => 'Signed in ' . bin2hex(random_bytes(4)),
            'type' => 'google_drive',
            'settings' => ['auth_google' => 'oauth', 'client_id' => 'client-1', 'client_secret' => 'client-secret'],
        ], $this->_objectManager->get(ConnectionInterfaceFactory::class)->create());

        return $this->_objectManager->get(ConnectionRepositoryInterface::class)->save($connection);
    }

    /**
     * The same request object, emptied (see AdminTest::fresh()).
     */
    private function fresh(): void
    {
        $request = $this->getRequest();
        if ($request instanceof \Magento\Framework\App\Request\Http) {
            $request->setDispatched(false)
                ->clearParams()
                ->setPostValue([])
                ->setMethod(\Magento\Framework\App\Request\Http::METHOD_GET)
                ->setRouteName(null)
                ->setModuleName(null)
                ->setControllerName(null)
                ->setActionName(null)
                ->setPathInfo('');
            \Closure::bind(function (): void {
                $this->originalPathInfo = '';
                $this->pathInfo = '';
                $this->requestString = '';
                $this->beforeForwardInfo = [];
            }, $request, \Magento\Framework\App\Request\Http::class)();
        }
        $this->_objectManager->get(\Magento\Backend\Model\UrlInterface::class)->turnOffSecretKey();
        $response = $this->getResponse();
        if ($response instanceof \Magento\Framework\HTTP\PhpEnvironment\Response) {
            $response->clearBody();
            $response->clearHeader('Location');
            $response->setStatusCode(200);
        }
    }

    private function location(): string
    {
        $header = $this->getResponse()->getHeader('Location');

        return $header === false || $header === null ? '' : (string) $header->getFieldValue();
    }

    public function testSignInStoresTheRefreshToken(): void
    {
        $connection = $this->connection();
        $this->dispatch('backend/openimport/connection/oauthStart/id/' . $connection->getConnectionId());
        $consent = $this->location();
        self::assertStringStartsWith(($this->server?->base ?? '') . '/auth?', $consent);
        parse_str((string) parse_url($consent, PHP_URL_QUERY), $query);
        self::assertSame('client-1', $query['client_id']);
        self::assertStringContainsString('openimport/connection/oauthCallback', (string) $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);
        $state = $this->server?->state() ?? [];
        $state['challenge'] = $query['code_challenge'];
        $this->server?->write($state);

        $this->fresh();
        $this->getRequest()->setParams(['state' => $query['state'], 'code' => 'good-code']);
        $this->dispatch('backend/openimport/connection/oauthCallback');
        $this->assertSessionMessages(
            self::containsEqual(sprintf('"%s" is signed in to Google as shop@example.com.', $connection->getName())),
            MessageInterface::TYPE_SUCCESS
        );
        $stored = $this->_objectManager->get(ConnectionRepositoryInterface::class)->getById((int) $connection->getConnectionId());
        $settings = $stored->getSettings();
        self::assertSame('shop@example.com', $settings['account']);
        self::assertSame('refresh-1', $this->_objectManager->get(Secrets::class)->get($settings, 'refresh_token'));
        self::assertStringNotContainsString('refresh-1', (string) json_encode($settings));
        self::assertSame('client-secret', $this->_objectManager->get(Secrets::class)->get($settings, 'client_secret'));

        self::assertSame(
            'Connected to Google Drive (shop@example.com). The login folder has 0 file(s).',
            $this->_objectManager->get(ConnectionTester::class)->test($stored)
        );
    }

    public function testAnUnknownStateIsRefused(): void
    {
        $this->getRequest()->setParams(['state' => 'not-from-this-session', 'code' => 'good-code']);
        $this->dispatch('backend/openimport/connection/oauthCallback');
        $this->assertSessionMessages(
            self::containsEqual('This sign-in is unknown or older than 15 minutes. Start it again from the connection.'),
            MessageInterface::TYPE_ERROR
        );
    }
}
