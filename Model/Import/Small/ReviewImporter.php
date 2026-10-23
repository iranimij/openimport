<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Magento\ImportExport\Model\Import;
use Magento\Review\Model\Rating;
use Magento\Review\Model\RatingFactory;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use Psr\Log\LoggerInterface;

/**
 * Product reviews with ratings, by product SKU, nickname and title (or `review_id`): status, store views, text,
 * date, the customer by e-mail, ratings as "Quality:4, Value:5". Writes go through the review and rating models;
 * the product's review summary is aggregated after each review.
 */
class ReviewImporter extends SmallImporter
{
    public const COLUMNS = ['review_id', 'product_sku', 'nickname', 'title', 'detail', 'status', 'store_codes',
        'created_at', 'customer_email', 'ratings'];

    public const STATUSES = ['approved' => Review::STATUS_APPROVED, 'pending' => Review::STATUS_PENDING,
        'not approved' => Review::STATUS_NOT_APPROVED, 'not_approved' => Review::STATUS_NOT_APPROVED,
        'rejected' => Review::STATUS_NOT_APPROVED];

    /**
     * @param LoggerInterface $logger
     * @param SmallRecords $records
     * @param ReviewSource $source
     * @param ReviewFactory $reviewFactory
     * @param RatingFactory $ratingFactory
     * @param ReviewResource $reviewResource
     */
    public function __construct(
        LoggerInterface $logger,
        private readonly SmallRecords $records,
        private readonly ReviewSource $source,
        private readonly ReviewFactory $reviewFactory,
        private readonly RatingFactory $ratingFactory,
        private readonly ReviewResource $reviewResource
    ) {
        parent::__construct($logger);
    }

    /**
     * @inheritDoc
     */
    protected function what(): string
    {
        return 'Reviews';
    }

    /**
     * @inheritDoc
     */
    protected function prepare(array $row, string $behavior): array
    {
        $get = static fn (string $column): string => $row[$column] ?? '';
        $sku = $get('product_sku');
        $productId = $sku === '' ? null : $this->records->productId($sku);
        if ($productId === null) {
            return ['error' => ['product_sku',
                $sku === '' ? 'the product SKU is empty' : sprintf('there is no product "%s"', $sku)]];
        }
        $idText = $get('review_id');
        if ($idText !== '' && !ctype_digit($idText)) {
            return ['error' => ['review_id', sprintf('"%s" is not a review id', $idText)]];
        }
        $existing = null;
        if ($idText !== '') {
            $existing = $this->source->byId((int) $idText);
            if ($existing === null) {
                return ['error' => ['review_id', sprintf('there is no review with id %s', $idText)]];
            }
        } else {
            $existing = $this->source->byKey($productId, $get('nickname'), $get('title'));
        }
        $key = $existing !== null
            ? 'id:' . $existing['review_id']
            : mb_strtolower($sku . '|' . $get('nickname') . '|' . $get('title'));
        if ($behavior === Import::BEHAVIOR_DELETE) {
            if ($existing === null) {
                return ['error' => ['title', 'there is no such review']];
            }

            return ['key' => $key, 'exists' => true, 'id' => (int) $existing['review_id'], 'product_id' => $productId];
        }
        if ($existing === null && ($get('nickname') === '' || $get('title') === '' || $get('detail') === '')) {
            return ['error' => ['title', 'a new review needs a nickname, a title and a text (detail)']];
        }
        $status = null;
        if ($get('status') !== '') {
            $status = self::STATUSES[strtolower($get('status'))]
                ?? (ctype_digit($get('status')) ? (int) $get('status') : null);
            if ($status === null || !in_array($status, self::STATUSES, true)) {
                return ['error' => ['status',
                    sprintf('"%s" is not a status: use approved, pending or not approved', $get('status'))]];
            }
        }
        $stores = null;
        if ($get('store_codes') !== '') {
            $stores = [];
            foreach (array_filter(array_map('trim', explode(',', $get('store_codes')))) as $code) {
                if (strtolower($code) === 'all') {
                    // Every store view; Magento adds store 0 itself. Store 0 alone would show the review nowhere.
                    $stores = $this->allStoreViews();
                    break;
                }
                $id = $this->records->storeId($code);
                if ($id === null) {
                    return ['error' => ['store_codes', sprintf('there is no store view "%s"', $code)]];
                }
                $stores[] = $id;
            }
        }
        if ($get('created_at') !== '' && strtotime($get('created_at')) === false) {
            return ['error' => ['created_at', sprintf('"%s" is not a date', $get('created_at'))]];
        }
        $customerId = null;
        if ($get('customer_email') !== '') {
            $storeId = $stores !== null && $stores !== [] ? $stores[0] : ($this->records->storeId('') ?? 0);
            $customerId = $this->records->customerId($get('customer_email'), $this->records->websiteOf($storeId));
            if ($customerId === null) {
                return ['error' => ['customer_email',
                    sprintf('there is no customer "%s" on the store view\'s website', $get('customer_email'))]];
            }
        }
        $ratings = null;
        $warning = null;
        if ($get('ratings') !== '') {
            $ratings = [];
            $known = $this->source->ratings();
            foreach (array_filter(array_map('trim', preg_split('/[;,]/', $get('ratings')) ?: [])) as $entry) {
                if (preg_match('/^(.+?)\s*[:=]\s*(\d+)$/', $entry, $match) !== 1) {
                    return ['error' => ['ratings', sprintf('"%s" is not a rating: write Quality:4', $entry)]];
                }
                $code = mb_strtolower(trim($match[1]));
                $value = (int) $match[2];
                if (!isset($known[$code])) {
                    return ['error' => ['ratings', sprintf(
                        'there is no rating "%s" (ratings: %s)',
                        trim($match[1]),
                        implode(', ', array_keys($known))
                    )]];
                }
                if (!isset($known[$code]['options'][$value])) {
                    return ['error' => ['ratings', sprintf(
                        'rating %s has no value %d (1 to %d)',
                        trim($match[1]),
                        $value,
                        count($known[$code]['options'])
                    )]];
                }
                $ratings[(int) $known[$code]['id']] = (int) $known[$code]['options'][$value];
                $warning ??= $this->hiddenRating(
                    $known[$code],
                    $stores ?? ($existing !== null ? $this->source->storesOf((int) $existing['review_id'])
                        : $this->allStoreViews())
                );
            }
        }

        return [
            'key' => $key,
            'exists' => $existing !== null,
            'id' => $existing === null ? null : (int) $existing['review_id'],
            'product_id' => $productId,
            'nickname' => $get('nickname'),
            'title' => $get('title'),
            'detail' => $get('detail'),
            'status' => $status,
            'stores' => $stores,
            'created_at' => $get('created_at') !== ''
                ? gmdate('Y-m-d H:i:s', (int) strtotime($get('created_at'))) : null,
            'customer_id' => $customerId,
            'ratings' => $ratings,
        ] + ($warning === null ? [] : ['warning' => ['ratings', $warning]]);
    }

