<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Connection;

use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Dropbox\DropboxClient;
use Iranimij\OpenImport\Model\Dropbox\DropboxEndpoints;
use Iranimij\OpenImport\Model\Dropbox\DropboxOAuthProvider;
use Iranimij\OpenImport\Model\Microsoft\MicrosoftEndpoints;
use Iranimij\OpenImport\Model\Microsoft\MicrosoftOAuthProvider;
use Iranimij\OpenImport\Model\Microsoft\OneDriveClient;
use Iranimij\OpenImport\Model\OAuth\OAuthFlow;
use Iranimij\OpenImport\Model\OAuth\OAuthTokens;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Runs Test/Integration/_files/cloud-server.php (Dropbox and Microsoft Graph stand-in) with PHP's built-in web server
 * and points OpenImport's Dropbox and Microsoft endpoints to it.
 */
class CloudServer
{
    private const SHARED = [
        DropboxEndpoints::class, MicrosoftEndpoints::class, OAuthTokens::class, OAuthFlow::class,
        DropboxOAuthProvider::class, MicrosoftOAuthProvider::class, DropboxClient::class, OneDriveClient::class,
    ];

    /**
     * @var resource|null
     */
    private $process = null;

    private string $stateFile;

    public string $base = '';

    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
        $this->stateFile = sys_get_temp_dir() . '/openimport-cloud-' . bin2hex(random_bytes(5)) . '.json';
    }

    /**
     * @param array<string, mixed> $state
     * @return void
     */
    public function start(array $state = []): void
    {
        $this->write($state);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        // phpcs:ignore Magento2.Security.InsecureFunction -- starts the test web server, fixed command, no user input
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../_files/cloud-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['OPENIMPORT_CLOUD_STATE' => $this->stateFile]
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
        $this->objectManager->get(MutableScopeConfigInterface::class)->setValue(Config::XML_ALLOWED_HOSTS, '127.0.0.1');
        foreach (self::SHARED as $shared) {
            $this->objectManager->removeSharedInstance($shared);
        }
        $this->objectManager->addSharedInstance(new DropboxEndpoints(
            $this->base . '/dropbox/oauth2/authorize',
            $this->base . '/dropbox/oauth2/token',
            $this->base . '/dropbox/2',
            $this->base . '/dropbox/content/2'
        ), DropboxEndpoints::class);
        $this->objectManager->addSharedInstance(
            new MicrosoftEndpoints($this->base . '/ms/login', $this->base . '/ms/graph'),
            MicrosoftEndpoints::class
        );
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            // phpcs:ignore Magento2.Security.InsecureFunction -- stops the test web server
            proc_terminate($this->process);
            // phpcs:ignore Magento2.Security.InsecureFunction -- stops the test web server
            proc_close($this->process);
        }
        foreach (self::SHARED as $shared) {
            $this->objectManager->removeSharedInstance($shared);
        }
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return json_decode((string) file_get_contents($this->stateFile), true) ?: [];
    }

    /**
     * @param array<string, mixed> $state
     * @return void
     */
    public function write(array $state): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($state + ['files' => [], 'folders' => [], 'uploads' => [], 'requests' => []])
        );
    }
}
