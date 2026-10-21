<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The profiles a profile can run afterwards (job chain): every profile by name; the form hides the profile
 * itself. Profiles that read uploaded files are listed but refused at save with a plain message.
 */
class ThenProfile implements OptionSourceInterface
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param SearchCriteriaBuilder $criteria
     * @param SortOrderBuilder $sortOrders
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly SearchCriteriaBuilder $criteria,
        private readonly SortOrderBuilder $sortOrders
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Nothing')]];
        $criteria = $this->criteria
            ->addSortOrder($this->sortOrders->setField('name')->setDirection('ASC')->create())
            ->create();
        foreach ($this->profiles->getList($criteria)->getItems() as $profile) {
            $options[] = [
                'value' => (string) $profile->getProfileId(),
                'label' => sprintf(
                    '%s (%s)',
                    $profile->getName(),
                    $profile->getDirection() === 'export' ? __('export') : __('import')
                ),
            ];
        }

        return $options;
    }
}
