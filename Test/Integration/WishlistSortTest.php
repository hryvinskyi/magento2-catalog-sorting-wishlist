<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingWishlist\Test\Integration;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;
use Hryvinskyi\CatalogSorting\Api\Search\ShopperSortFieldsInterface;
use Hryvinskyi\CatalogSorting\Model\Index\DocumentFields;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\RecordingSearchDocumentRefresher;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\ReplacesSearchEngineAndPageCache;
use Hryvinskyi\CatalogSortingOptions\Api\ListingSortOptionsInterface;
use Hryvinskyi\CatalogSortingWishlist\Model\ResourceModel\WishlistMetricSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Now in Wishlists on the real wiring and the real database: the refresh stores, per store view, the number of
 * wishlist items that hold each product and were added in that view, within the period, where every item counts once
 * whatever its quantity; items without a store view and items older than the period are left out. The values reach
 * the product search documents, a `wished` sort is rendered on the document field, and the listing option is offered
 * where the store enables it.
 *
 * The refresh commits its own transaction, so the refresh scenarios run outside the test transaction and remove the
 * products, customers with their wishlists, stored values and pending rows they created. Recording doubles stand in
 * for the search engine and the page cache, which the run neither needs nor may write to.
 */
#[AppArea('global')]
class WishlistSortTest extends TestCase
{
    use ReplacesSearchEngineAndPageCache;

    private const CODE = 'wished';
    private const FIELD = 'sort_wished';
    private const DEFAULT_STORE = 'default';
    private const SECOND_STORE = 'hryvinskyi_wished';
    private const METRIC_TABLE = 'hryvinskyi_catalog_sorting_metric';
    private const PENDING_TABLE = 'hryvinskyi_catalog_sorting_metric_pending';
    private const SKU_PREFIX = 'hryvinskyi-wished-test-';
    private const EMAIL_DOMAIN = '@wished.test.invalid';
    private const DAY = 86400;

    /**
     * @var list<int>
     */
    private array $productIds = [];

    /**
     * @var list<int>
     */
    private array $customerIds = [];

