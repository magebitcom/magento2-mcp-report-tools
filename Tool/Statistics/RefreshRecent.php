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
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\AggregationDispatcher;
use Magebit\McpReportTools\Model\AggregationRegistry;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `reports.statistics.refresh_recent` — runs each requested
 * aggregation resource model over the last 25 hours. Mirrors admin
 * *Reports → Statistics → Refresh Statistics for Last Day*.
 *
 * Avoid running during peak checkout traffic — aggregation competes with
 * order-path writes on the same sales tables and briefly locks the
 * `report_flag` row.
 */
class RefreshRecent implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'reports.statistics.refresh_recent';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_statistics_refresh_recent';

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
        return 'Refresh Recent Statistics';
    }

    public function getDescription(): string
    {
        return 'Aggregate report statistics for the last 25 hours (default) '
            . 'or an explicit `from` / `to` window. Pass `report_codes=["ALL"]` '
            . 'to refresh everything registered, or a subset (e.g. '
            . '`["sales","bestsellers"]`). WARNING: avoid peak checkout '
            . 'traffic — aggregation contends with order-path writes on the '
            . 'same sales tables.';
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
            ->string('from', fn (StringBuilder $s) => $s
                ->description(
                    'Override lower bound as an ISO-8601 date/time (e.g. '
                    . '"2026-04-01" or "2026-04-01T00:00:00"). Defaults to '
                    . '25 hours before "now" in the store timezone.'
                )
            )
            ->string('to', fn (StringBuilder $s) => $s
                ->description('Override upper bound as an ISO-8601 date/time. Defaults to "now".')
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

        $from = $this->parseDate($arguments['from'] ?? null) ?? $this->dispatcher->recentWindowFrom();
        $to = $this->parseDate($arguments['to'] ?? null);

        $results = $this->dispatcher->dispatch($codes, $from, $to);

        $payload = [
            'mode' => 'recent',
            'from' => $from->format(\DateTimeInterface::ATOM),
            'to' => $to?->format(\DateTimeInterface::ATOM),
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
                'mode' => 'recent',
                'codes_requested' => count($codes),
                'codes_succeeded' => count($succeeded),
                'codes_failed' => count($results) - count($succeeded),
            ]
        );
    }

    /**
     * @throws LocalizedException
     */
    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new LocalizedException(__('Date overrides must be ISO-8601 strings.'));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new LocalizedException(__('Could not parse date "%1": %2', $value, $e->getMessage()), $e);
        }
    }
}
