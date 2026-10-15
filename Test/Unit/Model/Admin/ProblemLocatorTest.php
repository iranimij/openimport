<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Admin;

use Iranimij\OpenImport\Model\Admin\ProblemLocator;
use PHPUnit\Framework\TestCase;

class ProblemLocatorTest extends TestCase
{
    public function testProblemsGoToTheirFields(): void
    {
        $fields = (new ProblemLocator())->locate([
            'Give the profile a name.',
            'The minute "61" is outside 0-59. The hour "25" is outside 0-23.',
            '"ops@" is not an e-mail address.',
            '"files@" is not an e-mail address.',
            'Enter the server (host name).',
            'Enter the server (host name) of the destination.',
            'price: the transform "round" needs a number.',
            'Filter 2: the file has no column "Bestand".',
            'Something general.',
        ], ['export_options' => ['emails' => 'files@, shop@example.com']]);

        self::assertSame([
            'name' => 'Give the profile a name.',
            'schedule_custom' => 'The minute "61" is outside 0-59. The hour "25" is outside 0-23.',
            'notify_emails' => '"ops@" is not an e-mail address.',
            'dest_emails' => '"files@" is not an e-mail address.',
            'source_host' => 'Enter the server (host name).',
            'dest_host' => 'Enter the server (host name) of the destination.',
            'mapping' => 'price: the transform "round" needs a number.',
            'row_filters' => 'Filter 2: the file has no column "Bestand".',
        ], $fields);
    }

    public function testSeveralProblemsOfOneField(): void
    {
        self::assertSame(
            ['mapping' => "sku: required\nprice: not a number"],
            (new ProblemLocator())->locate(['sku: required', 'price: not a number'], [])
        );
    }
}
