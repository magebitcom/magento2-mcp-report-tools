<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Reviews;

use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\AbstractLiveReportTool;
use Magento\Framework\Data\Collection;
use Magento\Reports\Model\ResourceModel\Review\Product\CollectionFactory as ReviewProductCollectionFactory;

/**
 * MCP tool `reports.reviews.by_product` — products ranked by review count
 * with average rating. Mirrors admin *Reports → Reviews → By Products*.
 */
class ByProduct extends AbstractLiveReportTool
{
    public const TOOL_NAME = 'reports.reviews.by_product';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_reviews_by_product';

    public function __construct(
        RowSerializer $serializer,
        private readonly ReviewProductCollectionFactory $collectionFactory
    ) {
        parent::__construct($serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Review Report: By Product';
    }

    public function getDescription(): string
    {
        return 'Products ranked by number of reviews, with average rating. '
            . 'Mirrors admin *Reports → Reviews → By Products*. Live query '
            . 'against review + rating_option_vote_aggregated.';
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
        return $this->collectionFactory->create();
    }
}
