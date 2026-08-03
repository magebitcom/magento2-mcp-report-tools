<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Products;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Reports\Model\ResourceModel\Product\Sold\CollectionFactory as SoldCollectionFactory;

/**
 * MCP tool `reports.products.ordered` — quantity ordered per product in a
 * date range (a.k.a. "Products Ordered"). Mirrors admin
 * *Reports → Products → Ordered*. Live query against sales_order_item +
 * sales_order.
 */
class Ordered implements ToolInterface
{
    public const TOOL_NAME = 'reports.products.ordered';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_products_ordered';

    public const MAX_PAGE_SIZE = 500;
    public const DEFAULT_PAGE_SIZE = 100;

    /**
     * @param SoldCollectionFactory $collectionFactory
     * @param RowSerializer $serializer
     * @param DateArgReader $dateReader
     */
    public function __construct(
        private readonly SoldCollectionFactory $collectionFactory,
        private readonly RowSerializer $serializer,
        private readonly DateArgReader $dateReader
    ) {
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Product Report: Ordered';
    }

    public function getDescription(): string
    {
        return 'Products ranked by quantity ordered in the given date range '
            . '(excludes cancelled orders). Mirrors admin '
            . '*Reports → Products → Ordered*. Live query — no aggregation '
            . 'refresh needed.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('from', fn (StringBuilder $s) => $s->required()
                ->description('Start date (YYYY-MM-DD).'))
            ->string('to', fn (StringBuilder $s) => $s->required()
                ->description('End date (YYYY-MM-DD).'))
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0)
                ->description('Limit to one store view id.'))
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
        $from = $this->readDate($arguments, 'from');
        $to = $this->readDate($arguments, 'to');
        if ($from > $to) {
            throw new LocalizedException(__('"from" must be on or before "to".'));
        }

        $collection = $this->collectionFactory->create();
        $collection->setDateRange($from . ' 00:00:00', $to . ' 23:59:59');
        if (isset($arguments['store_id']) && is_numeric($arguments['store_id'])) {
            $collection->setStoreIds([(int) $arguments['store_id']]);
        }

        $page = isset($arguments['page']) && is_numeric($arguments['page']) ? max(1, (int) $arguments['page']) : 1;
        $sizeRaw = $arguments['page_size'] ?? self::DEFAULT_PAGE_SIZE;
        if (!is_numeric($sizeRaw)) {
            throw new LocalizedException(__('"page_size" must be numeric.'));
        }
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int) $sizeRaw));
        $collection->setCurPage($page);
        $collection->setPageSize($pageSize);

        $rows = [];
        /** @var \Magento\Framework\DataObject $item */
        foreach ($collection->getItems() as $item) {
            $data = $item->getData();
            if (is_array($data)) {
                $rows[] = $this->serializer->normalise($data);
            }
        }
        $payload = [
            'items' => $rows,
            'total_count' => $collection->getSize(),
            'page' => $page,
            'page_size' => $pageSize,
            'from' => $from,
            'to' => $to,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode ordered-products report as JSON.'));
        }
        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'row_count' => count($rows),
                'total_count' => $collection->getSize(),
                'from' => $from,
                'to' => $to,
                'page' => $page,
            ]
        );
    }

    /**
     * @param array<string, mixed> $args
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    private function readDate(array $args, string $key): string
    {
        return $this->dateReader->required($args, $key);
    }
}
