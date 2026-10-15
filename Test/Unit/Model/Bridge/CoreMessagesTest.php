<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Bridge;

use Iranimij\OpenImport\Model\Bridge\CoreMessages;
use Iranimij\OpenImport\Model\Bridge\RowError;
use PHPUnit\Framework\TestCase;

class CoreMessagesTest extends TestCase
{
    public function testPlainMessageWithColumnAndOncePerRow(): void
    {
        $errors = (new CoreMessages())->explain([
            new RowError(0, 'invalidType', 'Product Type is invalid or not supported', null, true),
            new RowError(0, 'invalidType', 'Product Type is invalid or not supported', null, true),
            new RowError(1, 'invalidType', 'Product Type is invalid or not supported', null, true),
            new RowError(1, 'invalidValue', "Value for 'weight' attribute contains incorrect value", 'weight', true),
        ]);

        self::assertCount(3, $errors);
        self::assertSame('product_type', $errors[0]->column);
        self::assertStringContainsString('Add required fields', $errors[0]->message);
        self::assertSame(1, $errors[1]->row);
        self::assertSame("Value for 'weight' attribute contains incorrect value", $errors[2]->message);
    }
}
