<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Chain\ChainStarter;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Job chains: a chain of three runs one after the other with parent runs, a failure stops the chain unless
 * "always", a dry run starts nothing, loops and uploads are refused at save, the JSON carries the chain by name.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class ChainTest extends TestCase
{
    private function repository(): ProfileRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class);
    }

    private function exportProfile(string $name): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName($name)
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity(EntityRegistry::CMS_BLOCK)
            ->setFormat('csv')
            ->setExportOptions(['destination' => 'download']);

        return $this->repository()->save($profile);
    }

    /**
     * An import that fails at once: a folder without files.
     */
    private function failingProfile(string $name): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName($name)
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setSourceType('folder')
            ->setSourceConfig(['path' => 'it-chain-empty-' . bin2hex(random_bytes(2)), 'pattern' => '*.csv'])
            ->setMapping([['target' => 'sku', 'source' => 'sku']]);

        return $this->repository()->save($profile);
    }

    private function uploadProfile(string $name): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName($name)
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setMapping([['target' => 'sku', 'source' => 'sku']]);

        return $this->repository()->save($profile);
    }

    private function process(ProfileInterface $profile, string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($profile, $mode, RunInterface::TRIGGER_CLI);

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    private function run(int $runId): RunInterface
    {
        return Bootstrap::getObjectManager()->get(RunRepositoryInterface::class)->getById($runId);
    }

    private function queuedRunOf(string $message): int
    {
        self::assertSame(1, preg_match('/queued as run (\d+)\./', $message, $match), $message);

        return (int) $match[1];
    }

    public function testAChainOfThreeRunsOneAfterTheOther(): void
    {
        $tag = bin2hex(random_bytes(2));
        $a = $this->exportProfile("Chain A $tag");
        $b = $this->exportProfile("Chain B $tag");
        $c = $this->exportProfile("Chain C $tag");
        $b = $this->repository()->save($b->setThenProfileId((int) $c->getProfileId()));
        $a = $this->repository()->save($a->setThenProfileId((int) $b->getProfileId()));

        $first = $this->process($a);
        self::assertSame(RunInterface::STATUS_SUCCESS, $first->getStatus(), $first->getMessage());
        self::assertStringContainsString("Then: \"Chain B $tag\" queued as run", (string) $first->getMessage());
        $secondId = $this->queuedRunOf((string) $first->getMessage());
        $second = $this->run($secondId);
        self::assertSame([RunInterface::STATUS_QUEUED, RunInterface::TRIGGER_CHAIN, RunInterface::MODE_LIVE], [
            $second->getStatus(), $second->getTrigger(), $second->getMode(),
        ]);
        self::assertSame((int) $first->getRunId(), $second->getParentRunId());
        self::assertSame((int) $b->getProfileId(), $second->getProfileId());
        self::assertSame([(int) $a->getProfileId()], $second->getOptions()[ChainStarter::OPTION_CHAIN]);

        $second = Bootstrap::getObjectManager()->get(RunProcessor::class)->process($secondId);
        self::assertSame(RunInterface::STATUS_SUCCESS, $second->getStatus(), $second->getMessage());
        $thirdId = $this->queuedRunOf((string) $second->getMessage());
        $third = $this->run($thirdId);
        self::assertSame([(int) $a->getProfileId(), (int) $b->getProfileId()], $third->getOptions()[ChainStarter::OPTION_CHAIN]);
        $third = Bootstrap::getObjectManager()->get(RunProcessor::class)->process($thirdId);
        self::assertSame(RunInterface::STATUS_SUCCESS, $third->getStatus(), $third->getMessage());
        self::assertStringNotContainsString('Then:', (string) $third->getMessage());

        // A dry run starts nothing.
        $dry = $this->process($a, RunInterface::MODE_DRY_RUN);
        self::assertStringNotContainsString('Then:', (string) $dry->getMessage());

        // The JSON export carries the chain by name and imports it back.
        $json = Bootstrap::getObjectManager()->get(ProfileJson::class);
        $array = $json->toArray($a);
        self::assertSame(["Chain B $tag", ProfileInterface::THEN_SUCCESS], [$array['then_profile'], $array['then_when']]);
        $array['name'] = "Chain A copy $tag";
        $prepared = $json->prepare(json_encode(['openimport' => 1, 'profiles' => [$array]]) ?: '');
        $json->save($prepared);
        self::assertSame((int) $b->getProfileId(), $this->repository()->getByName("Chain A copy $tag")->getThenProfileId());
    }

    public function testAFailedRunStopsTheChainUnlessAlways(): void
    {
        $tag = bin2hex(random_bytes(2));
        $next = $this->exportProfile("Chain next $tag");
        $failing = $this->failingProfile("Chain failing $tag");
        $failing = $this->repository()->save($failing->setThenProfileId((int) $next->getProfileId()));

        $run = $this->process($failing);
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus(), $run->getMessage());
        self::assertStringContainsString("Then: \"Chain next $tag\" is not started (this run did not succeed).", (string) $run->getMessage());

        $failing = $this->repository()->save($failing->setThenWhen(ProfileInterface::THEN_ALWAYS));
        $run = $this->process($failing);
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        $queued = $this->run($this->queuedRunOf((string) $run->getMessage()));
        self::assertSame((int) $next->getProfileId(), $queued->getProfileId());
    }

    public function testLoopsSelfAndUploadsAreRefusedAtSave(): void
    {
        $tag = bin2hex(random_bytes(2));
        $a = $this->exportProfile("Loop A $tag");
        $b = $this->exportProfile("Loop B $tag");
        $this->repository()->save($a->setThenProfileId((int) $b->getProfileId()));
        try {
            $this->repository()->save($b->setThenProfileId((int) $a->getProfileId()));
            self::fail('a loop is refused');
        } catch (CouldNotSaveException $exception) {
            self::assertStringContainsString("The chain would loop: Loop B $tag → Loop A $tag → Loop B $tag.", $exception->getMessage());
        }
        try {
            $this->repository()->save($b->setThenProfileId((int) $b->getProfileId()));
            self::fail('a profile cannot run itself');
        } catch (CouldNotSaveException $exception) {
            self::assertStringContainsString('A profile cannot run itself afterwards.', $exception->getMessage());
        }
        $upload = $this->uploadProfile("Loop upload $tag");
        try {
            $this->repository()->save($b->setThenProfileId((int) $upload->getProfileId()));
            self::fail('an upload profile cannot run in a chain');
        } catch (CouldNotSaveException $exception) {
            self::assertStringContainsString("\"Loop upload $tag\" reads an uploaded file, so it cannot run afterwards", $exception->getMessage());
        }
        try {
            $this->repository()->save($b->setThenProfileId(999999999));
            self::fail('the next profile must exist');
        } catch (CouldNotSaveException $exception) {
            self::assertStringContainsString('There is no profile with id 999999999 to run afterwards.', $exception->getMessage());
        }
    }
}
