<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Customers;

use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\AbstractLiveReportTool;
use Magento\Customer\Model\ResourceModel\Online\Grid\CollectionFactory as OnlineCollectionFactory;
use Magento\Framework\Data\Collection;

/**
 * MCP tool `reports.customers.online` — live snapshot of visitors currently
 * on the site, with cart contents, last URL, and last activity. Mirrors
 * admin *Customers → Now Online*.
 */
class Online extends AbstractLiveReportTool implements UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'reports.customers.online';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_customers_online';

    /**
     * Equivalent admin-UI ACL — preserves "MCP cannot do what the admin UI
     * cannot" against admins without rights to *Customers → Now Online*.
     */
    public const UNDERLYING_ACL_RESOURCE = 'Magento_Customer::online';

    public function __construct(
        RowSerializer $serializer,
        private readonly OnlineCollectionFactory $collectionFactory
    ) {
        parent::__construct($serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Customers Online (Live)';
    }

    public function getDescription(): string
    {
        return 'Live snapshot of visitors currently on the site. Returns '
            . 'visitor_id, customer identity when logged in (email, '
            . 'firstname, lastname), visitor_type (customer|visitor), '
            . 'last_visit_at, last_url. Useful for "who\'s shopping right '
            . 'now" and identifying VIP customers in session.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('visitor_type', fn (StringBuilder $s) => $s
                ->enum(['customer', 'visitor'])
                ->description('Filter to only logged-in customers or only guests. Omit for both.')
            )
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1)
                ->description('1-based page number.'))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)
                ->maximum(self::MAX_PAGE_SIZE)
                ->description(sprintf('Rows per page (capped at %d).', self::MAX_PAGE_SIZE)))
            ->toArray();
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    public function getUnderlyingAclResource(): ?string
    {
        return self::UNDERLYING_ACL_RESOURCE;
    }

    protected function buildCollection(array $arguments): Collection
    {
        $collection = $this->collectionFactory->create();
        if (
            isset($arguments['visitor_type'])
            && is_string($arguments['visitor_type'])
            && $arguments['visitor_type'] !== ''
        ) {
            $collection->addFieldToFilter('visitor_type', ['eq' => $arguments['visitor_type']]);
        }
        return $collection;
    }
}
