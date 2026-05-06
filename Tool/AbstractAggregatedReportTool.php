<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\Search\SalesReportSearchBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection;

/**
 * Shared scaffolding for the aggregated `reports.*` tools (sales / products
 * with pre-aggregated source tables).
 *
 * Concrete subclasses provide:
 *   - `getName()` / `getTitle()` / `getDescription()` / `getAclResource()`
 *   - `createCollection()` — returns a freshly-built report collection,
 *     typically by calling `->create()` on an injected auto-factory
 *
 * Everything else (schema shape, filter application, iteration, JSON encode,
 * audit summary) is fixed here so all subclasses share one implementation
 * and one wire shape.
 */
abstract class AbstractAggregatedReportTool implements ToolInterface
{
    public function __construct(
        private readonly SalesReportSearchBuilder $searchBuilder
    ) {
    }

    /**
     * Build a fresh report collection. Implementations should inject the
     * matching Magento auto-factory and return `$factory->create()`.
     */
    abstract protected function createCollection(): AbstractCollection;

    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('from', fn (StringBuilder $s) => $s
                ->description('Start date (YYYY-MM-DD) in the store timezone. Required.')
                ->required()
            )
            ->string('to', fn (StringBuilder $s) => $s
                ->description('End date (YYYY-MM-DD) in the store timezone. Required.')
                ->required()
            )
            ->string('period', fn (StringBuilder $s) => $s
                ->enum(SalesReportSearchBuilder::PERIODS)
                ->description('Bucket size. Defaults to "day".')
            )
            ->integer('store_id', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Limit to one store view id. Omit for all stores.')
            )
            ->array('order_statuses', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description('Restrict to given order statuses (e.g. ["complete","processing"]).')
            )
            ->integer('page', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('1-based page number.')
            )
            ->integer('page_size', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(SalesReportSearchBuilder::MAX_PAGE_SIZE)
                ->description(sprintf('Rows per page (capped at %d).', SalesReportSearchBuilder::MAX_PAGE_SIZE))
            )
            ->toArray();
    }

    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    public function getConfirmationRequired(): bool
    {
        return false;
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $collection = $this->createCollection();
        $meta = $this->searchBuilder->apply($collection, $arguments);

        $rows = [];
        /** @var \Magento\Framework\DataObject $item */
        foreach ($collection->getItems() as $item) {
            $data = $item->getData();
            if (is_array($data)) {
                $rows[] = $this->normaliseRow($data);
            }
        }

        $payload = [
            'items' => $rows,
            'total_count' => $collection->getSize(),
            'page' => $meta['page'],
            'page_size' => $meta['page_size'],
            'period' => $meta['period'],
            'from' => $meta['from'],
            'to' => $meta['to'],
            'store_id' => $meta['store_id'],
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode %1 report as JSON.', $this->getName()));
        }
        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'row_count' => count($rows),
                'total_count' => $collection->getSize(),
                'period' => $meta['period'],
                'from' => $meta['from'],
                'to' => $meta['to'],
                'store_id' => $meta['store_id'],
                'page' => $meta['page'],
            ]
        );
    }

    /**
     * Cast numerics to float/int where possible so JSON output is typed
     * rather than all-strings (collection rows come back from the DB as
     * strings).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function normaliseRow(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $out[$key] = null;
                continue;
            }
            if (is_string($value) && is_numeric($value)) {
                $out[$key] = str_contains($value, '.') ? (float) $value : (int) $value;
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
