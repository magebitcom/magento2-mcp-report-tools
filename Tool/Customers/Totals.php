<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Customers;

use Magebit\McpReportTools\Model\Search\CustomerReportSearchBuilder;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magento\Framework\Data\Collection;
use Magento\Reports\Model\ResourceModel\Customer\Totals\CollectionFactory as TotalsReportCollectionFactory;

/**
 * MCP tool `reports.customers.totals` — customers ranked by total spend in a
 * date range. Mirrors admin *Reports → Customers → Order Total*.
 */
class Totals extends AbstractCustomerReportTool
{
    public const TOOL_NAME = 'reports.customers.totals';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_customers_totals';

    public function __construct(
        CustomerReportSearchBuilder $searchBuilder,
        RowSerializer $serializer,
        private readonly TotalsReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder, $serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Customer Report: Order Total';
    }

    public function getDescription(): string
    {
        return 'Customers ranked by total amount spent in the given date '
            . 'range (aka "Order Total" / customer LTV for the window). '
            . 'Mirrors admin *Reports → Customers → Order Total*. Live '
            . 'query against sales_order — no aggregation refresh needed.';
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    protected function createCollection(): Collection
    {
        return $this->collectionFactory->create();
    }
}
