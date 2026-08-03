<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Cart;

use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\AbstractLiveReportTool;
use Magento\Framework\Data\Collection;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Reports\Model\ResourceModel\Quote\CollectionFactory as AbandonedCartCollectionFactory;

/**
 * MCP tool `reports.cart.abandoned` — carts that started checkout but were
 * never converted. Mirrors admin *Reports → Shopping Cart → Abandoned
 * Carts*.
 */
class Abandoned extends AbstractLiveReportTool
{
    public const TOOL_NAME = 'reports.cart.abandoned';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_cart_abandoned';

    /**
     * Columns mirrored from the admin Abandoned Carts grid
     * ({@see \Magento\Reports\Block\Adminhtml\Shopcart\Abandoned\Grid::_prepareColumns}).
     * Holds the floor against leaking `quote.password_hash` / `customer_dob` /
     * `customer_taxvat` / `customer_gender` / `customer_note` — none of which
     * the admin grid surfaces — through `SELECT main_table.*`. `remote_ip` is
     * likewise omitted: it is personal data no cart report needs.
     */
    private const SELECT_COLUMNS = [
        'entity_id',
        'store_id',
        'customer_id',
        'items_count',
        'items_qty',
        'created_at',
        'updated_at',
        'coupon_code',
    ];

    /**
     * @param RowSerializer $serializer
     * @param AbandonedCartCollectionFactory $collectionFactory
     * @param DateArgReader $dateReader
     */
    public function __construct(
        RowSerializer $serializer,
        private readonly AbandonedCartCollectionFactory $collectionFactory,
        private readonly DateArgReader $dateReader
    ) {
        parent::__construct($serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Cart Report: Abandoned Carts';
    }

    public function getDescription(): string
    {
        return 'Customer carts with items that were never converted to '
            . 'orders. Includes customer email/name, item count, subtotal, '
            . 'created_at, updated_at. Optional "from"/"to" (YYYY-MM-DD, store '
            . 'timezone) narrow the report to carts last touched in that date '
            . 'range. Mirrors admin *Reports → Shopping Cart → Abandoned '
            . 'Carts*.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('from', fn (StringBuilder $s) => $s
                ->description('Only carts abandoned on/after this date (YYYY-MM-DD, store timezone).'))
            ->string('to', fn (StringBuilder $s) => $s
                ->description('Only carts abandoned on/before this date (YYYY-MM-DD, store timezone).'))
            ->array('store_id', fn (ArrayBuilder $a) => $a->ofIntegers()
                ->description('Store view ids to scope to. Omit for all stores.'))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)
                ->maximum(self::MAX_PAGE_SIZE)
                ->description(sprintf('Rows per page (capped at %d).', self::MAX_PAGE_SIZE)))
            ->toArray();
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    protected function buildCollection(array $arguments): Collection
    {
        $collection = $this->collectionFactory->create();
        $storeIds = isset($arguments['store_id']) ? $this->coerceStoreIds($arguments['store_id']) : [];
        $collection->prepareForAbandonedReport($storeIds);
        $collection->getSelect()->reset(Select::COLUMNS);
        $collection->getSelect()->columns(self::SELECT_COLUMNS);
        $collection->addSubtotal($storeIds);
        $collection->addCustomerData();
        $collection->resolveCustomerNames();

        $from = $this->dateReader->optional($arguments, 'from');
        if ($from !== null) {
            $collection->addFieldToFilter(
                'main_table.updated_at',
                ['gteq' => $this->dateReader->utcBoundary($from, false)]
            );
        }
        $to = $this->dateReader->optional($arguments, 'to');
        if ($to !== null) {
            $collection->addFieldToFilter(
                'main_table.updated_at',
                ['lteq' => $this->dateReader->utcBoundary($to, true)]
            );
        }

        return $collection;
    }

    /**
     * @return array<int, int>
     * @throws LocalizedException
     */
    private function coerceStoreIds(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : [$raw];
        $out = [];
        foreach ($list as $v) {
            if (!is_numeric($v) || (int) $v < 0) {
                throw new LocalizedException(__('"store_id" values must be non-negative integers.'));
            }
            $out[] = (int) $v;
        }
        return $out;
    }
}
