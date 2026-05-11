<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Statistics;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\AggregationRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Reports\Model\Flag;
use Magento\Reports\Model\FlagFactory;

/**
 * MCP tool `reports.statistics.status` — read-only view of last-refresh time
 * per registered aggregation code. Useful before deciding whether to run
 * `reports.statistics.refresh_recent` or `refresh_lifetime`.
 */
class Status implements ToolInterface
{
    public const TOOL_NAME = 'reports.statistics.status';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_statistics_status';

    public function __construct(
        private readonly AggregationRegistry $registry,
        private readonly FlagFactory $flagFactory,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Statistics Refresh Status';
    }

    public function getDescription(): string
    {
        return 'List every registered report aggregation code with the '
            . 'timestamp of its last refresh and current flag state. Read '
            . 'this before calling `reports.statistics.refresh_recent` or '
            . '`refresh_lifetime` to check whether a refresh is actually '
            . 'needed.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
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
        unset($arguments);

        $rows = [];
        foreach ($this->registry->getCodes() as $code) {
            $rows[] = $this->statusRow($code);
        }

        $json = json_encode(['items' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode statistics status as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: ['row_count' => count($rows)]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function statusRow(string $code): array
    {
        $flagCode = $this->registry->getFlagCode($code);
        $row = [
            'code' => $code,
            'flag_code' => $flagCode,
            'resource_class' => $this->registry->getResourceClass($code),
            'last_update' => null,
            'state' => null,
            'refreshed' => false,
        ];
        if ($flagCode === null) {
            return $row;
        }

        /** @var Flag $flag */
        $flag = $this->flagFactory->create();
        $flag->setReportFlagCode($flagCode);
        try {
            $flag->loadSelf();
        } catch (\Throwable $e) {
            $this->logger?->warning(sprintf(
                'reports.statistics.status: failed to load flag "%s": %s',
                $flagCode,
                $e->getMessage()
            ));
            $row['error'] = $e->getMessage();
            return $row;
        }

        if (!$flag->getId()) {
            return $row;
        }
        $row['last_update'] = (string) $flag->getLastUpdate();
        $row['state'] = (int) $flag->getState();
        $row['refreshed'] = true;
        return $row;
    }
}
