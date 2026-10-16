<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Controller;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Test\Integration\Connection\FakeRemoteClient;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The connection pages: grid, form, save, test, delete, and the connection select of the profile form.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
class ConnectionAdminTest extends AbstractBackendController
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeRemoteClient::reset();
        $this->_objectManager->removeSharedInstance(ConnectionTester::class);
        $this->_objectManager->addSharedInstance(
            new RemoteClientFactory($this->_objectManager, ['sftp' => FakeRemoteClient::class, 'ftp' => FakeRemoteClient::class]),
            RemoteClientFactory::class
        );
    }

    protected function tearDown(): void
    {
        $this->_objectManager->removeSharedInstance(ConnectionTester::class);
        $this->_objectManager->removeSharedInstance(RemoteClientFactory::class);
        parent::tearDown();
    }

    /**
     * The same request object, emptied (see AdminTest::fresh()).
     */
    private function fresh(): void
    {
        $request = $this->getRequest();
        if ($request instanceof HttpRequest) {
            $request->setDispatched(false)
                ->clearParams()
                ->setPostValue([])
                ->setMethod(HttpRequest::METHOD_GET)
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
            }, $request, HttpRequest::class)();
        }
        $this->_objectManager->get(\Magento\Backend\Model\UrlInterface::class)->turnOffSecretKey();
        $response = $this->getResponse();
        if ($response instanceof \Magento\Framework\HTTP\PhpEnvironment\Response) {
            $response->clearBody();
            $response->clearHeader('Location');
            $response->setStatusCode(200);
        }
    }

    /**
     * @param string $uri
     * @param array<string, mixed> $data
     * @return void
     */
    private function post(string $uri, array $data): void
    {
        $this->fresh();
        $formKey = $this->_objectManager->get(FormKey::class)->getFormKey();
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST)->setPostValue($data + ['form_key' => $formKey]);
        $this->dispatch($uri);
    }

    /**
     * @param string $uri
     * @return string
     */
    private function page(string $uri): string
    {
        $this->fresh();
        $this->dispatch($uri);
        $body = (string) $this->getResponse()->getBody();
        self::assertNotSame('', $body, $uri . ' answered without a body');

        return $body;
    }

    public function testGridAndForm(): void
    {
        self::assertStringContainsString('openimport_connection_listing', $this->page('backend/openimport/connection/index'));
        self::assertStringContainsString('openimport_connection_form', $this->page('backend/openimport/connection/new'));
    }

    public function testSaveTestAndDelete(): void
    {
        $name = 'Admin connection ' . bin2hex(random_bytes(4));
        $this->post('backend/openimport/connection/save', [
            'name' => $name,
            'type' => 'sftp',
            'settings' => ['host' => 'sftp.example.com', 'username' => 'shop', 'auth_sftp' => 'password', 'password' => 'hunter2'],
        ]);
        $this->assertRedirect($this->stringContains('openimport/connection/index'));
        $connection = $this->_objectManager->get(ConnectionRepositoryInterface::class)->getByName($name);

        $form = $this->page('backend/openimport/connection/edit/id/' . $connection->getConnectionId());
        self::assertStringContainsString('sftp.example.com', $form);
        self::assertStringNotContainsString('hunter2', $form);
        self::assertStringContainsString($name, $this->page('backend/openimport/profile/new'), 'offered in the profile form');

        // Test with the stored password: the form leaves the field empty.
        $this->post('backend/openimport/connection/test', [
            'connection_id' => (string) $connection->getConnectionId(),
            'name' => $name,
            'type' => 'sftp',
            'settings' => ['host' => 'sftp.example.com', 'username' => 'shop', 'password' => ''],
        ]);
        $answer = (array) json_decode((string) $this->getResponse()->getBody(), true);
        self::assertTrue($answer['ok'] ?? false, (string) ($answer['message'] ?? ''));
        self::assertSame('Connected to sftp.example.com:22. The login folder has 0 file(s).', $answer['message']);
        self::assertSame('hunter2', FakeRemoteClient::$connected?->password);

        $this->post('backend/openimport/connection/test', ['type' => 'ftp', 'settings' => ['username' => 'x']]);
        $answer = (array) json_decode((string) $this->getResponse()->getBody(), true);
        self::assertFalse($answer['ok']);
        self::assertSame('Enter the server (host name).', $answer['message']);

        $this->post('backend/openimport/connection/save', ['name' => '', 'type' => 'sftp', 'settings' => ['password' => 'x']]);
        $this->assertSessionMessages(self::containsEqual('Give the connection a name.'), MessageInterface::TYPE_ERROR);
        $this->assertRedirect($this->stringContains('openimport/connection/new'));

        $this->post('backend/openimport/connection/delete/id/' . $connection->getConnectionId(), []);
        $this->assertRedirect($this->stringContains('openimport/connection/index'));
        $this->expectException(NoSuchEntityException::class);
        $this->_objectManager->get(ConnectionRepositoryInterface::class)->getById((int) $connection->getConnectionId());
    }
}
