<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Mapping;

use Iranimij\OpenImport\Model\Entity\EntityInfo;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Mapping\FieldMapping;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingException;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Model\Transform\ValueMap;
use PHPUnit\Framework\TestCase;

class MappingCompilerTest extends TestCase
{
    private function compiler(): MappingCompiler
    {
        return new MappingCompiler(new TransformPool());
    }

    private function product(): EntityInfo
    {
        return (new EntityRegistry())->get('catalog_product') ?? throw new \LogicException('no product entity');
    }

    public function testSourceStaticAndTransformedValues(): void
    {
        $mapping = $this->compiler()->compile([
            new FieldMapping('sku', 'Artikelnummer', null, ['trim']),
            new FieldMapping('price', 'Preis', null, ['decimal_comma']),
            new FieldMapping('attribute_set_code', null, 'Default'),
            new FieldMapping('product_type', null, 'simple'),
            new FieldMapping('status', 'Aktiv', null, ['map']),
            new FieldMapping('name', null, null, [['name' => 'expr', 'args' => ["{Marke} . ' ' . {Modell}"]]]),
            new FieldMapping('qty', 'Missing'),
        ], $this->product(), ['status' => new ValueMap(['ja' => '1', 'nein' => '2'])]);

        $result = $mapping->map([
            'Artikelnummer' => ' A-1 ',
            'Preis' => '1.234,50',
            'Aktiv' => 'Ja',
            'Marke' => 'ACME',
            'Modell' => 'Rocket',
        ]);

        self::assertTrue($result->isValid());
        self::assertSame('A-1', $result->identifier);
        self::assertSame(
            [
                'sku' => 'A-1',
                'price' => '1234.5',
                'attribute_set_code' => 'Default',
                'product_type' => 'simple',
                'status' => '1',
                'name' => 'ACME Rocket',
                'qty' => '',
            ],
            $result->main()
        );
        self::assertSame(
            ['sku', 'price', 'attribute_set_code', 'product_type', 'status', 'name', 'qty'],
            $mapping->columns()
        );
        self::assertSame(['sku'], $mapping->identifierColumns());
    }

    public function testStoreViewValuesBecomeExtraRows(): void
    {
        $mapping = $this->compiler()->compile([
            new FieldMapping('name', 'Name'),
            new FieldMapping('store:de:name', 'Name DE'),
            new FieldMapping('store:de:description', 'Text DE'),
            new FieldMapping('store:fr:name', 'Name FR'),
            new FieldMapping('sku', 'SKU'),
            new FieldMapping('attribute_set_code', null, 'Default'),
            new FieldMapping('product_type', null, 'simple'),
        ], $this->product());

        self::assertSame(
            ['sku', 'name', 'attribute_set_code', 'product_type', 'store_view_code', 'description'],
            $mapping->columns()
        );
        $result = $mapping->map(['SKU' => 'A', 'Name' => 'Shirt', 'Name DE' => 'Hemd', 'Text DE' => '', 'Name FR' => ' ']);
        self::assertSame(
            [
                ['name' => 'Shirt', 'sku' => 'A', 'attribute_set_code' => 'Default', 'product_type' => 'simple'],
                [
                    'sku' => 'A',
                    'attribute_set_code' => 'Default',
                    'product_type' => 'simple',
                    'store_view_code' => 'de',
                    'name' => 'Hemd',
                    'description' => '',
                ],
            ],
            $result->rows
        );
    }

    public function testRowErrorsNameTheField(): void
    {
        $mapping = $this->compiler()->compile([
            new FieldMapping('sku', 'SKU', null, [], true),
            new FieldMapping('price', 'Price', null, ['number']),
            new FieldMapping('name', 'Name', null, [], true),
        ], $this->product());

        $result = $mapping->map(['SKU' => 'A', 'Price' => 'abc', 'Name' => ' ']);

        self::assertFalse($result->isValid());
        self::assertSame([], $result->rows);
        self::assertSame([], $result->main());
        self::assertSame('A', $result->identifier);
        self::assertSame('price', $result->errors[0]->field);
        self::assertSame('number: "abc" is not a number.', $result->errors[0]->message);
        self::assertSame('"name" is required but empty.', $result->errors[1]->message);
    }

    public function testIdentifierWithSeveralColumns(): void
    {
        $mapping = $this->compiler()->compile(
            [new FieldMapping('email', 'Mail'), new FieldMapping('_website', null, 'base')],
            (new EntityRegistry())->get('customer') ?? throw new \LogicException()
        );

        self::assertSame('a@b.de|base', $mapping->map(['Mail' => 'a@b.de'])->identifier);
        // A partly empty identifier goes to core, which explains what is missing (e-mail required).
        $result = $mapping->map(['Mail' => '']);
        self::assertTrue($result->isValid());
        self::assertSame('|base', $result->identifier);
    }

