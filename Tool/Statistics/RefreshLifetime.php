<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Statistics;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\AggregationDispatcher;
use Magebit\McpReportTools\Model\AggregationRegistry;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `reports.statistics.refresh_lifetime` — re-aggregates every
 * row in the source sales tables for the requested report codes. Mirrors
 * admin *Reports → Statistics → Refresh Lifetime Statistics*.
 *
 * HEAVY. On stores with 500k+ orders this can take 5–30 minutes and will
 * noticeably elevate DB load. Run off-peak. Confirmation is mandatory.
 */
class RefreshLifetime implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'reports.statistics.refresh_lifetime';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_statistics_refresh_lifetime';

    public function __construct(
        private readonly AggregationRegistry $registry,
        private readonly AggregationDispatcher $dispatcher
    ) {
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Refresh Lifetime Statistics';
    }

    public function getDescription(): string
    {
        return 'Re-aggregate every row in the source sales tables since the '
            . 'store opened, for each requested report code. HEAVY: on a '
            . 'store with 500k+ orders this can take 5–30 minutes and will '
            . 'noticeably elevate DB load. Strongly recommended to run '
            . 'off-peak. Pass `report_codes=["ALL"]` or a subset — per-code '
            . 'failures are reported individually and do not abort the rest.';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->array('report_codes', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description(
                    'Report codes to refresh. Use ["ALL"] to refresh every '
                    . 'registered aggregation. Valid individual codes: '
                    . implode(', ', $this->registry->getCodes()) . '.'
                )
            )
            ->toArray();
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    public function getUnderlyingAclResource(): ?string
    {
        return 'Magento_Reports::statistics';
    }

    public function getWriteMode(): WriteMode
    {
        return WriteMode::WRITE;
    }

    public function getConfirmationRequired(): bool
    {
        return true;
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $codes = $this->registry->resolveRequested($arguments['report_codes'] ?? null);

        $results = $this->dispatcher->dispatch($codes, null, null);

        $payload = [
            'mode' => 'lifetime',
            'results' => $results,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode refresh result as JSON.'));
        }

        $succeeded = array_values(array_filter(
            $results,
            static fn(array $r): bool => (bool) ($r['success'] ?? false)
        ));
        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            isError: count($succeeded) !== count($results),
            auditSummary: [
                'mode' => 'lifetime',
                'codes_requested' => count($codes),
                'codes_succeeded' => count($succeeded),
                'codes_failed' => count($results) - count($succeeded),
            ]
        );
    }
}
