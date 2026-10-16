<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Connection;

use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Google\DriveClient;
use Iranimij\OpenImport\Model\Google\GoogleAuth;
use Iranimij\OpenImport\Model\Google\GoogleEndpoints;
use Iranimij\OpenImport\Model\Google\OAuthFlow;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Runs Test/Integration/_files/google-server.php with PHP's built-in web server and points OpenImport's Google
 * endpoints to it.
 */
class GoogleServer
{
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
        $this->stateFile = sys_get_temp_dir() . '/openimport-google-' . bin2hex(random_bytes(5)) . '.json';
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
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../_files/google-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['OPENIMPORT_GOOGLE_STATE' => $this->stateFile]
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
        foreach ([GoogleAuth::class, DriveClient::class, OAuthFlow::class] as $shared) {
            $this->objectManager->removeSharedInstance($shared);
        }
        $this->objectManager->addSharedInstance(
            new GoogleEndpoints($this->base . '/auth', $this->base . '/token', $this->base . '/drive/v3', $this->base . '/upload/drive/v3'),
            GoogleEndpoints::class
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
        foreach ([GoogleEndpoints::class, GoogleAuth::class, DriveClient::class, OAuthFlow::class] as $shared) {
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
        file_put_contents($this->stateFile, json_encode($state + ['files' => [], 'sessions' => [], 'requests' => []]));
    }

    /**
     * A service account key whose public half the server knows.
     *
     * @return string the JSON key file
     */
    public function serviceAccountKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $state = $this->state();
        $state['public_key'] = (string) openssl_pkey_get_details($key)['key'];
        $this->write($state);

        return (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'openimport-test',
            'client_email' => 'importer@openimport-test.iam.gserviceaccount.com',
            'private_key' => $private,
            'token_uri' => $this->base . '/token',
        ]);
    }

    /**
     * @param string $id
     * @param string $name
     * @param string $parent
     * @param string $content
     * @param string $mimeType
     * @return void
     */
    public function addFile(string $id, string $name, string $parent, string $content, string $mimeType = 'text/csv'): void
    {
        $state = $this->state();
        $state['files'][$id] = [
            'name' => $name,
            'mimeType' => $mimeType,
            'parents' => [$parent],
            'modifiedTime' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'content' => $content,
        ];
        $this->write($state);
    }
}