    /**
     * @inheritDoc
     */
    protected function write(array $prepared): void
    {
        /** @var Review $review */
        $review = $this->reviewFactory->create();
        if ($prepared['id'] !== null) {
            $this->reviewResource->load($review, (int) $prepared['id']);
        } else {
            $review->setEntityId((int) $review->getEntityIdByCode(Review::ENTITY_PRODUCT_CODE))
                ->setEntityPkValue($prepared['product_id'])
                ->setStatusId(Review::STATUS_APPROVED)
                ->setData('stores', $this->allStoreViews());
        }
        foreach (['nickname', 'title', 'detail'] as $column) {
            if ($prepared[$column] !== '') {
                $review->setData($column, $prepared[$column]);
            }
        }
        if ($prepared['status'] !== null) {
            $review->setStatusId($prepared['status']);
        }
        if ($prepared['stores'] !== null) {
            $review->setData('stores', $prepared['stores']);
        }
        if ($prepared['id'] !== null && !$review->hasData('stores')) {
            $review->setData('stores', $this->source->storesOf((int) $prepared['id']));
        }

        if ($prepared['customer_id'] !== null) {
            $review->setData('customer_id', $prepared['customer_id']);
        }
        $review->setData('store_id', $review->getData('store_id') ?: ($this->records->storeId('') ?? 0));
        $this->reviewResource->save($review);
        if ($prepared['created_at'] !== null) {
            $this->source->setCreatedAt((int) $review->getId(), $prepared['created_at']);
        }
        if ($prepared['ratings'] !== null) {
            $this->source->deleteVotes((int) $review->getId());
            foreach ($prepared['ratings'] as $ratingId => $optionId) {
                /** @var Rating $rating */
                $rating = $this->ratingFactory->create();
                $rating->setData('rating_id', $ratingId);
                $rating->setData('review_id', (int) $review->getId());
                $rating->addOptionVote($optionId, $prepared['product_id']);
            }
        }
        $review->aggregate();
    }

    /**
     * Why a rating's stars will not show for this review, or null when they will.
     *
     * @param array{code: string, active: bool, stores: int[]} $rating
     * @param int[] $reviewStores
     * @return string|null
     */
    private function hiddenRating(array $rating, array $reviewStores): ?string
    {
        if (!$rating['active']) {
            return sprintf(
                'rating %s is switched off (Stores › Rating › Is Active), so its stars are not shown',
                $rating['code']
            );
        }
        $views = array_values(array_filter($reviewStores, static fn (int $id): bool => $id !== 0));
        if ($views !== [] && array_intersect($views, $rating['stores']) === []) {
            return sprintf(
                'rating %s is not shown in store view %s (Stores › Rating › Rating Visibility), so its stars '
                . 'are not shown; the vote is imported',
                $rating['code'],
                implode(', ', array_map(fn (int $id): string => $this->records->storeCode($id), $views))
            );
        }

        return null;
    }

    /**
     * Every store view of the shop (a new review without store_codes, or "all", is shown everywhere).
     *
     * @return int[]
     */
    private function allStoreViews(): array
    {
        return array_values(array_filter(
            array_values($this->records->storeCodes()),
            static fn (int $id): bool => $id !== 0
        ));
    }

    /**
     * @inheritDoc
     */
    protected function remove(array $prepared): void
    {
        /** @var Review $review */
        $review = $this->reviewFactory->create();
        $this->reviewResource->load($review, (int) $prepared['id']);
        if ($review->getId()) {
            $this->reviewResource->delete($review);
            $review->aggregate();
        }
    }
}
