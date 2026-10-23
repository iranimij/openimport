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

    public function testAttributeAndPictureMessagesNameTheColumn(): void
    {
        $errors = (new CoreMessages())->explain([
            new RowError(2, 'invalidAttributeType', "Value for 'name' attribute contains incorrect value", null, true),
            new RowError(3, 'invalidMediaUrlPath', 'Wrong URL/path used for attribute image', null, true),
        ]);

        self::assertSame('name', $errors[0]->column);
        self::assertStringStartsWith('Magento does not accept this value for name', $errors[0]->message);
        self::assertSame('image', $errors[1]->column);
        self::assertStringStartsWith('The picture in image is neither a web address', $errors[1]->message);
    }

    public function testFormattedMessagesAsCodesAreExplained(): void
    {
        $name = "Value for 'name' attribute contains incorrect value";
        $picture = 'Wrong URL/path used for attribute small_image';
        $errors = (new CoreMessages())->explain([
            new RowError(3, $name, $name, 'name', false),
            new RowError(4, $picture, $picture, null, false),
        ]);

        self::assertSame('name', $errors[0]->column);
        self::assertStringStartsWith('Magento does not accept this value for name', $errors[0]->message);
        self::assertSame('small_image', $errors[1]->column);
        self::assertStringStartsWith('The picture in small_image is neither', $errors[1]->message);
    }

    public function testDuplicatedUrlKeysAreExplained(): void
    {
        $core = "Url key: 'shirt' was already generated for an item with the SKU: 'A-1'. You need to specify the unique URL key manually";
        $errors = (new CoreMessages())->explain([new RowError(3, $core, $core, null, false)]);

        self::assertSame('url_key', $errors[0]->column);
        self::assertStringStartsWith('Another product of the file or the shop already has this URL key.', $errors[0]->message);
    }
}
