<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Finds a profile by id or by name for the CLI.
 */
class ProfileFinder
{
    /**
     * @param ProfileRepositoryInterface $profiles
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles
    ) {
    }

    /**
     * @param string $idOrName
     * @return ProfileInterface
     * @throws NoSuchEntityException
     */
    public function find(string $idOrName): ProfileInterface
    {
        if (ctype_digit($idOrName)) {
            try {
                return $this->profiles->getById((int) $idOrName);
            } catch (NoSuchEntityException $exception) {
                // A profile may be named "2024": fall back to the name.
                return $this->profiles->getByName($idOrName);
            }
        }

        return $this->profiles->getByName($idOrName);
    }
}
