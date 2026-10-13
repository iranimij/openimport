<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Console;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Console\Command\ProfileListCommand;
use Iranimij\OpenImport\Console\Command\RunCommand;
use Iranimij\OpenImport\Console\Command\RunStatusCommand;
use Magento\Framework\Console\CommandListInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The CLI: openimport:run, openimport:run:status and openimport:profile:list.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class CommandsTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function profile(string $name): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName($name)
            ->setEntity('catalog_product')
            ->setFormat('auto')
            ->setSchedule('0 2 * * *')
            ->setMapping([
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'price', 'source' => 'price'],
                ['target' => 'attribute_set_code', 'static' => 'Default'],
                ['target' => 'product_type', 'static' => 'simple'],
                ['target' => 'product_websites', 'static' => 'base'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function csv(string $content): string
    {
        $this->file = sys_get_temp_dir() . '/openimport-cli-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($this->file, $content);

        return $this->file;
    }

    public function testCommandsAreRegistered(): void
    {
        $names = array_keys(Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands());

        self::assertContains('openimport_run', $names);
        self::assertContains('openimport_run_status', $names);
        self::assertContains('openimport_profile_list', $names);
    }

    public function testDryRunThenLiveRunThenStatus(): void
    {
        $prefix = 'OI-CLI-' . bin2hex(random_bytes(3));
        $profile = $this->profile('CLI ' . $prefix);
        $file = $this->csv("sku,name,price\n{$prefix}-1,One,10\n{$prefix}-2,Two,abc\n");
        $run = new CommandTester(Bootstrap::getObjectManager()->create(RunCommand::class));

        $exit = $run->execute(['profile' => $profile->getName(), '--dry-run' => true, '--file' => $file]);
        $output = $run->getDisplay();
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('dry run', $output);
        self::assertStringContainsString('failed', $output);
        self::assertStringContainsString('Errors (all of', $output);
        self::assertMatchesRegularExpression('/\|\s*3\s*\|\s*' . preg_quote($prefix, '/') . '-2/', $output);

        file_put_contents($file, "sku,name,price\n{$prefix}-1,One,10\n{$prefix}-2,Two,12\n");
        $exit = $run->execute(['profile' => (string) $profile->getProfileId(), '--dry-run' => true, '--file' => $file]);
        $output = $run->getDisplay();
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Dry run: 2 new, 0 changed', $output);
        self::assertStringContainsString('After the run', $output);

        $exit = $run->execute(['profile' => $profile->getName(), '--file' => $file]);
        $output = $run->getDisplay();
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('success', $output);
        self::assertStringContainsString('Created 2', $output);
        preg_match('/Run (\d+) ·/', $output, $match);

        $status = new CommandTester(Bootstrap::getObjectManager()->create(RunStatusCommand::class));
        self::assertSame(0, $status->execute(['run' => $match[1]]));
        self::assertStringContainsString('peak memory', $status->getDisplay());
        self::assertSame(1, $status->execute(['run' => '999999999']));

        $list = new CommandTester(Bootstrap::getObjectManager()->create(ProfileListCommand::class));
        self::assertSame(0, $list->execute([]));
        self::assertStringContainsString('CLI ' . $prefix, $list->getDisplay());
        self::assertStringContainsString('0 2 * * *', $list->getDisplay());
        self::assertStringContainsString('live success', $list->getDisplay());
    }

    public function testQueueOnlyAndMistakes(): void
    {
        $profile = $this->profile('CLI queue ' . bin2hex(random_bytes(3)));
        $run = new CommandTester(Bootstrap::getObjectManager()->create(RunCommand::class));

        self::assertSame(0, $run->execute(['profile' => $profile->getName(), '--queue' => true]));
        self::assertMatchesRegularExpression('/Run \d+ queued for/', $run->getDisplay());

        self::assertSame(1, $run->execute(['profile' => 'No such profile']));
        self::assertStringContainsString('There is no profile named', $run->getDisplay());

        self::assertSame(1, $run->execute(['profile' => $profile->getName(), '--file' => '/nope.csv']));
        self::assertStringContainsString('does not exist', $run->getDisplay());

        self::assertSame(1, $run->execute(['profile' => $profile->getName(), '--dry-run' => true]));
        self::assertStringContainsString('Upload a file to run this profile.', $run->getDisplay());
    }
}
