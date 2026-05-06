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
use Magento\Reports\Model\ResourceModel\Customer\Orders\CollectionFactory as OrdersReportCollectionFactory;

/**
 * MCP tool `reports.customers.orders` — customers ranked by order count in a
 * date range. Mirrors admin *Reports → Customers → Order Count*.
 */
class Orders extends AbstractCustomerReportTool
{
    public const TOOL_NAME = 'reports.customers.orders';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_customers_orders';

    public function __construct(
        CustomerReportSearchBuilder $searchBuilder,
        RowSerializer $serializer,
        private readonly OrdersReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder, $serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Customer Report: Order Count';
    }

    public function getDescription(): string
    {
        return 'Customers ranked by number of orders placed in the given '
            . 'date range. Mirrors admin *Reports → Customers → Order '
            . 'Count*. Live query against sales_order — no aggregation '
            . 'refresh needed.';
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
