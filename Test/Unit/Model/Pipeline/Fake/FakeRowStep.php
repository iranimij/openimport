<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\Step\AbstractRowStep;

/**
 * Upper-cases names, fails records whose name is "bad" and leaves records named "half" incomplete.
 */
class FakeRowStep extends AbstractRowStep
{
    /**
     * @var string[] identifiers seen, in order
     */
    public array $seen = [];

    public int $batches = 0;

    public string $began = '';

    /**
     * @param bool $active
     */
    public function __construct(
        private readonly bool $active = true
    ) {
    }

    /**
     * @inheritDoc
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool
    {
        $this->began = $behavior . ($dryRun ? ' dry' : '');

        return $this->active;
    }

    /**
     * @inheritDoc
     */
    public function apply(array $records, \Closure $tick): void
    {
        $this->batches++;
        $tick();
        foreach ($records as $record) {
            $this->seen[] = $record->identifier;
            $name = $record->rows[0]['name'] ?? '';
            if ($name === 'bad') {
                $record->fail('Bad name', 'name');
                continue;
            }
            if ($name === 'half') {
                $record->markIncomplete();
            }
            foreach ($record->rows as $index => $row) {
                $record->rows[$index]['name'] = strtoupper($row['name'] ?? '');
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function summary(): string
    {
        return $this->seen === [] ? '' : 'Fake step: ' . count($this->seen) . ' seen.';
    }
}
