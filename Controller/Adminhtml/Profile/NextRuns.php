<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Model\Schedule\CronExpression;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * The next three times a schedule runs, in the store time zone (shown under the schedule in the form), or what is
 * wrong with it.
 */
class NextRuns extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles';

    /**
     * @param Context $context
     * @param TimezoneInterface $timezone
     * @param StoredText $text
     */
    public function __construct(
        Context $context,
        private readonly TimezoneInterface $timezone,
        private readonly StoredText $text
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $schedule = trim((string) $this->getRequest()->getParam('schedule'));
        if ($schedule === '') {
            return $result->setData(['times' => []]);
        }
        $zone = $this->timezone->getConfigTimezone() ?: 'UTC';
        try {
            $next = (new CronExpression($schedule))->next(new \DateTimeImmutable('now', new \DateTimeZone($zone)), 3);
        } catch (\InvalidArgumentException $exception) {
            return $result->setData(['times' => [], 'error' => $this->text->translate($exception->getMessage())]);
        }

        return $result->setData([
            'times' => array_map(
                fn (\DateTimeImmutable $time): string => $this->timezone->formatDateTime(
                    $time,
                    \IntlDateFormatter::FULL,
                    \IntlDateFormatter::SHORT,
                    null,
                    $zone
                ),
                $next
            ),
            'zone' => $zone,
        ]);
    }
}
