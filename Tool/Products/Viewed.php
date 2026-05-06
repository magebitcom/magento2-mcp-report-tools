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
use Magento\Reports\Model\ResourceModel\Report\Product\Viewed\CollectionFactory as ViewedReportCollectionFactory;

/**
 * MCP tool `reports.products.viewed` — most-viewed products aggregated per
 * period. Mirrors admin *Reports → Products → Views*. Reads
 * `report_viewed_product_aggregated_daily|monthly|yearly` (selected based
 * on `period`).
 */
class Viewed extends AbstractAggregatedReportTool
{
    public const TOOL_NAME = 'reports.products.viewed';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_products_viewed';

    public function __construct(
        SalesReportSearchBuilder $searchBuilder,
        private readonly ViewedReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Product Report: Views';
    }

    public function getDescription(): string
    {
        return 'Most-viewed products in the given date range, aggregated per '
            . 'period (day/month/year). Returns product_id, product_name, '
            . 'product_price, views_num. Reads pre-aggregated data; if '
            . 'numbers look stale run `reports.statistics.refresh_recent` '
            . 'with `report_codes=["viewed"]`.';
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
