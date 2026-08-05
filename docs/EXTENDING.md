# Extending Magebit_McpReportTools

Three extension points, from smallest to largest:

1. **Register a new aggregation code** — plug a payment-module or custom
   report into the shared `reports.statistics.*` surface.
2. **Add a new aggregated report tool** — reuse
   `AbstractAggregatedReportTool` + `SalesReportSearchBuilder`.
3. **Add a new live report tool** — reuse `AbstractLiveReportTool`.

All three require nothing more than a `di.xml` merge and a new tool class.
No base-module changes.

---

## 1. Register a new aggregation code

Example: a PayPal Settlement report backed by
`Vendor\PayPalSettlement\Model\ResourceModel\Report\Settlement` with flag
code `paypal_settlement_aggregated`.

```xml
<!-- app/code/Vendor/McpReportPayPal/etc/di.xml -->
<type name="Magebit\McpReportTools\Model\AggregationRegistry">
    <arguments>
        <argument name="aggregatorFactories" xsi:type="array">
            <item name="paypal_settlement" xsi:type="object">Vendor\PayPalSettlement\Model\ResourceModel\Report\SettlementFactory</item>
        </argument>
        <argument name="flagCodes" xsi:type="array">
            <item name="paypal_settlement" xsi:type="string">paypal_settlement_aggregated</item>
        </argument>
    </arguments>
</type>
```

`aggregatorFactories` items use `xsi:type="object"` and reference the
auto-generated `*Factory` for the resource model — the dispatcher calls
`->create()` on it. `flagCodes` items use `xsi:type="string"` for
custom/third-party flag codes; the in-tree wiring in
`Magebit_McpReportTools/etc/di.xml` instead uses `xsi:type="const"` to
reference `Magento\Reports\Model\Flag::REPORT_*_FLAG_CODE` — both forms work.

After `setup:di:compile` the new code is visible to:

- `reports.statistics.status` — shows the last-refresh timestamp.
- `reports.statistics.refresh_recent` / `refresh_lifetime` — callable with
  `report_codes=["paypal_settlement"]` or `["ALL"]`.

The registered resource class must implement `aggregate($from = null, $to = null)`.

---

## 2. Add a new aggregated report tool

Shared base class
`Magebit\McpReportTools\Tool\AbstractAggregatedReportTool` already provides
the schema shape (`from`, `to`, `period`, `store_id`, `order_statuses`,
`page`, `page_size`), date-range application, iteration, JSON encoding, and
audit summary. You declare the tool's identity and inject its collection
factory.

```php
<?php
declare(strict_types=1);

namespace Vendor\McpReportCustomSales\Tool;

use Magebit\McpReportTools\Model\Search\SalesReportSearchBuilder;
use Magebit\McpReportTools\Tool\AbstractAggregatedReportTool;
use Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection;
use Vendor\CustomSales\Model\ResourceModel\Report\Custom\CollectionFactory as CustomReportCollectionFactory;

class CustomSales extends AbstractAggregatedReportTool
{
    public const TOOL_NAME = 'reports.sales.custom';
    public const ACL_RESOURCE = 'Vendor_McpReportCustomSales::mcp_tool_reports_sales_custom';

    public function __construct(
        SalesReportSearchBuilder $searchBuilder,
        private readonly CustomReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder);
    }

    public function getName(): string { return self::TOOL_NAME; }
    public function getTitle(): string { return 'Custom Sales Report'; }
    public function getDescription(): string { return '...'; }
    public function getAclResource(): string { return self::ACL_RESOURCE; }

    protected function createCollection(): AbstractCollection
    {
        return $this->collectionFactory->create();
    }
}
```

Register:

```xml
<type name="Magebit\Mcp\Model\Tool\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="reports.sales.custom" xsi:type="object">
                Vendor\McpReportCustomSales\Tool\CustomSales
            </item>
        </argument>
    </arguments>
</type>
```

Declare the ACL resource under `Magebit_Mcp::tools` in your `etc/acl.xml`.

Requirements on the collection class: it must extend
`Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection`
(i.e. expose `setPeriod`, `setDateRange`, `addStoreFilter`). If it also
implements `addOrderStatusFilter` it will honour the `order_statuses` arg.

---

## 3. Add a new live report tool

For reports that read live data (not pre-aggregated tables) use
`AbstractLiveReportTool`. You own the collection-prep (filters, joins) and
the schema; the base handles paging, iteration, JSON encoding, and audit
summary.

```php
<?php
declare(strict_types=1);

namespace Vendor\McpReportInventory\Tool;

use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\AbstractLiveReportTool;
use Magento\Framework\Data\Collection;
use Vendor\Inventory\Model\ResourceModel\Health\CollectionFactory;

class InventoryHealth extends AbstractLiveReportTool
{
    public const TOOL_NAME = 'reports.inventory.health';
    public const ACL_RESOURCE = 'Vendor_McpReportInventory::mcp_tool_reports_inventory_health';

    public function __construct(
        RowSerializer $serializer,
        private readonly CollectionFactory $collectionFactory
    ) {
        parent::__construct($serializer);
    }

    public function getName(): string { return self::TOOL_NAME; }
    public function getTitle(): string { return 'Inventory Health Report'; }
    public function getDescription(): string { return '...'; }
    public function getAclResource(): string { return self::ACL_RESOURCE; }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)
                ->maximum(self::MAX_PAGE_SIZE))
            ->toArray();
    }

    protected function buildCollection(array $arguments): Collection
    {
        unset($arguments);
        return $this->collectionFactory->create();
    }
}
```

Register the tool and ACL the same way as above.

### `buildCollection()` must never trigger a load

`AbstractLiveReportTool::execute()` applies `setCurPage()` / `setPageSize()`
(and calls `afterPaging()`) *after* `buildCollection()` returns. Don't call
anything that loads the collection — `getItems()`, `getData()`, `load()`, a
count — inside `buildCollection()`: once a collection is loaded, Magento
collections silently ignore any filter, sort, or limit added afterwards, so
the page and page-size arguments would be dropped without error. Filters and
joins are fine there; loading is not.

### The `afterPaging()` hook

If enrichment needs the collection loaded — e.g. resolving related data per
row that isn't available through a join — override `afterPaging()` instead
of loading in `buildCollection()`. It runs after filters and paging have
been applied to the collection, so the load it triggers only touches the
already-paged rows. The default implementation is a no-op. See
`Tool/Cart/Abandoned.php::afterPaging()` in this module, which uses it to
resolve customer names for the current page only.

If your report is a write operation (triggers recomputation, resets a
cache, etc.), implement `Magebit\Mcp\Api\UnderlyingAclAwareInterface` and
override `getWriteMode()` / `getConfirmationRequired()` — see
`Tool/Statistics/RefreshLifetime.php` in this module for the pattern.
