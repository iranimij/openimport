<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Notification;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Notification\RunMailer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use PHPUnit\Framework\TestCase;

/**
 * E-mails about finished runs: never, on failure, always; to the profile's addresses or the creating admin.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class RunMailerTest extends TestCase
{
    private function profile(string $notify, string $emails = '', ?string $createdBy = null): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Notify ' . $notify . ' ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setNotify($notify)
            ->setNotifyEmails($emails)
            ->setCreatedBy($createdBy)
            ->setMapping([['target' => 'sku', 'source' => 'sku']]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * A run that fails at once (upload profile without a file).
     */
    private function failedRun(ProfileInterface $profile): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($profile, RunInterface::MODE_LIVE, RunInterface::TRIGGER_CLI);
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());

        return $run;
    }

    private function mock(): TransportBuilderMock
    {
        $builder = Bootstrap::getObjectManager()->get(TransportBuilder::class);
        self::assertInstanceOf(TransportBuilderMock::class, $builder);

        return $builder;
    }

    public function testFailureIsMailedToTheProfilesAddresses(): void
    {
        $mock = $this->mock();
        $mock->clean();
        $profile = $this->profile('failure', 'ops@example.com, boss@example.com');

        $run = $this->failedRun($profile);

        $message = $mock->getSentMessage();
        self::assertNotNull($message, 'an e-mail was sent');
        self::assertStringContainsString($profile->getName(), (string) $message->getSubject());
        self::assertStringContainsString('failed', (string) $message->getSubject());
        $to = array_map(static fn ($address): string => $address->getEmail(), $message->getTo());
        self::assertSame(['ops@example.com', 'boss@example.com'], $to);
        $body = quoted_printable_decode($message->getBodyText());
        self::assertStringContainsString('Upload a file to run this profile.', $body);
        self::assertStringContainsString('openimport/run/view/id/' . $run->getRunId(), $body);
    }

    public function testNeverAndAlways(): void
    {
        $mock = $this->mock();
        $mailer = Bootstrap::getObjectManager()->get(RunMailer::class);

        $mock->clean();
        $this->failedRun($this->profile('never', 'ops@example.com'));
        self::assertNull($mock->getSentMessage());

        $always = $this->profile('always', 'ops@example.com');
        $run = $this->failedRun($always);
        self::assertNotNull($mock->getSentMessage());
        self::assertTrue($mailer->wants($always, $run->setStatus(RunInterface::STATUS_SUCCESS)));
        self::assertFalse($mailer->wants($this->profile('failure'), $run));
        self::assertTrue($mailer->wants($this->profile('failure'), $run->setStatus(RunInterface::STATUS_NEEDS_CONFIRMATION)));
    }

    public function testTheCreatingAdminIsTheDefaultRecipient(): void
    {
        $mailer = Bootstrap::getObjectManager()->get(RunMailer::class);
        $admin = Bootstrap::getObjectManager()->get(\Magento\User\Model\User::class)->loadByUsername('user');
        if (!$admin->getId()) {
            self::markTestSkipped('No admin "user" in this test database.');
        }

        self::assertSame([$admin->getEmail()], $mailer->recipients($this->profile('always', '', 'user')));
        self::assertSame([], $mailer->recipients($this->profile('always', '', 'nobody-' . uniqid())));
        self::assertSame(['a@example.com'], $mailer->recipients($this->profile('always', ' a@example.com ', 'user')));
    }
}
