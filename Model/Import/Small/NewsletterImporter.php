<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Magento\ImportExport\Model\Import;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Psr\Log\LoggerInterface;

/**
 * Newsletter subscribers by e-mail and store view: status (subscribed, unsubscribed, not activated,
 * unconfirmed), the customer with that e-mail linked. Written through the subscriber model without sending any
 * e-mail (no confirmation, no welcome).
 */
class NewsletterImporter extends SmallImporter
{
    public const COLUMNS = ['email', 'store_code', 'status'];

    public const STATUSES = ['subscribed' => Subscriber::STATUS_SUBSCRIBED,
        'not activated' => Subscriber::STATUS_NOT_ACTIVE, 'not_activated' => Subscriber::STATUS_NOT_ACTIVE,
        'unsubscribed' => Subscriber::STATUS_UNSUBSCRIBED, 'unconfirmed' => Subscriber::STATUS_UNCONFIRMED];

    /**
     * @param LoggerInterface $logger
     * @param SmallRecords $records
     * @param NewsletterSource $source
     * @param SubscriberFactory $subscriberFactory
     * @param SubscriberResource $subscriberResource
     */
    public function __construct(
        LoggerInterface $logger,
        private readonly SmallRecords $records,
        private readonly NewsletterSource $source,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly SubscriberResource $subscriberResource
    ) {
        parent::__construct($logger);
    }

    /**
     * @inheritDoc
     */
    protected function what(): string
    {
        return 'Newsletter subscribers';
    }

    /**
     * @inheritDoc
     */
    protected function prepare(array $row, string $behavior): array
    {
        $email = mb_strtolower($row['email'] ?? '');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => ['email', sprintf('"%s" is not an e-mail address', $email)]];
        }
        $storeId = $this->records->storeId($row['store_code'] ?? '');
        if ($storeId === null) {
            return ['error' => ['store_code', sprintf('there is no store view "%s"', $row['store_code'] ?? '')]];
        }
        $websiteId = $this->records->websiteOf($storeId);
        $existing = $this->source->subscriber($email, $websiteId);
        $key = $websiteId . '|' . $email;
        if ($behavior === Import::BEHAVIOR_DELETE) {
            if ($existing === null) {
                return ['error' => ['email', 'is not a subscriber on this website']];
            }

            return ['key' => $key, 'exists' => true, 'id' => (int) $existing['subscriber_id']];
        }
        $status = Subscriber::STATUS_SUBSCRIBED;
        $text = strtolower($row['status'] ?? '');
        if ($text !== '') {
            $status = self::STATUSES[$text] ?? (ctype_digit($text) ? (int) $text : null);
            if ($status === null || !in_array($status, self::STATUSES, true)) {
                return ['error' => ['status', sprintf(
                    '"%s" is not a status: use subscribed, unsubscribed, not activated or unconfirmed',
                    $row['status'] ?? ''
                )]];
            }
        } elseif ($existing !== null) {
            $status = (int) $existing['subscriber_status'];
        }

        return [
            'key' => $key,
            'exists' => $existing !== null,
            'id' => $existing === null ? null : (int) $existing['subscriber_id'],
            'email' => $email,
            'store_id' => $storeId,
            'status' => $status,
            'customer_id' => $this->records->customerId($email, $websiteId),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function write(array $prepared): void
    {
        /** @var Subscriber $subscriber */
        $subscriber = $this->subscriberFactory->create();
        if ($prepared['id'] !== null) {
            $this->subscriberResource->load($subscriber, (int) $prepared['id']);
        } else {
            $subscriber->setSubscriberEmail($prepared['email'])
                ->setSubscriberConfirmCode(bin2hex(random_bytes(16)));
        }
        $subscriber->setStoreId($prepared['store_id'])
            ->setCustomerId($prepared['customer_id'] ?? 0)
            ->setStatus($prepared['status']);
        if ($prepared['status'] === Subscriber::STATUS_SUBSCRIBED && !$subscriber->getChangeStatusAt()) {
            $subscriber->setChangeStatusAt(gmdate('Y-m-d H:i:s'));
        }
        $this->subscriberResource->save($subscriber);
    }

    /**
     * @inheritDoc
     */
    protected function remove(array $prepared): void
    {
        /** @var Subscriber $subscriber */
        $subscriber = $this->subscriberFactory->create();
        $this->subscriberResource->load($subscriber, (int) $prepared['id']);
        if ($subscriber->getId()) {
            $this->subscriberResource->delete($subscriber);
        }
    }
}
