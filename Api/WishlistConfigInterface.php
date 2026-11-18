<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingWishlist\Api;

/**
 * The setting that decides which wishlist items the Now in Wishlists metric counts
 *
 * It is read at default scope only: the metric is computed for every store in one query. Whether the option is
 * offered in a store is not read here; the metric and the listing option read their own Yes/No setting.
 */
interface WishlistConfigInterface
{
    public const XML_PATH_PERIOD = 'catalog_sorting/wished/period';

    /**
     * Get the number of days back from now whose wishlist additions are counted
     *
     * @return int Zero or more; zero counts every item that is in a wishlist now, and so does a blank, negative or
     *             non-numeric setting
     */
    public function getPeriodDays(): int;
}
