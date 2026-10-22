<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Entity;

use Iranimij\OpenImport\Model\Import\Attribute\AttributeImporter;
use Iranimij\OpenImport\Model\Import\Category\CategoryImporter;
use Iranimij\OpenImport\Model\Import\Cms\CmsImporter;
use Iranimij\OpenImport\Model\Import\Order\OrderFile;
use Iranimij\OpenImport\Model\Import\PriceRule\PriceRuleImporter;
use Iranimij\OpenImport\Model\Import\Small\ReviewImporter;
use Iranimij\OpenImport\Model\Import\Small\NewsletterImporter;
use Iranimij\OpenImport\Model\Import\Small\SearchTermImporter;
use Iranimij\OpenImport\Model\Import\Small\SynonymImporter;
use Iranimij\OpenImport\Model\Import\Small\CategoryPositionImporter;
use Iranimij\OpenImport\Model\Import\UrlRewrite\UrlRewriteImporter;
use Iranimij\OpenImport\Model\Import\Shipment\ShipmentImporter;

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
    public const ATTRIBUTE = 'openimport_attribute';
    public const CMS_PAGE = 'openimport_cms_page';
    public const CMS_BLOCK = 'openimport_cms_block';
    public const SHIPMENT = 'openimport_shipment';
    public const URL_REWRITE = 'openimport_url_rewrite';
    public const CART_RULE = 'openimport_cart_rule';
    public const CATALOG_RULE = 'openimport_catalog_rule';
    public const REVIEW = 'openimport_review';
    public const NEWSLETTER = 'openimport_newsletter';
    public const SEARCH_TERM = 'openimport_search_term';
    public const SEARCH_SYNONYM = 'openimport_search_synonym';
    public const CATEGORY_POSITION = 'openimport_category_position';

    /**
     * Entities that cannot be imported with "Replace" (their importers refuse it).
     */
    public const NO_REPLACE = [self::CATEGORY, self::ATTRIBUTE, self::CMS_PAGE, self::CMS_BLOCK, self::SHIPMENT,
        self::ORDER, self::URL_REWRITE, self::CART_RULE, self::CATALOG_RULE, self::REVIEW, self::NEWSLETTER,
        self::SEARCH_TERM, self::SEARCH_SYNONYM, self::CATEGORY_POSITION];

    /**
     * Entities that can only be added (nothing to update or delete).
     */
    public const ADD_ONLY = [self::SHIPMENT, self::ORDER];

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
        $cmsSynonyms = [
            'kennung' => 'identifier', 'url key' => 'identifier', 'key' => 'identifier', 'titel' => 'title',
            'inhalt' => 'content', 'html' => 'content', 'body' => 'content', 'aktiv' => 'is_active', 'active' => 'is_active',
            'store views' => 'stores', 'store view' => 'stores', 'shops' => 'stores', 'überschrift' => 'content_heading',
            'heading' => 'content_heading', 'layout' => 'page_layout',
        ];
        $ruleSynonyms = [
            'regel' => 'name', 'rule' => 'name', 'rule name' => 'name', 'titel' => 'name', 'title' => 'name',
            'beschreibung' => 'description', 'aktiv' => 'is_active', 'active' => 'is_active', 'websites' => 'websites',
            'website' => 'websites', 'kundengruppen' => 'customer_groups', 'groups' => 'customer_groups',
            'customer group' => 'customer_groups', 'von' => 'from_date', 'from' => 'from_date', 'start' => 'from_date',
            'bis' => 'to_date', 'to' => 'to_date', 'end' => 'to_date', 'priorität' => 'priority',
            'sort order' => 'priority', 'gutschein' => 'coupon_code', 'coupon' => 'coupon_code', 'code' => 'coupon_code',
            'gutscheine' => 'coupon_codes', 'coupons' => 'coupon_codes', 'codes' => 'coupon_codes', 'aktion' => 'action',
            'type' => 'action', 'typ' => 'action', 'rabatt' => 'discount_amount', 'discount' => 'discount_amount',
            'amount' => 'discount_amount', 'betrag' => 'discount_amount', 'prozent' => 'discount_amount',
            'percent' => 'discount_amount', 'label' => 'labels', 'skus' => 'condition_skus', 'sku' => 'condition_skus',
            'artikel' => 'condition_skus', 'products' => 'condition_skus', 'produkte' => 'condition_skus',
            'kategorien' => 'condition_categories', 'categories' => 'condition_categories',
            'mindestbestellwert' => 'condition_subtotal', 'subtotal' => 'condition_subtotal',
            'minimum' => 'condition_subtotal', 'bedingungen' => 'conditions', 'aktionen' => 'actions',
        ];
        $smallSynonyms = [
            'sku' => 'product_sku', 'artikelnummer' => 'product_sku', 'product' => 'product_sku', 'produkt' => 'product_sku',
            'name' => 'nickname', 'author' => 'nickname', 'autor' => 'nickname', 'titel' => 'title', 'summary' => 'title',
            'text' => 'detail', 'review' => 'detail', 'bewertung' => 'detail', 'comment' => 'detail',
            'e mail' => 'email', 'e-mail' => 'email', 'mail' => 'email', 'store' => 'store_code',
            'store view' => 'store_code', 'storeview' => 'store_code', 'store views' => 'store_codes',
            'datum' => 'created_at', 'date' => 'created_at', 'kunde' => 'customer_email', 'customer' => 'customer_email',
            'sterne' => 'ratings', 'stars' => 'ratings', 'rating' => 'ratings', 'suchbegriff' => 'query_text',
            'term' => 'query_text', 'query' => 'query_text', 'search term' => 'query_text', 'weiterleitung' => 'redirect',
            'beliebtheit' => 'popularity', 'häufigkeit' => 'popularity', 'haeufigkeit' => 'popularity',
            'uses' => 'popularity', 'searches' => 'popularity', 'suchanfragen' => 'popularity',
            'redirect url' => 'redirect', 'ergebnisse' => 'num_results', 'results' => 'num_results',
            'synonyme' => 'synonyms', 'words' => 'synonyms', 'kategorie' => 'category_path', 'category' => 'category_path',
            'position' => 'position', 'sort' => 'position', 'reihenfolge' => 'position',
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
            new EntityInfo(
                self::ATTRIBUTE,
                ['attribute_code'],
                true,
                ['attribute_code'],
                ['code' => 'attribute_code', 'attributcode' => 'attribute_code', 'attribute' => 'attribute_code',
                    'attribut' => 'attribute_code', 'label' => 'label', 'bezeichnung' => 'label', 'typ' => 'frontend_input',
                    'type' => 'frontend_input', 'input' => 'frontend_input', 'optionen' => 'options', 'werte' => 'options',
                    'values' => 'options', 'pflicht' => 'is_required', 'required' => 'is_required',
                    'attributset' => 'attribute_set', 'set' => 'attribute_set', 'gruppe' => 'attribute_group',
                    'group' => 'attribute_group', 'store view' => 'store_view_code'],
                null,
                AttributeImporter::COLUMNS
            ),
            new EntityInfo(self::CMS_PAGE, ['identifier'], false, [], $cmsSynonyms, null, CmsImporter::PAGE_COLUMNS),
            new EntityInfo(self::CMS_BLOCK, ['identifier'], false, [], $cmsSynonyms, null, CmsImporter::BLOCK_COLUMNS),
            new EntityInfo(
                self::ORDER,
                ['increment_id'],
                false,
                [],
                ['bestellnummer' => 'increment_id', 'order' => 'increment_id', 'order number' => 'increment_id',
                    'order id' => 'increment_id', 'e mail' => 'customer_email', 'email' => 'customer_email',
                    'mail' => 'customer_email', 'kunde e mail' => 'customer_email', 'vorname' => 'customer_firstname',
                    'nachname' => 'customer_lastname', 'währung' => 'order_currency_code', 'currency' => 'order_currency_code',
                    'datum' => 'created_at', 'date' => 'created_at', 'bestelldatum' => 'created_at',
                    'gesamt' => 'grand_total', 'total' => 'grand_total', 'gesamtsumme' => 'grand_total',
                    'zwischensumme' => 'subtotal', 'versandkosten' => 'shipping_amount', 'shipping' => 'shipping_amount',
                    'steuer' => 'tax_amount', 'tax' => 'tax_amount', 'rabatt' => 'discount_amount',
                    'discount' => 'discount_amount', 'zahlungsart' => 'payment_method', 'payment' => 'payment_method',
                    'versandart' => 'shipping_method', 'artikelnummer' => 'item_sku', 'sku' => 'item_sku',
                    'artikel' => 'item_name', 'product' => 'item_name', 'menge' => 'item_qty_ordered', 'qty' => 'item_qty_ordered',
                    'quantity' => 'item_qty_ordered', 'preis' => 'item_price', 'price' => 'item_price',
                    'sendungsnummer' => 'tracking_numbers', 'tracking' => 'tracking_numbers'],
                null,
                OrderFile::COLUMNS
            ),
            new EntityInfo(
                self::URL_REWRITE,
                ['request_path'],
                false,
                [],
                ['request path' => 'request_path', 'alte url' => 'request_path', 'old url' => 'request_path',
                    'from' => 'request_path', 'von' => 'request_path', 'url' => 'request_path', 'path' => 'request_path',
                    'target path' => 'target_path', 'neue url' => 'target_path', 'new url' => 'target_path',
                    'to' => 'target_path', 'nach' => 'target_path', 'ziel' => 'target_path', 'target' => 'target_path',
                    'redirect' => 'redirect_type', 'weiterleitung' => 'redirect_type', 'type' => 'redirect_type',
                    'store' => 'store_code', 'store view' => 'store_code', 'sku' => 'product_sku',
                    'artikelnummer' => 'product_sku', 'product' => 'product_sku', 'produkt' => 'product_sku',
                    'category' => 'category_path', 'kategorie' => 'category_path', 'page' => 'cms_page',
                    'seite' => 'cms_page', 'beschreibung' => 'description', 'comment' => 'description'],
                null,
                UrlRewriteImporter::COLUMNS
            ),
            new EntityInfo(self::CART_RULE, ['name'], false, [], $ruleSynonyms, null, PriceRuleImporter::CART_COLUMNS),
            new EntityInfo(
                self::CATALOG_RULE,
                ['name'],
                false,
                [],
                $ruleSynonyms,
                null,
                PriceRuleImporter::CATALOG_COLUMNS
            ),
            new EntityInfo(
                self::REVIEW,
                ['product_sku', 'nickname', 'title'],
                false,
                [],
                $smallSynonyms,
                null,
                ReviewImporter::COLUMNS
            ),
            new EntityInfo(
                self::NEWSLETTER,
                ['email'],
                false,
                [],
                $smallSynonyms,
                null,
                NewsletterImporter::COLUMNS
            ),
            new EntityInfo(
                self::SEARCH_TERM,
                ['query_text'],
                false,
                [],
                $smallSynonyms,
                null,
                SearchTermImporter::COLUMNS
            ),
            new EntityInfo(
                self::SEARCH_SYNONYM,
                ['synonyms'],
                false,
                [],
                $smallSynonyms,
                null,
                SynonymImporter::COLUMNS
            ),
            new EntityInfo(
                self::CATEGORY_POSITION,
                ['category_path', 'product_sku'],
                false,
                [],
                $smallSynonyms,
                null,
                CategoryPositionImporter::COLUMNS
            ),
            new EntityInfo(
                self::SHIPMENT,
                ['increment_id'],
                false,
                [],
                ['bestellnummer' => 'increment_id', 'bestellung' => 'increment_id', 'order' => 'increment_id',
                    'order number' => 'increment_id', 'order id' => 'increment_id', 'auftragsnummer' => 'increment_id',
                    'artikelnummer' => 'item_sku', 'sku' => 'item_sku', 'menge' => 'qty', 'quantity' => 'qty',
                    'versanddienstleister' => 'carrier_code', 'carrier' => 'carrier_code', 'spediteur' => 'carrier_code',
                    'sendungsnummer' => 'tracking_number', 'tracking' => 'tracking_number', 'tracking no' => 'tracking_number',
                    'tracking id' => 'tracking_number', 'trackingnummer' => 'tracking_number', 'paketnummer' => 'tracking_number',
                    'titel' => 'title', 'service' => 'title', 'benachrichtigen' => 'notify', 'e mail' => 'notify',
                    'kommentar' => 'comment', 'bemerkung' => 'comment'],
                null,
                ShipmentImporter::COLUMNS
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
