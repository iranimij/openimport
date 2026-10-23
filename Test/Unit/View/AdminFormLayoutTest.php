<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Magento places form fields by their sortOrder as a slot in a list: two fields of one step with the same number
 * push each other around (the "Order numbers" select of order imports ended up below "Skip unchanged rows").
 */
class AdminFormLayoutTest extends TestCase
{
    public function testEveryFieldOfAStepHasItsOwnSortOrder(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/view/adminhtml/ui_component/*_form.xml') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            self::assertInstanceOf(\SimpleXMLElement::class, $xml, $file);
            foreach ($xml->xpath('//fieldset') ?: [] as $fieldset) {
                $seen = [];
                foreach ($fieldset->children() as $child) {
                    $order = (string) $child['sortOrder'];
                    if ($order === '') {
                        continue;
                    }
                    $name = (string) $child['name'];
                    self::assertArrayNotHasKey(
                        $order,
                        $seen,
                        sprintf('%s, step %s: %s and %s share sortOrder %s', basename($file), $fieldset['name'], $seen[$order] ?? '', $name, $order)
                    );
                    $seen[$order] = $name;
                }
            }
        }
    }
}
