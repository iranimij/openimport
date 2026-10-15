<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Entity;

use Iranimij\OpenImport\Model\Import\Category\CategoryImporter;

/**
 * The import entities OpenImport supports, with their identifier columns. More can be added through di.xml
 * (argument "entities"); an entity that is not listed is identified by its first mapped column.
 */
class EntityRegistry
{
    public const PRODUCT = 'catalog_product';
    public const ADVANCED_PRICING = 'advanced_pricing';
    public const CUSTOMER = 'customer';
    public const CUSTOMER_ADDRESS = 'customer_address';
    public const CUSTOMER_COMPOSITE = 'customer_composite';
    public const STOCK_SOURCES = 'stock_sources';
    public const STOCK_PRICE = 'openimport_stock_price';
    public const ORDER = 'openimport_order';
    public const CATEGORY = 'openimport_category';

    /**
     * What a Stock & prices profile may change (through core's product import, only existing products).
     */
    public const STOCK_PRICE_COLUMNS = ['sku', 'qty', 'is_in_stock', 'price', 'special_price',
        'special_price_from_date', 'special_price_to_date', 'cost'];

    /**
     * @var array<string, EntityInfo>
     */
    private array $entities = [];

    /**
     * @param EntityInfo[] $entities
     */
    public function __construct(array $entities = [])
    {
        $productSynonyms = [
            'artikelnummer' => 'sku', 'artikelnr' => 'sku', 'art nr' => 'sku', 'artnr' => 'sku', 'sku' => 'sku',
            'bezeichnung' => 'name', 'artikelname' => 'name', 'produktname' => 'name', 'product name' => 'name',
            'preis' => 'price', 'vk preis' => 'price', 'verkaufspreis' => 'price',
            'bestand' => 'qty', 'menge' => 'qty', 'lagerbestand' => 'qty', 'stock' => 'qty', 'quantity' => 'qty',
            'beschreibung' => 'description', 'kurzbeschreibung' => 'short_description', 'gewicht' => 'weight',
            'sonderpreis' => 'special_price', 'hersteller' => 'manufacturer', 'farbe' => 'color',
            'ean' => 'ean', 'gtin' => 'ean', 'kategorie' => 'categories', 'kategorien' => 'categories',
            'bild' => 'base_image', 'image' => 'base_image', 'url key' => 'url_key',
        ];
        $customerSynonyms = [
            'e mail' => 'email', 'mail' => 'email', 'vorname' => 'firstname', 'nachname' => 'lastname',
            'first name' => 'firstname', 'last name' => 'lastname', 'telefon' => 'telephone', 'phone' => 'telephone',
            'strasse' => 'street', 'straße' => 'street', 'plz' => 'postcode', 'zip' => 'postcode', 'ort' => 'city',
            'stadt' => 'city', 'land' => 'country_id', 'country' => 'country_id', 'firma' => 'company',
        ];
        $categorySynonyms = [
            'kategorie' => 'path', 'kategoriepfad' => 'path', 'category' => 'path', 'category path' => 'path',
            'pfad' => 'path', 'name' => 'name', 'bezeichnung' => 'name', 'beschreibung' => 'description',
            'aktiv' => 'is_active', 'active' => 'is_active', 'im menü' => 'include_in_menu', 'in menu' => 'include_in_menu',
            'url key' => 'url_key', 'url' => 'url_key', 'meta titel' => 'meta_title', 'meta title' => 'meta_title',
            'store view' => 'store_view_code', 'storeview' => 'store_view_code',
        ];
        $builtIn = [
            new EntityInfo(
                self::CATEGORY,
                ['path'],
                true,
                ['path'],
                $categorySynonyms,
                null,
                CategoryImporter::COLUMNS
            ),
            new EntityInfo(self::PRODUCT, ['sku'], true, ['sku', 'attribute_set_code', 'product_type'], $productSynonyms),
            new EntityInfo(self::ADVANCED_PRICING, ['sku', 'tier_price_website', 'tier_price_customer_group',
                'tier_price_qty'], false, [], $productSynonyms),
            new EntityInfo(self::CUSTOMER, ['email', '_website'], false, [], $customerSynonyms),
            new EntityInfo(self::CUSTOMER_ADDRESS, ['_email', '_website', '_entity_id'], false, [], $customerSynonyms),
            new EntityInfo(self::CUSTOMER_COMPOSITE, ['email', '_website'], false, [], $customerSynonyms),
            new EntityInfo(self::STOCK_SOURCES, ['sku', 'source_code'], false, [], $productSynonyms),
            new EntityInfo(
                self::STOCK_PRICE,
                ['sku'],
                false,
                [],
                $productSynonyms + ['sonderpreis von' => 'special_price_from_date',
                    'sonderpreis bis' => 'special_price_to_date', 'einkaufspreis' => 'cost', 'ek' => 'cost',
                    'verfügbar' => 'is_in_stock', 'lieferbar' => 'is_in_stock'],
                self::PRODUCT,
                self::STOCK_PRICE_COLUMNS,
                true
            ),
        ];
        foreach (array_merge($builtIn, array_values($entities)) as $entity) {
            $this->entities[$entity->code] = $entity;
        }
    }

    /**
     * @param string $code
     * @return EntityInfo|null
     */
    public function get(string $code): ?EntityInfo
    {
        return $this->entities[$code] ?? null;
    }

    /**
     * The entity, or for an entity OpenImport does not know (a Commerce-only one): identified by its first mapped
     * default field.
     *
     * @param string $code
     * @param \Iranimij\OpenImport\Model\Mapping\FieldMapping[] $fields
     * @return EntityInfo
     */
    public function resolve(string $code, array $fields): EntityInfo
    {
        $known = $this->get($code);
        if ($known !== null) {
            return $known;
        }
        foreach ($fields as $field) {
            if ($field->storeView() === null && $field->target !== '') {
                return new EntityInfo($code, [$field->target]);
            }
        }

        return new EntityInfo($code, []);
    }

    /**
     * @return string[]
     */
    public function codes(): array
    {
        return array_keys($this->entities);
    }
}
