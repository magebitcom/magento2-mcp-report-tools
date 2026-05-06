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
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Reports\Model\ResourceModel\Product\Lowstock\CollectionFactory as LowStockCollectionFactory;

/**
 * MCP tool `reports.products.low_stock` — products at or below their
 * notify-stock threshold. Mirrors admin *Reports → Products → Low Stock*.
 */
class LowStock implements ToolInterface
{
    public const TOOL_NAME = 'reports.products.low_stock';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_products_low_stock';

    public const MAX_PAGE_SIZE = 500;
    public const DEFAULT_PAGE_SIZE = 100;

    public function __construct(
        private readonly LowStockCollectionFactory $collectionFactory,
        private readonly RowSerializer $serializer
    ) {
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Product Report: Low Stock';
    }

    public function getDescription(): string
    {
        return 'Products whose inventory is at or below the notify-stock '
            . 'threshold configured per product or globally. Mirrors admin '
            . '*Reports → Products → Low Stock*. Live query — no '
            . 'aggregation refresh needed.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0)
                ->description('Optional store view id to scope stock thresholds to.'))
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
        $storeId = isset($arguments['store_id']) && is_numeric($arguments['store_id'])
            ? (int) $arguments['store_id'] : null;

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*')
            ->filterByIsQtyProductTypes()
            ->joinInventoryItem(['qty'])
            ->useManageStockFilter($storeId)
            ->useNotifyStockQtyFilter($storeId)
            ->setOrder('qty', DataCollection::SORT_ORDER_ASC)
            ->addAttributeToFilter('status', ['eq' => ProductStatus::STATUS_ENABLED]);
        if ($storeId !== null && $storeId > 0) {
            $collection->addStoreFilter($storeId);
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
            'store_id' => $storeId,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode low-stock report as JSON.'));
        }
        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'row_count' => count($rows),
                'total_count' => $collection->getSize(),
                'page' => $page,
                'store_id' => $storeId,
            ]
        );
    }
}
