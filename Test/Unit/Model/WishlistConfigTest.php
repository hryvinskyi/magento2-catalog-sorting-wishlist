<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingWishlist\Test\Unit\Model;

use Hryvinskyi\CatalogSortingWishlist\Model\WishlistConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The period is read at default scope. It is a whole number of days, zero or more: anything that is not a number, or
 * is below zero, counts wishlist items of any age.
 */
class WishlistConfigTest extends TestCase
{
    /**
     * @param mixed $stored The stored setting
     * @param int $expected Days the setting is read as
     * @return void
     */
    #[DataProvider('periods')]
    public function testReadsThePeriodAtDefaultScope(mixed $stored, int $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with('catalog_sorting/wished/period', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->willReturn($stored);

        self::assertSame($expected, (new WishlistConfig($scopeConfig))->getPeriodDays());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function periods(): array
    {
        return [
            'days' => ['183', 183],
            'surrounded by spaces' => [' 30 ', 30],
            'an integer' => [90, 90],
            'zero' => ['0', 0],
            'negative' => ['-5', 0],
            'blank' => ['', 0],
            'not a number' => ['half a year', 0],
            'not set' => [null, 0],
        ];
    }
}
