<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Products;

use Magebit\McpReportTools\Model\Search\SalesReportSearchBuilder;
use Magebit\McpReportTools\Tool\AbstractAggregatedReportTool;
use Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection;
use Magento\Sales\Model\ResourceModel\Report\Bestsellers\CollectionFactory as BestsellersReportCollectionFactory;

/**
 * MCP tool `reports.products.bestsellers` — bestselling products by quantity
 * ordered, aggregated per period. Mirrors admin *Reports → Products →
 * Bestsellers*. Reads `sales_bestsellers_aggregated_daily|monthly|yearly`.
 */
class Bestsellers extends AbstractAggregatedReportTool
{
    public const TOOL_NAME = 'reports.products.bestsellers';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_products_bestsellers';

    public function __construct(
        SalesReportSearchBuilder $searchBuilder,
        private readonly BestsellersReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Product Report: Bestsellers';
    }

    public function getDescription(): string
    {
        return 'Bestselling products in the given date range by quantity '
            . 'ordered, aggregated per period (day/month/year). Returns '
            . 'product_id, product_name, product_price, qty_ordered. Reads '
            . 'pre-aggregated data; if numbers look stale run '
            . '`reports.statistics.refresh_recent` with '
            . '`report_codes=["bestsellers"]`.';
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
