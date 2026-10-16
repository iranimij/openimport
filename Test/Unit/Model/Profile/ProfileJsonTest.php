<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Connection\ConnectionTypes;
use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Iranimij\OpenImport\Model\Profile\ProfileValidator;
use Iranimij\OpenImport\Model\Security\Secrets;
use Iranimij\OpenImport\Test\Unit\Model\Connection\ConnectionStub;
use Iranimij\OpenImport\Test\Unit\Model\Connection\FakeConnectionRepository;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Test\Unit\Model\Security\FakeEncryptor;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class ProfileJsonTest extends TestCase
{
    /** @var array<string, ProfileInterface> */
    private array $stored = [];

    /** @var string[] */
    private array $saved = [];

    /**
     * @param array<string, string[]> $problems validator problems by profile name
     * @return ProfileJson
     */
    private function json(array $problems = []): ProfileJson
    {
        $repository = $this->createMock(ProfileRepositoryInterface::class);
        $repository->method('getByName')->willReturnCallback(function (string $name): ProfileInterface {
            if (!isset($this->stored[$name])) {
                throw new NoSuchEntityException(__('no'));
            }

            return $this->stored[$name];
        });
        $repository->method('save')->willReturnCallback(function (ProfileInterface $profile): ProfileInterface {
            $this->saved[] = $profile->getName();

            return $profile;
        });
        $factory = $this->createMock(ProfileInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): ProfileInterface => new ProfileStub());
        $validator = $this->createMock(ProfileValidator::class);
        $validator->method('validate')->willReturnCallback(static fn (ProfileInterface $profile): array => $problems[$profile->getName()] ?? []);

        $connections = new ConnectionConfig(
            new FakeConnectionRepository([new ConnectionStub(7, 'Supplier SFTP', 'sftp', ['host' => 'sftp.example.com'])]),
            new ConnectionTypes(['sftp' => 'SFTP', 'ftp' => 'FTP'])
        );

        return new ProfileJson($repository, $factory, $validator, new Secrets(new FakeEncryptor()), $connections);
    }

    public function testExportLeavesSecretsAndUploadTokensOut(): void
    {
        $profile = new ProfileStub([
            'name' => 'Nightly',
            'entity' => 'catalog_product',
            'source_type' => 'sftp',
            'source_config' => ['host' => 'h', 'file' => 'abc.csv', 'file_name' => 'x.csv', 'secrets' => ['password' => 'enc(x)']],
            'export_options' => ['destination' => 'email', 'secrets' => ['token' => 'enc(t)']],
            'mapping' => [['target' => 'sku', 'source' => 'SKU']],
            'schedule' => '0 2 * * *',
        ]);
        $json = $this->json()->encode([$profile]);
        $data = json_decode($json, true);

        self::assertSame(1, $data['openimport']);
        self::assertSame(['host' => 'h'], $data['profiles'][0]['source_config']);
        self::assertSame(['destination' => 'email'], $data['profiles'][0]['export_options']);
        self::assertStringNotContainsString('enc(', $json);
        self::assertSame('0 2 * * *', $data['profiles'][0]['schedule']);
    }

    public function testSavedConnectionsTravelByName(): void
    {
        $profile = new ProfileStub([
            'name' => 'Supplier stock',
            'entity' => 'catalog_product',
            'source_type' => 'sftp',
            'source_config' => ['connection_id' => '7', 'path' => '/out'],
            'export_options' => ['connection_id' => '99'],
        ]);
        $data = json_decode($this->json()->encode([$profile]), true);
        self::assertSame(['path' => '/out', 'connection' => 'Supplier SFTP'], $data['profiles'][0]['source_config']);
        self::assertSame([], $data['profiles'][0]['export_options'], 'a deleted connection is left out');

        $prepared = $this->json()->prepare((string) json_encode(['profiles' => [
            ['name' => 'Supplier stock', 'entity' => 'catalog_product', 'source_type' => 'sftp',
                'source_config' => ['connection' => 'supplier sftp', 'connection_id' => '1', 'path' => '/out']],
        ]]));
        self::assertSame(['path' => '/out', 'connection_id' => '7'], $prepared[0][0]->getSourceConfig());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Other: there is no saved connection "Gone". Create it under OpenImport Connections first.'
        );
        $this->json()->prepare((string) json_encode(['profiles' => [
            ['name' => 'Other', 'entity' => 'catalog_product', 'export_options' => ['connection' => 'Gone']],
        ]]));
    }

    public function testImportUpdatesByNameKeepsStoredSecretsAndIsAllOrNothing(): void
    {
        $this->stored['Nightly'] = new ProfileStub([
            'name' => 'Nightly', 'entity' => 'catalog_product', 'allowed_errors' => 1,
            'source_config' => ['host' => 'old', 'secrets' => ['password' => 'enc(keep)']],
        ]);
        $json = $this->json();
        $prepared = $json->prepare(json_encode(['openimport' => 1, 'profiles' => [
            ['name' => 'Nightly', 'entity' => 'catalog_product', 'allowed_errors' => 5, 'source_config' => ['host' => 'new', 'secrets' => ['password' => 'smuggled']]],
            ['name' => 'Fresh', 'entity' => 'customer', 'safety_threshold' => '20'],
        ]]));

        self::assertCount(2, $prepared);
        [$nightly, $exists] = $prepared[0];
        self::assertTrue($exists);
        self::assertSame(5, $nightly->getAllowedErrors());
        self::assertSame(['host' => 'new', 'secrets' => ['password' => 'enc(keep)']], $nightly->getSourceConfig());
        self::assertFalse($prepared[1][1]);
        self::assertSame(20, $prepared[1][0]->getSafetyThreshold());
        self::assertSame([1, 1], $json->save($prepared));
        self::assertSame(['Nightly', 'Fresh'], $this->saved);

        $this->saved = [];
        try {
            $this->json(['Bad' => ['Magento has no import entity "x".']])->prepare(json_encode(['profiles' => [
                ['name' => 'Bad', 'entity' => 'x', 'colour' => 'red'],
                ['name' => 'Bad', 'entity' => 'x'],
            ]]));
            self::fail('problems must stop the import');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                "Bad: unknown setting \"colour\".\nBad: Magento has no import entity \"x\".\nBad: appears twice in the file.\nBad: Magento has no import entity \"x\".",
                $exception->getMessage()
            );
        }
        self::assertSame([], $this->saved);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not an OpenImport profile export');
        $json->prepare('[1, 2]');
    }
}