    /**
     * Removing the customers removes their wishlists and the items in them; removing the products removes the items
     * that remain
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $connection = $this->connection();
        $connection->delete($this->table(self::METRIC_TABLE), ['metric_code = ?' => self::CODE]);
        if ($this->customerIds !== []) {
            $connection->delete($this->table('customer_entity'), ['entity_id IN (?)' => $this->customerIds]);
        }
        if ($this->productIds !== []) {
            $connection->delete($this->table(self::PENDING_TABLE), ['product_id IN (?)' => $this->productIds]);
            $connection->delete($this->table('catalog_product_entity'), ['entity_id IN (?)' => $this->productIds]);
        }
        $this->customerIds = [];
        $this->productIds = [];
    }

    /**
     * @return void
     */
    #[
        AppIsolation(true),
        DbIsolation(false),
        DataFixture(StoreFixture::class, ['code' => self::SECOND_STORE], 'second_store'),
        Config('catalog_sorting/wished/enabled', '1', 'store', self::DEFAULT_STORE),
        Config('catalog_sorting/wished/enabled', '1', 'store', self::SECOND_STORE),
        Config('catalog_sorting/wished/period', '183')
    ]
    public function testTheRefreshCountsTheItemsAddedWithinThePeriodPerStoreView(): void
    {
        $this->replaceTheSearchEngineAndThePageCache();
        $store = $this->storeId(self::DEFAULT_STORE);
        $secondStore = $this->storeId(self::SECOND_STORE);
        [$product, $wishedOnce, $wishedLongAgo] = $this->placeItems($store, $secondStore);

        $changed = $this->refresh();

        // The first and the second product: one product changed in two stores counts once, and an item added
        // before the period stores no value, so the product wished only long ago never changed
        self::assertSame([self::CODE => 2], $changed);
        self::assertSame([$product => 2.0, $wishedOnce => 1.0], $this->storedValues($store));
        self::assertSame([$product => 1.0], $this->storedValues($secondStore));
        self::assertSame(
            [],
            array_diff([$product, $wishedOnce], $this->refresher()->takeProductIds())
        );
        self::assertSame([], $this->pendingIds());
        self::assertNotContains($wishedLongAgo, array_keys($this->storedValues($store)));

        $fields = $this->service(DocumentFields::class)->getFields([$product, $wishedLongAgo], $store);
        self::assertSame(2.0, $fields[$product][self::FIELD] ?? null);
        self::assertArrayNotHasKey(self::FIELD, $fields[$wishedLongAgo] ?? []);

        self::assertSame([self::CODE => 0], $this->refresh());
        self::assertSame([], $this->refresher()->takeProductIds());
    }

    /**
     * @return void
     */
    #[
        AppIsolation(true),
        DbIsolation(false),
        DataFixture(StoreFixture::class, ['code' => self::SECOND_STORE], 'second_store'),
        Config('catalog_sorting/wished/enabled', '1', 'store', self::DEFAULT_STORE),
        Config('catalog_sorting/wished/enabled', '1', 'store', self::SECOND_STORE),
        Config('catalog_sorting/wished/period', '0')
    ]
    public function testAPeriodOfZeroCountsEveryItemInAWishlistNow(): void
    {
        $this->replaceTheSearchEngineAndThePageCache();
        $store = $this->storeId(self::DEFAULT_STORE);
        $secondStore = $this->storeId(self::SECOND_STORE);
        [$product, $wishedOnce, $wishedLongAgo] = $this->placeItems($store, $secondStore);

        $this->refresh();

        self::assertSame(
            [$product => 3.0, $wishedOnce => 1.0, $wishedLongAgo => 1.0],
            $this->storedValues($store)
        );
        self::assertSame([$product => 1.0], $this->storedValues($secondStore));
    }

    /**
     * Create three products and the wishlist items of three customers over them
     *
     * In the first store view the first product is wished by all three customers, one of them 400 days ago and one
     * asking for five units; the second is wished once, now; the third only 400 days ago. The first product is
     * wished once in the second store view. One more item of the first product has no store view.
     *
     * @param int $store First store view id
     * @param int $secondStore Second store view id
     * @return array{0: int, 1: int, 2: int} Ids of the first, second and third product
     */
    private function placeItems(int $store, int $secondStore): array
    {
        $product = $this->createProduct('wished', 'simple');
        $wishedOnce = $this->createProduct('wished-once', 'simple');
        $wishedLongAgo = $this->createProduct('wished-long-ago', 'simple');
        $now = time();
        $first = $this->createWishlist('first');
        $second = $this->createWishlist('second');
        $third = $this->createWishlist('third');

        $this->addItem($first, $product, $store, $now - self::DAY, 1);
        $this->addItem($second, $product, $store, $now - 2 * self::DAY, 5);
        $this->addItem($third, $product, $store, $now - 400 * self::DAY, 1);
        $this->addItem($first, $product, $secondStore, $now - self::DAY, 1);
        $this->addItem($second, $product, null, $now - self::DAY, 1);
        $this->addItem($first, $wishedOnce, $store, $now - 3 * self::DAY, 1);
        $this->addItem($third, $wishedLongAgo, $store, $now - 400 * self::DAY, 1);

        return [$product, $wishedOnce, $wishedLongAgo];
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    public function testTheMetricIsRegisteredOnTheDocumentField(): void
    {
        $metric = $this->service(MetricRegistryInterface::class)->get(self::CODE);

        self::assertSame(self::FIELD, $metric->getDocumentField());
        self::assertSame(FieldType::Long, $metric->getFieldType());
        self::assertInstanceOf(WishlistMetricSource::class, $metric->getSource());
        self::assertFalse($metric->isEnabledIn($this->storeId(self::DEFAULT_STORE)));
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    public function testAWishedSortIsRenderedOnTheDocumentFieldWithTheShoppersDirection(): void
    {
        $sorts = $this->service(ShopperSortFieldsInterface::class)->apply([[self::CODE => ['order' => 'desc']]]);

        self::assertSame(
            [[self::FIELD => ['order' => 'desc', 'missing' => 0, 'unmapped_type' => 'long']]],
            $sorts
        );
    }

    /**
     * @return void
     */
    #[
        AppIsolation(true),
        Config('catalog_sorting/wished/enabled', '1', 'store', self::DEFAULT_STORE)
    ]
    public function testTheListingOptionIsOfferedDescendingWhereTheStoreEnablesIt(): void
    {
        $options = $this->service(ListingSortOptionsInterface::class);

        $offered = $options->offeredIn($this->storeId(self::DEFAULT_STORE));

        self::assertSame('Now in Wishlists', $offered[self::CODE] ?? null);
        self::assertSame('desc', $options->defaultDirectionOf(self::CODE)?->value);
    }

    /**
     * Run the Now in Wishlists refresh through the real container
     *
     * @return array<string, int>
     */
    private function refresh(): array
    {
        return $this->service(MetricRefreshInterface::class)->refresh([self::CODE]);
    }

    /**
     * Create a bare product row
     *
     * @param string $name Name that makes the SKU unique
     * @param string $type Product type
     * @return int Product id
     */
    private function createProduct(string $name, string $type): int
    {
        $connection = $this->connection();
        $attributeSetId = $connection->fetchOne(
            $connection->select()
                ->from($this->table('eav_entity_type'), ['default_attribute_set_id'])
                ->where('entity_type_code = ?', 'catalog_product')
        );
        $sku = uniqid(self::SKU_PREFIX . $name . '-');
        $connection->insert($this->table('catalog_product_entity'), [
            'attribute_set_id' => is_numeric($attributeSetId) ? (int)$attributeSetId : 4,
            'type_id' => $type,
            'sku' => $sku,
        ]);
        $productId = $this->id('catalog_product_entity', 'entity_id', 'sku = ?', $sku);
        $this->productIds[] = $productId;

        return $productId;
    }

    /**
     * Read the id of a row just inserted
     *
     * @param string $table Table name without prefix
     * @param string $idColumn Id column, or an expression over it
     * @param string $condition Condition that finds the row, with one `?` placeholder
     * @param int|string $value Value the condition is quoted with
     * @return int
     */
    private function id(string $table, string $idColumn, string $condition, int|string $value): int
    {
        $select = $this->connection()->select();
        $select->from($this->table($table), [$idColumn]);
        $select->where($condition, $value);
        $id = $this->connection()->fetchOne($select);
        self::assertIsNumeric($id);

        return (int)$id;
    }

    /**
     * Get the stored Now in Wishlists values of the test products in a store
     *
     * @param int $storeId Store id
     * @return array<int, float> Product id => value, in the order the products were created
     */
    private function storedValues(int $storeId): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(self::METRIC_TABLE), ['product_id', 'value'])
                ->where('metric_code = ?', self::CODE)
                ->where('store_id = ?', $storeId)
                ->where('product_id IN (?)', $this->productIds)
                ->order('product_id ASC')
        );

        $values = [];
        foreach ($rows as $productId => $value) {
            $values[(int)$productId] = is_numeric($value) ? (float)$value : 0.0;
        }

        return $values;
    }

    /**
     * Get the pending product ids among the test products
     *
     * @return list<int>
     */
    private function pendingIds(): array
    {
        $connection = $this->connection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->table(self::PENDING_TABLE), ['product_id'])
                ->where('product_id IN (?)', $this->productIds)
        );

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $ids));
    }

    /**
     * Get the shared recording document refresher the refresh holds
     *
     * @return RecordingSearchDocumentRefresher
     */
    private function refresher(): RecordingSearchDocumentRefresher
    {
        return $this->service(RecordingSearchDocumentRefresher::class);
    }

    /**
     * Get the id of a store view by code
     *
     * @param string $code Store code
     * @return int
     */
    private function storeId(string $code): int
    {
        return (int)$this->service(StoreManagerInterface::class)->getStore($code)->getId();
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->service(ResourceConnection::class)->getTableName($name);
    }

    /**
     * Get the default database connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->service(ResourceConnection::class)->getConnection();
    }

    /**
     * Get a service from the test application's container
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function service(string $type): object
    {
        $service = Bootstrap::getObjectManager()->get($type);
        self::assertInstanceOf($type, $service);

        return $service;
    }

    /**
     * Create a customer with an empty wishlist
     *
     * @param string $name Name that makes the email unique
     * @return int Wishlist id
     */
    private function createWishlist(string $name): int
    {
        $connection = $this->connection();
        $email = uniqid($name . '-') . self::EMAIL_DOMAIN;
        $connection->insert($this->table('customer_entity'), ['email' => $email]);
        $customerId = $this->id('customer_entity', 'entity_id', 'email = ?', $email);
        $this->customerIds[] = $customerId;
        $connection->insert($this->table('wishlist'), ['customer_id' => $customerId]);

        return $this->id('wishlist', 'wishlist_id', 'customer_id = ?', $customerId);
    }

    /**
     * Put a product into a wishlist
     *
     * @param int $wishlistId Wishlist id
     * @param int $productId Product id
     * @param int|null $storeId Store view the item was added in, or null for none
     * @param int $addedAt Unix time the item was added
     * @param int $units Units the item asks for
     * @return void
     */
    private function addItem(int $wishlistId, int $productId, ?int $storeId, int $addedAt, int $units): void
    {
        $this->connection()->insert($this->table('wishlist_item'), [
            'wishlist_id' => $wishlistId,
            'product_id' => $productId,
            'store_id' => $storeId,
            'added_at' => gmdate('Y-m-d H:i:s', $addedAt),
            'qty' => $units,
        ]);
    }
}
