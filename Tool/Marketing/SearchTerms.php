<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Marketing;

use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\AbstractLiveReportTool;
use Magento\Framework\Data\Collection;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory as QueryCollectionFactory;

/**
 * MCP tool `reports.marketing.search_terms` — storefront search queries
 * with popularity and result count. Mirrors admin *Reports → Marketing →
 * Search Terms*.
 */
class SearchTerms extends AbstractLiveReportTool
{
    public const TOOL_NAME = 'reports.marketing.search_terms';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_marketing_search_terms';

    public function __construct(
        RowSerializer $serializer,
        private readonly QueryCollectionFactory $collectionFactory
    ) {
        parent::__construct($serializer);
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Marketing Report: Search Terms';
    }

    public function getDescription(): string
    {
        return 'Storefront search queries ranked by popularity. Returns '
            . 'query_text, num_results, popularity, store_id, updated_at. '
            . 'Mirrors admin *Reports → Marketing → Search Terms*. Useful '
            . 'for identifying zero-result queries and high-volume terms.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0)
                ->description('Store view id to scope to. Omit for all stores.'))
            ->string('query_prefix', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Case-insensitive prefix match on query_text.'))
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
        $collection = $this->collectionFactory->create();
        if (isset($arguments['store_id']) && is_numeric($arguments['store_id'])) {
            $collection->addStoreFilter((int) $arguments['store_id']);
        }
        if (isset($arguments['query_prefix']) && is_string($arguments['query_prefix']) && $arguments['query_prefix'] !== '') {
            $collection->addFieldToFilter('query_text', ['like' => $arguments['query_prefix'] . '%']);
        }
        $collection->setOrder('popularity', Collection::SORT_ORDER_DESC);
        return $collection;
    }
}
