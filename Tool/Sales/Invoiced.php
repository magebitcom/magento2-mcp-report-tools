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
use Magento\Sales\Model\ResourceModel\Report\Invoiced\Collection\InvoicedFactory as InvoicedReportCollectionFactory;

/**
 * MCP tool `reports.sales.invoiced` — invoicing activity aggregated per
 * period by **invoice date**. Mirrors admin *Reports → Sales → Invoiced*
 * with its "Date Used" defaulted to "Invoice Date". Reads
 * `sales_invoiced_aggregated`.
 */
class Invoiced extends AbstractAggregatedReportTool
{
    public const TOOL_NAME = 'reports.sales.invoiced';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_sales_invoiced';

    public function __construct(
        SalesReportSearchBuilder $searchBuilder,
        private readonly InvoicedReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Sales Report: Invoiced';
    }

    public function getDescription(): string
    {
        return 'Invoicing activity aggregated per period — orders invoiced, '
            . 'invoiced total, invoiced total paid, invoiced total not paid. '
            . 'Date axis is the invoice date (admin "Invoiced" grid default). '
            . 'Reads pre-aggregated data; if numbers look stale run '
            . '`reports.statistics.refresh_recent` with `report_codes=["invoiced"]`.';
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
