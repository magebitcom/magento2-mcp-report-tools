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
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Reports\Model\ResourceModel\Product\Downloads\CollectionFactory as DownloadsCollectionFactory;

/**
 * MCP tool `reports.products.downloads` — downloadable-product link
 * purchases and downloads. Mirrors admin *Reports → Products → Downloads*.
 */
class Downloads implements ToolInterface
{
    public const TOOL_NAME = 'reports.products.downloads';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_products_downloads';

    public const MAX_PAGE_SIZE = 500;
    public const DEFAULT_PAGE_SIZE = 100;

    public function __construct(
        private readonly DownloadsCollectionFactory $collectionFactory,
        private readonly RowSerializer $serializer
    ) {
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Product Report: Downloads';
    }

    public function getDescription(): string
    {
        return 'Downloadable product links ranked by purchases and downloads. '
            . 'Returns product_id, sku, name, link_id, link_title, '
            . 'purchases, downloads. Mirrors admin *Reports → Products → '
            . 'Downloads*. Live query against downloadable_link_purchased_item.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
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
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku'])
            ->addSummary()
            ->setOrder('purchases', DataCollection::SORT_ORDER_DESC);

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
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode downloads report as JSON.'));
        }
        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'row_count' => count($rows),
                'total_count' => $collection->getSize(),
                'page' => $page,
            ]
        );
    }
}
