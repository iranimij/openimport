<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Mapping;

use Iranimij\OpenImport\Model\Entity\EntityInfo;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Mapping\AutoMatcher;
use PHPUnit\Framework\TestCase;

class AutoMatcherTest extends TestCase
{
    private const PRODUCT_FIELDS = ['sku', 'name', 'price', 'qty', 'special_price', 'description', 'url_key', 'weight'];

    private function product(): EntityInfo
    {
        return (new EntityRegistry())->get('catalog_product') ?? throw new \LogicException();
    }

    public function testGermanSupplierHeaders(): void
    {
        $matches = (new AutoMatcher())->match(
            ['Artikelnummer', 'Bestand', 'Preis', 'Bezeichnung', 'Lieferant'],
            self::PRODUCT_FIELDS,
            $this->product()
        );

        self::assertSame(['sku' => 'Artikelnummer', 'qty' => 'Bestand', 'price' => 'Preis', 'name' => 'Bezeichnung'], $matches);
    }

    public function testExactBeforeLooseBeforeSynonym(): void
    {
        $matches = (new AutoMatcher())->match(
            ['Special Price', 'URL-Key', 'sku', 'SKU', 'Art.-Nr.', 'quantity', 'Menge'],
            self::PRODUCT_FIELDS,
            $this->product()
        );

        self::assertSame(
            ['sku' => 'sku', 'special_price' => 'Special Price', 'url_key' => 'URL-Key', 'qty' => 'quantity'],
            $matches
        );
    }

    public function testStoreViewColumns(): void
    {
        $matches = (new AutoMatcher())->match(
            ['sku', 'name', 'name_de', 'Beschreibung (de)', 'name [de_ch]', 'sku_de', 'price_xx', 'name-fr'],
            self::PRODUCT_FIELDS,
            $this->product(),
            ['de', 'de_ch', 'fr']
        );

        self::assertSame(
            [
                'sku' => 'sku',
                'name' => 'name',
                'store:de:name' => 'name_de',
                'store:de:description' => 'Beschreibung (de)',
                'store:de_ch:name' => 'name [de_ch]',
                'store:fr:name' => 'name-fr',
            ],
            $matches
        );
    }

    public function testOwnStoreNotation(): void
    {
        $matches = (new AutoMatcher())->match(
            ['attribute_code', 'label', 'store:default:label', 'store:DE:label', 'store:xx:label', 'store:de:'],
            ['attribute_code', 'label', 'options'],
            (new EntityRegistry())->get(EntityRegistry::ATTRIBUTE) ?? throw new \LogicException(),
            ['default', 'de']
        );

        self::assertSame(
            [
                'attribute_code' => 'attribute_code',
                'label' => 'label',
                'store:default:label' => 'store:default:label',
                'store:de:label' => 'store:DE:label',
            ],
            $matches
        );
    }

    public function testNoStoreViewColumnsForEntitiesWithoutThem(): void
    {
        $matches = (new AutoMatcher())->match(
            ['E-Mail', 'Vorname', 'firstname_de'],
            ['email', 'firstname', 'lastname'],
            (new EntityRegistry())->get('customer') ?? throw new \LogicException(),
            ['de']
        );

        self::assertSame(['email' => 'E-Mail', 'firstname' => 'Vorname'], $matches);
    }
}