    public function testFullyEmptyIdentifier(): void
    {
        $mapping = $this->compiler()->compile([new FieldMapping('sku', 'SKU')], $this->product());

        $result = $mapping->map(['SKU' => '  ']);

        self::assertFalse($result->isValid());
        self::assertSame('sku', $result->errors[0]->field);
        self::assertSame('The identifier (sku) is empty.', $result->errors[0]->message);
    }

    public function testProblemsAreListedTogether(): void
    {
        try {
            $this->compiler()->compile([
                new FieldMapping(''),
                new FieldMapping('name', 'Name'),
                new FieldMapping('Name', 'Other'),
                new FieldMapping('price', 'Nope'),
                new FieldMapping('store:de', 'X'),
                new FieldMapping('store:de:sku', 'X'),
                new FieldMapping('store:de:store_view_code', 'X'),
                new FieldMapping('weight', 'Name', null, ['shout']),
            ], $this->product(), [], ['Name', 'Other', 'X']);
            self::fail('Expected a mapping exception');
        } catch (MappingException $exception) {
            self::assertSame(
                "row 1: the Magento field is empty\n"
                . "Name: the field is mapped twice\n"
                . "price: the file has no column \"Nope\"\n"
                . "store:de: write store-view fields as store:<store view code>:<field>\n"
                . "store:de:sku: this field cannot have a store-view value\n"
                . "store:de:store_view_code: this field cannot have a store-view value\n"
                . "weight: Step 1: unknown transform \"shout\". Transforms: " . implode(', ', (new TransformPool())->names()) . ".\n"
                . 'Map the identifier column sku (a source column or a fixed value).',
                $exception->getMessage()
            );
        }
    }

    public function testOwnEntitiesLimitTheFields(): void
    {
        $entity = (new EntityRegistry())->get('openimport_stock_price') ?? throw new \LogicException();
        $mapping = $this->compiler()->compile(
            [new FieldMapping('sku', 'SKU'), new FieldMapping('qty', 'Bestand'), new FieldMapping('special_price', 'SP')],
            $entity
        );

        self::assertSame(['sku', 'qty', 'special_price'], $mapping->columns());
        self::assertSame('catalog_product', $entity->importEntity());
        self::assertTrue($entity->existingOnly);
        self::assertSame('catalog_product', (new EntityRegistry())->get('catalog_product')?->importEntity());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('name: openimport_stock_price can only set sku, qty, is_in_stock, price');
        $this->compiler()->compile([new FieldMapping('sku', 'SKU'), new FieldMapping('name', 'N')], $entity);
    }

    public function testStoreViewsOnlyWhereTheEntityHasThem(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('store-view values are not supported for customer');
        $this->compiler()->compile(
            [new FieldMapping('email', 'Mail'), new FieldMapping('_website', null, 'base'), new FieldMapping('store:de:firstname', 'X')],
            (new EntityRegistry())->get('customer') ?? throw new \LogicException()
        );
    }

    public function testSeveralMissingIdentifierColumns(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Map the identifier columns email, _website');
        $this->compiler()->compile([], (new EntityRegistry())->get('customer') ?? throw new \LogicException());
    }

    public function testFieldFromStoredData(): void
    {
        $compiler = $this->compiler();
        $field = $compiler->field([
            'target' => ' price ',
            'source' => 'Preis',
            'static' => 0,
            'transforms' => '["trim",{"name":"round","args":[2]}]',
            'required' => '1',
        ]);

        self::assertSame('price', $field->target);
        self::assertSame('Preis', $field->source);
        self::assertSame('0', $field->static);
        self::assertSame(['trim', ['name' => 'round', 'args' => [2]]], $field->transforms);
        self::assertTrue($field->required);
        self::assertSame(
            ['target' => 'price', 'source' => 'Preis', 'static' => '0', 'transforms' => $field->transforms, 'required' => true],
            $field->toArray()
        );

        $empty = $compiler->field(['target' => 'x', 'source' => ' ', 'transforms' => '', 'static' => ['no']]);
        self::assertNull($empty->source);
        self::assertNull($empty->static);
        self::assertSame([], $empty->transforms);
        self::assertFalse($empty->required);
        self::assertSame([], $compiler->field(['target' => 'x', 'transforms' => 'not json'])->transforms);
    }

    public function testStoreViewParts(): void
    {
        $field = new FieldMapping('store:de_ch:meta_title');

        self::assertSame('de_ch', $field->storeView());
        self::assertSame('meta_title', $field->column());
        self::assertNull((new FieldMapping('name'))->storeView());
        self::assertSame('', (new FieldMapping('store:x'))->column());
    }
}
