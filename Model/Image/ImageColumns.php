<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Image;

use Magento\Framework\App\ResourceConnection;

/**
 * The product import columns that hold pictures.
 */
class ImageColumns
{
    /**
     * Columns with a comma-separated list of pictures.
     */
    public const LISTS = ['additional_images', 'hide_from_product_page'];

    /**
     * Core's columns for the standard image roles.
     */
    private const ROLES = ['base_image', 'small_image', 'thumbnail_image', 'swatch_image'];

    /**
     * Image attributes whose column has another name (see ROLES).
     */
    private const ROLE_ATTRIBUTES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    /**
     * @var string[]|null
     */
    private ?array $single = null;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Columns with one picture: the standard roles and every custom image attribute (frontend input media_image).
     *
     * @return string[]
     */
    public function single(): array
    {
        if ($this->single === null) {
            $connection = $this->resource->getConnection();
            $codes = $connection->fetchCol(
                $connection->select()
                    ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_code'])
                    ->join(
                        ['t' => $this->resource->getTableName('eav_entity_type')],
                        't.entity_type_id = a.entity_type_id',
                        []
                    )
                    ->where('t.entity_type_code = ?', 'catalog_product')
                    ->where('a.frontend_input = ?', 'media_image')
            );
            $this->single = array_values(array_unique(array_merge(
                self::ROLES,
                array_diff(array_map('strval', $codes), self::ROLE_ATTRIBUTES)
            )));
        }

        return $this->single;
    }
}
