<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Execution;

use Iranimij\OpenImport\Model\Execution\ConfirmationWord;
use Iranimij\OpenImport\Model\Pipeline\ImportRunner;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Test\Unit\Model\RunStub;
use PHPUnit\Framework\TestCase;

class ConfirmationWordTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function runs(): array
    {
        return [
            'no confirmation' => [['status' => 'success', 'mode' => 'live', 'message' => 'Created 3.'], 'delete', ''],
            'named in the message' => [['status' => 'needs_confirmation', 'mode' => 'live',
                'message' => 'This run would disable 5 of 6 records (…). Type DISABLE to confirm.'], 'add_update', 'DISABLE'],
            'words with spaces' => [['status' => 'needs_confirmation', 'mode' => 'live',
                'message' => 'This run would set out of stock 9 of 10 records. Type OUT OF STOCK to confirm.'], 'add_update',
                'OUT OF STOCK'],
            'dry run note' => [['status' => 'success', 'mode' => 'dry_run', 'message' => 'Dry run: 1 new. '
                . ImportRunner::CONFIRMATION_NOTE . ': This run would replace 3 of 4 records. Type REPLACE to confirm.'],
                'replace', 'REPLACE'],
            'older run, delete' => [['status' => 'needs_confirmation', 'mode' => 'live', 'message' => 'Confirm it.'],
                'delete', 'DELETE'],
            'older run, replace' => [['status' => 'needs_confirmation', 'mode' => 'live', 'message' => 'Confirm it.'],
                'replace', 'REPLACE'],
            'a dry run without the note' => [['status' => 'success', 'mode' => 'dry_run',
                'message' => 'Type DELETE to confirm.'], 'delete', ''],
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @param string $behavior
     * @param string $word
     * @dataProvider runs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('runs')]
    public function testWord(array $run, string $behavior, string $word): void
    {
        self::assertSame(
            $word,
            (new ConfirmationWord())->forRun(new RunStub($run), new ProfileStub(['behavior' => $behavior]))
        );
    }
}
