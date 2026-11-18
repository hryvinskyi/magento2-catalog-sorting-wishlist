<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingWishlist\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricSourceInterface;
use Hryvinskyi\CatalogSortingWishlist\Api\WishlistConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Intl\DateTimeFactory;

/**
 * Computes the number of wishlist items of every product per store, as one aggregate query over the wishlist items
 *
 * A product's value in a store is the number of wishlist items that hold it and were added in that store, within
 * the configured period. Every item counts once, whoever owns the wishlist and however many units it asks for.
 * Items without a store, and items of products that no longer exist, are left out.
 *
 * The period start is a UTC time, as the times of addition are stored in UTC. Filter values are quoted by the
 * database adapter; the select carries no bind of its own.
 */
class WishlistMetricSource implements MetricSourceInterface
{
    private const WISHLIST_ITEM_TABLE = 'wishlist_item';
    private const PRODUCT_TABLE = 'catalog_product_entity';
    private const SECONDS_PER_DAY = 86400;
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param ResourceConnection $resourceConnection Gives the connection and table names
     * @param WishlistConfigInterface $config Period
     * @param DateTimeFactory $dateTimeFactory Tells the current time
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly WishlistConfigInterface $config,
        private readonly DateTimeFactory $dateTimeFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function select(): Select
    {
        $select = $this->resourceConnection->getConnection()->select();
        $select->from(['wi' => $this->table(self::WISHLIST_ITEM_TABLE)], []);
        $select->join(['p' => $this->table(self::PRODUCT_TABLE)], 'p.entity_id = wi.product_id', []);
        $select->columns([
            'product_id' => 'wi.product_id',
            'store_id' => 'wi.store_id',
            'value' => 'COUNT(*)',
        ]);
        $select->where('wi.store_id > 0');
        $select->group(['wi.product_id', 'wi.store_id']);

        $this->limitToPeriod($select);

        return $select;
    }

    /**
     * Count only items added since the start of the period, when a period is set
     *
     * @param Select $select The metric select
     * @return void
     */
    private function limitToPeriod(Select $select): void
    {
        $days = $this->config->getPeriodDays();
        if ($days === 0) {
            return;
        }

        $now = $this->dateTimeFactory->create()->getTimestamp();
        $select->where('wi.added_at >= ?', gmdate(self::DATE_FORMAT, $now - $days * self::SECONDS_PER_DAY));
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name without prefix
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}
