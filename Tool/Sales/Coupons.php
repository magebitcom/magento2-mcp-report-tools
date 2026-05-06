<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Sales;

use Magebit\McpReportTools\Model\Search\SalesReportSearchBuilder;
use Magebit\McpReportTools\Tool\AbstractAggregatedReportTool;
use Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection;
use Magento\SalesRule\Model\ResourceModel\Report\CollectionFactory as CouponsReportCollectionFactory;

/**
 * MCP tool `reports.sales.coupons` — coupon / price-rule usage aggregated
 * per period. Mirrors admin *Reports → Sales → Coupons*. Reads
 * `salesrule_coupon_aggregated`.
 */
class Coupons extends AbstractAggregatedReportTool
{
    public const TOOL_NAME = 'reports.sales.coupons';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_sales_coupons';

    public function __construct(
        SalesReportSearchBuilder $searchBuilder,
        private readonly CouponsReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Sales Report: Coupons';
    }

    public function getDescription(): string
    {
        return 'Coupon / sales-rule usage aggregated per period — coupon '
            . 'code, rule name, uses, subtotal, discount, total, revenue. '
            . 'Reads pre-aggregated data; if numbers look stale run '
            . '`reports.statistics.refresh_recent` with '
            . '`report_codes=["coupons"]`.';
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    protected function createCollection(): AbstractCollection
    {
        return $this->collectionFactory->create();
    }
}
