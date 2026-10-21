<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Magento\ImportExport\Model\Import;
use Magento\Search\Api\Data\SynonymGroupInterfaceFactory;
use Magento\Search\Api\SynonymGroupRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Search synonym groups (Marketing › Search Synonyms): a group of words that search alike, for all store views,
 * a website or one store view. A group is matched by its words and scope; words that already sit in another
 * group of the same scope are merged into it, as the admin does.
 */
class SynonymImporter extends SmallImporter
{
    public const COLUMNS = ['synonyms', 'scope'];

    /**
     * @param LoggerInterface $logger
     * @param SynonymSource $source
     * @param SynonymGroupInterfaceFactory $groupFactory
     * @param SynonymGroupRepositoryInterface $repository
     */
    public function __construct(
        LoggerInterface $logger,
        private readonly SynonymSource $source,
        private readonly SynonymGroupInterfaceFactory $groupFactory,
        private readonly SynonymGroupRepositoryInterface $repository
    ) {
        parent::__construct($logger);
    }

    /**
     * @inheritDoc
     */
    protected function what(): string
    {
        return 'Search synonyms';
    }

    /**
     * @inheritDoc
     */
    protected function prepare(array $row, string $behavior): array
    {
        $words = array_values(array_unique(array_filter(array_map(
            static fn (string $word): string => mb_strtolower(trim($word)),
            preg_split('/[,;]/', $row['synonyms'] ?? '') ?: []
        ))));
        if ($words === [] || (count($words) < 2 && $behavior !== Import::BEHAVIOR_DELETE)) {
            return ['error' => ['synonyms', 'a synonym group needs at least two words, separated by commas']];
        }
        $scope = $this->source->scope($row['scope'] ?? '');
        if ($scope === null) {
            return ['error' => ['scope', sprintf(
                '"%s" is not a scope: use all, a website code (website:base) or a store view code (store:de)',
                $row['scope'] ?? ''
            )]];
        }
        $existing = $this->source->group($words, $scope['store_id'], $scope['website_id']);
        $key = $scope['store_id'] . '|' . $scope['website_id'] . '|' . implode(',', $words);
        if ($behavior === Import::BEHAVIOR_DELETE) {
            if ($existing === null) {
                return ['error' => ['synonyms', 'there is no such synonym group in this scope']];
            }

            return ['key' => $key, 'exists' => true, 'id' => (int) $existing['group_id']];
        }

        return [
            'key' => $key,
            'exists' => $existing !== null,
            'id' => $existing === null ? null : (int) $existing['group_id'],
            'words' => $words,
            'store_id' => $scope['store_id'],
            'website_id' => $scope['website_id'],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function write(array $prepared): void
    {
        $group = $this->groupFactory->create();
        $words = $prepared['words'];
        if ($prepared['id'] !== null) {
            // Merge: the words the group has already stay, as the admin form does.
            $group = $this->repository->get($prepared['id']);
            $words = array_values(array_unique(array_merge(
                array_filter(array_map('trim', explode(',', (string) $group->getSynonymGroup()))),
                $words
            )));
        }
        $group->setSynonymGroup(implode(',', $words));
        $group->setStoreId($prepared['store_id']);
        $group->setWebsiteId($prepared['website_id']);
        $this->repository->save($group);
    }

    /**
     * @inheritDoc
     */
    protected function remove(array $prepared): void
    {
        $this->repository->delete($this->repository->get($prepared['id']));
    }
}
