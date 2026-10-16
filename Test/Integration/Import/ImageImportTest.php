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
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Image\ImageCache;
use Iranimij\OpenImport\Model\Image\ImageFolder;
use Iranimij\OpenImport\Model\Maintenance\Housekeeping;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Product pictures from web addresses against a real store and a real web server (PHP's built-in server with
 * Test/Integration/_files/image-server.php): download, ETag cache, refusals, file names, housekeeping.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class ImageImportTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $server = null;

    private ?string $base = null;

    private ?string $log = null;

    /**
     * @var string[]|null
     */
    private ?array $files = [];

    protected function setUp(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->log = sys_get_temp_dir() . '/openimport-image-server-' . $port . '.log';
        file_put_contents($this->log, '');
        // phpcs:ignore Magento2.Security.InsecureFunction -- starts the test web server, fixed command, no user input
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../_files/image-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['OPENIMPORT_IMAGE_LOG' => $this->log]
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
        $this->config(Config::XML_ALLOWED_HOSTS, '127.0.0.1');
        $this->config(Config::XML_IMAGE_REFRESH_HOURS, '0');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            // phpcs:ignore Magento2.Security.InsecureFunction -- stops the test web server
            proc_terminate($this->server);
            // phpcs:ignore Magento2.Security.InsecureFunction -- stops the test web server
            proc_close($this->server);
        }
        foreach (array_merge($this->files ?? [], [$this->log]) as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
    }

    private function config(string $path, string $value): void
    {
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)->setValue($path, $value);
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return string
     */
    private function csv(array $rows): string
    {
        $file = sys_get_temp_dir() . '/openimport-img-' . bin2hex(random_bytes(5)) . '.csv';
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
     * @param array<string, string> $importOptions
     * @param string $onError
     * @return ProfileInterface
     */
    private function profile(array $importOptions = [], string $onError = 'stop'): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create();
        $profile->setName('Pictures ' . bin2hex(random_bytes(4)))
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setBehavior('add_update')
            ->setOnError($onError)
            ->setAllowedErrors($onError === 'skip' ? 10 : 0)
            ->setImportOptions($importOptions)
            ->setMapping([
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'price', 'source' => null, 'static' => '9.5'],
                ['target' => 'base_image', 'source' => 'image'],
                ['target' => 'small_image', 'source' => 'image'],
                ['target' => 'additional_images', 'source' => 'more'],
                ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
                ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
                ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
                ['target' => 'visibility', 'source' => null, 'static' => 'Catalog, Search'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param ProfileInterface $profile
     * @param string $file
     * @param array<string, mixed> $options
     * @return RunInterface
     */
    private function run(ProfileInterface $profile, string $file, array $options = []): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'pictures.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    /**
     * @return string[] "<path> <status>" lines the server logged
     */
    private function requests(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents((string) $this->log))));
    }

    /**
     * @param string $sku
     * @return array{0: string, 1: string[]} base image and gallery files
     */
    private function pictures(string $sku): array
    {
        $repository = Bootstrap::getObjectManager()->create(ProductRepositoryInterface::class);
        $product = $repository->get($sku, false, 0, true);
        $files = array_map(
            static fn ($entry): string => (string) $entry->getFile(),
            $product->getMediaGalleryEntries() ?? []
        );

        return [(string) $product->getData('image'), $files];
    }

    public function testPicturesFromWebAddressesAreImportedAndOnlyDownloadedOnce(): void
    {
        $sku = 'OI-IMG-' . bin2hex(random_bytes(3));
        $color = 'c' . bin2hex(random_bytes(3));
        $file = $this->csv([[
            'sku' => $sku,
            'name' => 'Picture product ' . $sku,
            'image' => $this->base . '/pic/' . $color . '.png',
            'more' => $this->base . '/pic/' . $color . '-back.png,' . $this->base . '/moved.png',
        ]]);
        $profile = $this->profile();

        $first = $this->run($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $first->getStatus(), $first->getMessage());
        self::assertStringContainsString('Pictures: 3 downloaded, 0 unchanged, 0 could not be read.', $first->getMessage());
        [$image, $gallery] = $this->pictures($sku);
        self::assertMatchesRegularExpression('#/' . $color . '(_\d+)?\.png$#', $image, 'the catalog file keeps the name');
        self::assertCount(3, $gallery);
        $cached = Bootstrap::getObjectManager()->get(ImageCache::class)->find($this->base . '/pic/' . $color . '.png');
        self::assertNotNull($cached);
        self::assertTrue(Bootstrap::getObjectManager()->get(ImageFolder::class)->exists($cached['file']));
        self::assertNotSame('', $cached['etag']);
        // base_image and small_image share one address: one request.
        self::assertSame(1, count(array_filter($this->requests(), static fn (string $line): bool
            => str_starts_with($line, '/pic/' . $color . '.png '))));

        // Refresh after 0 hours: the next run asks with the ETag and gets 304.
        $second = $this->run($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $second->getStatus(), $second->getMessage());
        self::assertStringContainsString('Pictures: 0 downloaded, 3 unchanged', $second->getMessage());
        self::assertContains('/pic/' . $color . '.png 304', $this->requests());
        self::assertCount(3, $this->pictures($sku)[1], 'the same pictures are not added again');

        // Within the refresh time no request is made at all.
        $this->config(Config::XML_IMAGE_REFRESH_HOURS, '24');
        $before = count($this->requests());
        $third = $this->run($profile, $file);
        self::assertStringContainsString('Pictures: 0 downloaded, 3 unchanged', $third->getMessage());
        self::assertCount($before, $this->requests());
    }

    public function testPicturesThatCannotBeReadFailTheRowOrAreLeftOut(): void
    {
        $prefix = 'OI-IMGX-' . bin2hex(random_bytes(3));
        $rows = [
            ['sku' => $prefix . '-1', 'name' => 'Missing ' . $prefix, 'image' => $this->base . '/missing.png', 'more' => ''],
            ['sku' => $prefix . '-2', 'name' => 'Page ' . $prefix, 'image' => $this->base . '/page.png', 'more' => ''],
            ['sku' => $prefix . '-3', 'name' => 'Webp ' . $prefix, 'image' => $this->base . '/photo.webp', 'more' => ''],
            ['sku' => $prefix . '-4', 'name' => 'Fine ' . $prefix, 'image' => $this->base . '/pic/fine-' . strtolower($prefix) . '.png', 'more' => ''],
        ];

        $failed = $this->run($this->profile([], 'skip'), $this->csv($rows));
        self::assertSame(RunInterface::STATUS_PARTIAL, $failed->getStatus(), $failed->getMessage());
        self::assertSame(3, $failed->getCount('errors'));
        $errors = [];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $failed->getRunId()) as $error) {
            $errors[$error->identifier] = $error;
        }
        self::assertSame('base_image', $errors[$prefix . '-1']->field);
        self::assertStringContainsString('answered with HTTP 404', $errors[$prefix . '-1']->message);
        self::assertStringContainsString('a web page, not a picture', $errors[$prefix . '-2']->message);
        self::assertStringContainsString('The picture is WEBP', $errors[$prefix . '-3']->message);
        self::assertNotSame('', $this->pictures($prefix . '-4')[0]);

        $skipped = $this->run(
            $this->profile(['images_on_error' => 'skip']),
            $this->csv(array_slice($rows, 0, 1))
        );
        self::assertSame(RunInterface::STATUS_SUCCESS, $skipped->getStatus(), $skipped->getMessage());
        self::assertStringContainsString('Imported without the picture: ' . $prefix . '-1', $skipped->getMessage());
        self::assertSame(1, $skipped->getCount('created'));
    }

    public function testPrivateAddressesAreRefusedUnlessAllowed(): void
    {
        $this->config(Config::XML_ALLOWED_HOSTS, '');
        $sku = 'OI-IMGP-' . bin2hex(random_bytes(3));
        $run = $this->run($this->profile(), $this->csv([[
            'sku' => $sku, 'name' => 'Private ' . $sku, 'image' => $this->base . '/pic/private.png', 'more' => '',
        ]]));

        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        $errors = Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId());
        self::assertCount(1, $errors);
        self::assertStringContainsString('private or local address', $errors[0]->message);
        self::assertSame([], $this->requests(), 'nothing was asked');
    }

    public function testFileNamesAreReadFromTheImportImagesFolder(): void
    {
        $folder = Bootstrap::getObjectManager()->get(ImageFolder::class);
        $name = 'oi-it-' . bin2hex(random_bytes(3));
        $image = imagecreatetruecolor(6, 6);
        $path = $folder->absoluteBase() . '/' . $name . '.png';
        imagepng($image, $path);
        $this->files[] = $path;
        $sku = 'OI-IMGF-' . bin2hex(random_bytes(3));

        $run = $this->run($this->profile(), $this->csv([[
            'sku' => $sku, 'name' => 'Local ' . $sku, 'image' => $name . '.png', 'more' => '',
        ]]));

        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertStringNotContainsString('Pictures:', $run->getMessage(), 'no web addresses, no picture line');
        self::assertMatchesRegularExpression('#/' . $name . '(_\d+)?\.png$#', $this->pictures($sku)[0]);
    }

    public function testHousekeepingRemovesPicturesNoRunUsed(): void
    {
        $sku = 'OI-IMGH-' . bin2hex(random_bytes(3));
        $url = $this->base . '/pic/h' . bin2hex(random_bytes(3)) . '.png';
        $run = $this->run($this->profile(), $this->csv([['sku' => $sku, 'name' => 'Old ' . $sku, 'image' => $url, 'more' => '']]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $cache = Bootstrap::getObjectManager()->get(ImageCache::class);
        $file = $cache->find($url)['file'] ?? '';
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $connection->update(
            $connection->getTableName(ImageCache::TABLE),
            ['used_at' => '2020-01-01 00:00:00'],
            ['url_hash = ?' => sha1($url)]
        );

        $result = Bootstrap::getObjectManager()->get(Housekeeping::class)->run();

        self::assertGreaterThanOrEqual(1, $result['pictures']);
        self::assertNull($cache->find($url));
        self::assertFalse(Bootstrap::getObjectManager()->get(ImageFolder::class)->exists($file));
        self::assertNotSame('', $this->pictures($sku)[0], 'the catalog copy stays');
    }
}
