<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingWishlist\Test\Unit\Model\ResourceModel;

use Hryvinskyi\CatalogSortingWishlist\Api\WishlistConfigInterface;
use Hryvinskyi\CatalogSortingWishlist\Model\ResourceModel\WishlistMetricSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\Intl\DateTimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Items without a store are always left out; a period above zero adds one condition that counts only items added
 * since that many days before now, in UTC, and a period of zero adds none. Every value is quoted into its condition,
 * so the select carries no placeholder and no bind for the refresh to carry over.
 *
 * What the whole query computes is checked against real wishlist items by the integration test.
 */
class WishlistMetricSourceTest extends TestCase
{
    /**
     * 2025-11-18 12:00:00 UTC
     */
    private const NOW = 1763467200;

    /**
     * @var WishlistConfigInterface&MockObject
     */
    private $config;

    /**
     * @var DateTimeFactory&MockObject
     */
    private $dateTimeFactory;

    /**
     * @var WishlistMetricSource
     */
    private WishlistMetricSource $source;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $connection = $this->createMock(Mysql::class);
        $renderer = $this->createMock(SelectRenderer::class);
        $connection->method('select')
            ->willReturnCallback(static fn (): Select => new Select($connection, $renderer));
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', self::quoted($value), $text)
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->config = $this->createMock(WishlistConfigInterface::class);
        $this->dateTimeFactory = $this->createMock(DateTimeFactory::class);
        $this->dateTimeFactory->method('create')->willReturnCallback(
            static fn (): \DateTime => new \DateTime('@' . self::NOW)
        );

        $this->source = new WishlistMetricSource($resource, $this->config, $this->dateTimeFactory);
    }

    /**
     * @return void
     */
    public function testAPeriodCountsOnlyItemsAddedSinceItsStartInUtc(): void
    {
        $this->config->method('getPeriodDays')->willReturn(30);

        $conditions = $this->conditions($this->source->select());

        self::assertStringContainsString("wi.added_at >= '2025-10-19 12:00:00'", $conditions);
    }

    /**
     * @return void
     */
    public function testAPeriodOfZeroCountsEveryItemInAWishlistNow(): void
    {
        $this->config->method('getPeriodDays')->willReturn(0);
        $this->dateTimeFactory->expects(self::never())->method('create');

        $conditions = $this->conditions($this->source->select());

        self::assertStringNotContainsString('added_at', $conditions);
    }

    /**
     * @return void
     */
    public function testItemsWithoutAStoreAreLeftOutWhateverThePeriod(): void
    {
        $this->config->method('getPeriodDays')->willReturn(0);

        self::assertStringContainsString('wi.store_id > 0', $this->conditions($this->source->select()));
    }

    /**
     * @return void
     */
    public function testEveryItemCountsOnceAndIsGroupedPerProductAndStore(): void
    {
        $this->config->method('getPeriodDays')->willReturn(0);

        $select = $this->source->select();

        self::assertSame(
            ['product_id' => 'wi.product_id', 'store_id' => 'wi.store_id', 'value' => 'wi.COUNT(*)'],
            $this->columnsOf($select)
        );
        self::assertSame(['wi.product_id', 'wi.store_id'], $select->getPart(Select::GROUP));
    }

    /**
     * The refresh wraps the select in a statement with a named bind of its own, so no positional placeholder may
     * be left in it
     *
     * @return void
     */
    public function testEveryValueIsQuotedIntoItsCondition(): void
    {
        $this->config->method('getPeriodDays')->willReturn(30);

        $select = $this->source->select();

        self::assertStringNotContainsString('?', $this->conditions($select));
        self::assertSame([], $select->getBind());
    }

    /**
     * Get the select's columns as alias => `table.column`
     *
     * @param Select $select The metric select
     * @return array<string, string>
     */
    private function columnsOf(Select $select): array
    {
        $columns = [];
        $parts = $select->getPart(Select::COLUMNS);
        self::assertIsArray($parts);
        foreach ($parts as $part) {
            self::assertIsArray($part);
            [$table, $name, $alias] = $part + [null, null, null];
            if (!is_string($table) || !is_string($alias) || !(is_string($name) || $name instanceof \Stringable)) {
                self::fail('A column of the select has an unexpected shape.');
            }
            $columns[$alias] = $table . '.' . $name;
        }

        return $columns;
    }

    /**
     * Get the select's WHERE conditions as one string
     *
     * @param Select $select The metric select
     * @return string
     */
    private function conditions(Select $select): string
    {
        $where = $select->getPart(Select::WHERE);
        self::assertIsArray($where);

        return implode(' ', array_map(static fn (mixed $part): string => is_string($part) ? $part : '', $where));
    }

    /**
     * Render the value a `?` placeholder is bound to the way the database adapter quotes strings and lists
     *
     * @param mixed $value One value or a list of values
     * @return string
     */
    private static function quoted(mixed $value): string
    {
        $quoted = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            self::assertTrue(is_int($item) || is_string($item));
            $quoted[] = is_int($item) ? (string)$item : "'" . $item . "'";
        }

        return implode(', ', $quoted);
    }
}
