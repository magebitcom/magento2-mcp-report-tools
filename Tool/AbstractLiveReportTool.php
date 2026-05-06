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
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magento\Framework\Data\Collection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Shared scaffolding for live (non-aggregated) report tools. Concrete
 * subclasses produce a prepared collection and add their own tool-specific
 * schema — this base handles paging, iteration, JSON encoding, and the
 * standard audit summary.
 */
abstract class AbstractLiveReportTool implements ToolInterface
{
    public const MAX_PAGE_SIZE = 500;
    public const DEFAULT_PAGE_SIZE = 100;

    public function __construct(
        private readonly RowSerializer $serializer
    ) {
    }

    /**
     * Build and configure the collection for the given tool arguments. Must
     * NOT call `setCurPage` / `setPageSize` — paging is applied uniformly
     * by this base class.
     *
     * @param array<string, mixed> $arguments
     */
    abstract protected function buildCollection(array $arguments): Collection;

    /**
     * Optional audit metadata beyond the standard `row_count` / `total_count`.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    protected function auditContext(array $arguments): array
    {
        unset($arguments);
        return [];
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
        $collection = $this->buildCollection($arguments);

        $page = isset($arguments['page']) && is_numeric($arguments['page'])
            ? max(1, (int) $arguments['page']) : 1;
        $sizeRaw = $arguments['page_size'] ?? static::DEFAULT_PAGE_SIZE;
        if (!is_numeric($sizeRaw)) {
            throw new LocalizedException(__('"page_size" must be numeric.'));
        }
        $pageSize = min(static::MAX_PAGE_SIZE, max(1, (int) $sizeRaw));
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
            throw new LocalizedException(__('Failed to encode %1 as JSON.', $this->getName()));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: array_merge(
                [
                    'row_count' => count($rows),
                    'total_count' => $collection->getSize(),
                    'page' => $page,
                ],
                $this->auditContext($arguments)
            )
        );
    }
}
