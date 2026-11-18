<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingWishlist\Model;

use Hryvinskyi\CatalogSortingWishlist\Api\WishlistConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reads the Now in Wishlists period at default scope
 */
class WishlistConfig implements WishlistConfigInterface
{
    /**
     * @param ScopeConfigInterface $scopeConfig Reads the setting
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getPeriodDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PERIOD, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        if (is_string($value)) {
            $value = trim($value);
        }

        return is_numeric($value) ? max(0, (int)$value) : 0;
    }
}
