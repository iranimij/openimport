<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Notification;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * E-mails the outcome of a run to the profile's addresses (or the admin who created the profile): never, on
 * failure (failed, partial, needs confirmation), or always. Sending never fails a run; problems go to the log.
 */
class RunMailer
{
    private const FAILURE_STATUSES = [
        RunInterface::STATUS_FAILED,
        RunInterface::STATUS_PARTIAL,
        RunInterface::STATUS_NEEDS_CONFIRMATION,
    ];

    /**
     * @param TransportBuilder $transportBuilder
     * @param UserCollectionFactory $users
     * @param BackendUrl $backendUrl
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly UserCollectionFactory $users,
        private readonly BackendUrl $backendUrl,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether this run's outcome is one the profile wants to hear about.
     *
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @return bool
     */
    public function wants(ProfileInterface $profile, RunInterface $run): bool
    {
        return match ($profile->getNotify()) {
            'always' => true,
            'failure' => in_array($run->getStatus(), self::FAILURE_STATUSES, true),
            default => false,
        };
    }

    /**
     * Addresses to notify: the profile's list, or the e-mail of the admin who created the profile.
     *
     * @param ProfileInterface $profile
     * @return string[]
     */
    public function recipients(ProfileInterface $profile): array
    {
        $emails = array_values(array_filter(array_map('trim', explode(',', $profile->getNotifyEmails()))));
        if ($emails !== []) {
            return $emails;
        }
        $creator = (string) $profile->getCreatedBy();
        if ($creator === '') {
            return [];
        }
        $user = $this->users->create()->addFieldToFilter('username', $creator)->setPageSize(1)->getFirstItem();
        $email = (string) $user->getData('email');

        return $email === '' ? [] : [$email];
    }

    /**
     * Sends the e-mail when the profile wants it. Returns the addresses it went to.
     *
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @return string[]
     */
    public function send(ProfileInterface $profile, RunInterface $run): array
    {
        if (!$this->wants($profile, $run)) {
            return [];
        }
        $recipients = $this->recipients($profile);
        if ($recipients === []) {
            return [];
        }
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('openimport_notify_run_finished')
                // Header and footer templates live in the frontend area (as every core e-mail does).
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $this->storeId()])
                ->setTemplateVars($this->vars($profile, $run))
                ->setFromByScope('general')
                ->addTo($recipients)
                ->getTransport();
            $transport->sendMessage();
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                'OpenImport: the e-mail about run %d could not be sent: %s',
                (int) $run->getRunId(),
                $exception->getMessage()
            ));

            return [];
        }

        return $recipients;
    }

    /**
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @return array<string, string>
     */
    private function vars(ProfileInterface $profile, RunInterface $run): array
    {
        $labels = [
            RunInterface::STATUS_SUCCESS => 'succeeded',
            RunInterface::STATUS_PARTIAL => 'finished with errors',
            RunInterface::STATUS_FAILED => 'failed',
            RunInterface::STATUS_NEEDS_CONFIRMATION => 'needs confirmation',
            RunInterface::STATUS_CANCELLED => 'was cancelled',
        ];
        $rows = '';
        foreach (RunInterface::COUNTS as $count) {
            $value = $run->getCount($count);
            if ($value > 0) {
                $rows .= sprintf(
                    '<tr><td style="padding:2px 12px 2px 0">%s</td><td style="padding:2px 0">%s</td></tr>',
                    $this->escaper->escapeHtml(ucfirst(str_replace('_', ' ', $count))),
                    number_format($value)
                );
            }
        }
        $errors = $run->getCount('errors');

        return [
            'profile_name' => $profile->getName(),
            'status_label' => (string) __($labels[$run->getStatus()] ?? $run->getStatus()),
            'mode_label' => (string) __($run->isDryRun() ? 'dry run' : 'live run'),
            'message' => $run->getMessage(),
            'counts_html' => $rows === '' ? '' : '<table>' . $rows . '</table>',
            'errors_note' => $errors > 0
                ? (string) __('%1 row(s) had errors. The run page has the first ones and the error report.', $errors)
                : '',
            'run_url' => $this->backendUrl->getUrl('openimport/run/view', ['id' => $run->getRunId(), '_nosid' => true]),
        ];
    }
}
