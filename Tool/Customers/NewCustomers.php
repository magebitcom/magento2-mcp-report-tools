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
use Magento\Reports\Model\ResourceModel\Accounts\CollectionFactory as AccountsReportCollectionFactory;

/**
 * MCP tool `reports.customers.new` — newly registered customer accounts per
 * period. Mirrors admin *Reports → Customers → New*. Lives in
 * `Tool/Customers/NewCustomers.php` because `new` is a PHP reserved word.
 */
class NewCustomers extends AbstractCustomerReportTool
{
    public const TOOL_NAME = 'reports.customers.new';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_customers_new';

    public function __construct(
        CustomerReportSearchBuilder $searchBuilder,
        RowSerializer $serializer,
        private readonly AccountsReportCollectionFactory $collectionFactory
    ) {
        parent::__construct($searchBuilder, $serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Customer Report: New Accounts';
    }

    public function getDescription(): string
    {
        return 'Count of newly registered customer accounts per period in '
            . 'the given date range. Mirrors admin *Reports → Customers → '
            . 'New*. Live query against customer_entity.';
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
