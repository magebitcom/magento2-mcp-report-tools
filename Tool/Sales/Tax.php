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
use Magento\Tax\Model\ResourceModel\Report\CollectionFactory as TaxReportCollectionFactory;

/**
 * MCP tool `reports.sales.tax` — tax collected per rate / period. Mirrors
 * admin *Reports → Sales → Tax*. Reads `tax_order_aggregated_created`.
 */
class Tax extends AbstractAggregatedReportTool
{
    public const TOOL_NAME = 'reports.sales.tax';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_sales_tax';

    public function __construct(
        SalesReportSearchBuilder $searchBuilder,
        private readonly TaxReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Sales Report: Tax';
    }

    public function getDescription(): string
    {
        return 'Tax collected aggregated per period and tax rate — tax name, '
            . 'percent, amount, base amount, number of orders. Reads '
            . 'pre-aggregated data; if numbers look stale run '
            . '`reports.statistics.refresh_recent` with `report_codes=["tax"]` '
            . 'first.';
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
