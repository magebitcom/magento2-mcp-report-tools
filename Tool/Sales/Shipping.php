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
use Magento\Sales\Model\ResourceModel\Report\Shipping\Collection\ShipmentFactory as ShippingReportCollectionFactory;

/**
 * MCP tool `reports.sales.shipping` — shipping totals aggregated per period,
 * carrier, and shipping method. Mirrors admin *Reports → Sales → Shipping*
 * with "Date Used" defaulted to shipment date. Reads
 * `sales_shipping_aggregated`.
 */
class Shipping extends AbstractAggregatedReportTool
{
    public const TOOL_NAME = 'reports.sales.shipping';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_sales_shipping';

    public function __construct(
        SalesReportSearchBuilder $searchBuilder,
        private readonly ShippingReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Sales Report: Shipping';
    }

    public function getDescription(): string
    {
        return 'Shipping revenue aggregated per period, carrier, and method '
            . '— orders count, total shipping, total shipping actual. Date '
            . 'axis is the shipment date. Reads pre-aggregated data; if '
            . 'numbers look stale run `reports.statistics.refresh_recent` '
            . 'with `report_codes=["shipping"]`.';
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
