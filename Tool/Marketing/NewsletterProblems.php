<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Marketing;

use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\AbstractLiveReportTool;
use Magento\Framework\Data\Collection;
use Magento\Newsletter\Model\ResourceModel\Problem\CollectionFactory as ProblemCollectionFactory;

/**
 * MCP tool `reports.marketing.newsletter_problems` — newsletter queue
 * problem reports (bounces, send failures). Mirrors admin
 * *Reports → Marketing → Newsletter Problem Reports*.
 */
class NewsletterProblems extends AbstractLiveReportTool
{
    public const TOOL_NAME = 'reports.marketing.newsletter_problems';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_marketing_newsletter_problems';

    public function __construct(
        RowSerializer $serializer,
        private readonly ProblemCollectionFactory $collectionFactory
    ) {
        parent::__construct($serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Marketing Report: Newsletter Problems';
    }

    public function getDescription(): string
    {
        return 'Newsletter queue problem reports — subscriber email, queue '
            . 'id, subject, error code, error text. Mirrors admin '
            . '*Reports → Marketing → Newsletter Problem Reports*. Useful '
            . 'for identifying bad email addresses and queue send failures.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)
                ->maximum(self::MAX_PAGE_SIZE)
                ->description(sprintf('Rows per page (capped at %d).', self::MAX_PAGE_SIZE)))
            ->toArray();
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    protected function buildCollection(array $arguments): Collection
    {
        unset($arguments);
        $collection = $this->collectionFactory->create();
        $collection->addSubscriberInfo();
        $collection->addQueueInfo();
        return $collection;
    }
}
