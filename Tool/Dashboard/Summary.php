<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Tool\Dashboard;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Order\CollectionFactory as OrderReportCollectionFactory;
use Magento\Sales\Model\ResourceModel\Report\Bestsellers\CollectionFactory as BestsellersReportCollectionFactory;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory as SearchQueryCollectionFactory;

/**
 * MCP tool `reports.dashboard.summary` — one-call roll-up that mirrors the
 * admin Dashboard screen. Returns lifetime sales, average order amount,
 * revenue totals for a requested period, the last N orders, top search
 * terms, and top bestsellers.
 *
 * Intended as the "how is the store doing right now" convenience tool so an
 * agent doesn't need to chain five calls.
 */
class Summary implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'reports.dashboard.summary';
    public const ACL_RESOURCE = 'Magebit_McpReportTools::mcp_tool_reports_dashboard_summary';

    /**
     * Equivalent admin-UI ACL — preserves "MCP cannot do what the admin UI
     * cannot" against admins without rights to the admin Dashboard.
     */
    public const UNDERLYING_ACL_RESOURCE = 'Magento_Backend::dashboard';

    private const DEFAULT_PERIOD_DAYS = 30;
    private const DEFAULT_RECENT_ORDERS = 5;
    private const MAX_RECENT_ORDERS = 20;
    private const DEFAULT_TOP_LISTS = 5;
    private const MAX_TOP_LISTS = 50;

    /**
     * @param OrderReportCollectionFactory $ordersFactory
     * @param BestsellersReportCollectionFactory $bestsellersFactory
     * @param SearchQueryCollectionFactory $searchTermsFactory
     * @param RowSerializer $serializer
     * @param TimezoneInterface $timezone
     * @param DateArgReader $dateReader
     */
    public function __construct(
        private readonly OrderReportCollectionFactory $ordersFactory,
        private readonly BestsellersReportCollectionFactory $bestsellersFactory,
        private readonly SearchQueryCollectionFactory $searchTermsFactory,
        private readonly RowSerializer $serializer,
        private readonly TimezoneInterface $timezone,
        private readonly DateArgReader $dateReader
    ) {
    }

    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    public function getTitle(): string
    {
        return 'Admin Dashboard Summary';
    }

    public function getDescription(): string
    {
        return 'One-call roll-up of the admin Dashboard — lifetime sales, '
            . 'average order amount, revenue / tax / shipping / qty for a '
            . 'requested period (default last 30 days), recent orders, top '
            . 'search terms, top bestsellers. Bestseller and search data '
            . 'are live-computed; lifetime / period totals read from '
            . '`sales_order_aggregated_created` (run '
            . '`reports.statistics.refresh_recent` if stale).';
    }

    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0)
                ->description('Limit to one store view id. Omit for all stores.'))
            ->string('period_from', fn (StringBuilder $s) => $s
                ->description(sprintf('Period start (YYYY-MM-DD). Defaults to %d days ago.', self::DEFAULT_PERIOD_DAYS)))
            ->string('period_to', fn (StringBuilder $s) => $s
                ->description('Period end (YYYY-MM-DD). Defaults to today.'))
            ->integer('recent_orders_limit', fn (IntegerBuilder $i) => $i->minimum(0)
                ->maximum(self::MAX_RECENT_ORDERS)
                ->description(sprintf('Recent orders to return (default %d, max %d).', self::DEFAULT_RECENT_ORDERS, self::MAX_RECENT_ORDERS)))
            ->integer('top_search_terms_limit', fn (IntegerBuilder $i) => $i->minimum(0)
                ->maximum(self::MAX_TOP_LISTS)
                ->description(sprintf('Top search terms (default %d, max %d).', self::DEFAULT_TOP_LISTS, self::MAX_TOP_LISTS)))
            ->integer('top_bestsellers_limit', fn (IntegerBuilder $i) => $i->minimum(0)
                ->maximum(self::MAX_TOP_LISTS)
                ->description(sprintf('Top bestsellers (default %d, max %d).', self::DEFAULT_TOP_LISTS, self::MAX_TOP_LISTS)))
            ->toArray();
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    public function getUnderlyingAclResource(): ?string
    {
        return self::UNDERLYING_ACL_RESOURCE;
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

        [$periodFrom, $periodTo] = $this->resolvePeriod($arguments);

        $recentLimit = $this->clampLimit(
            $arguments['recent_orders_limit'] ?? self::DEFAULT_RECENT_ORDERS,
            self::MAX_RECENT_ORDERS
        );
        $searchLimit = $this->clampLimit(
            $arguments['top_search_terms_limit'] ?? self::DEFAULT_TOP_LISTS,
            self::MAX_TOP_LISTS
        );
        $bestsellerLimit = $this->clampLimit(
            $arguments['top_bestsellers_limit'] ?? self::DEFAULT_TOP_LISTS,
            self::MAX_TOP_LISTS
        );

        $payload = [
            'store_id' => $storeId,
            'period' => ['from' => $periodFrom, 'to' => $periodTo],
            'lifetime' => $this->lifetimeTotals($storeId),
            'period_totals' => $this->periodTotals($storeId, $periodFrom, $periodTo),
            'recent_orders' => $this->recentOrders($storeId, $recentLimit),
            'top_search_terms' => $this->topSearchTerms($storeId, $searchLimit),
            'top_bestsellers' => $this->topBestsellers($storeId, $periodFrom, $periodTo, $bestsellerLimit),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode dashboard summary as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'store_id' => $storeId,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'recent_orders_count' => count($payload['recent_orders']),
                'top_search_terms_count' => count($payload['top_search_terms']),
                'top_bestsellers_count' => count($payload['top_bestsellers']),
            ]
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: string}
     * @throws LocalizedException
     */
    private function resolvePeriod(array $arguments): array
    {
        $now = $this->timezone->date();
        $defaultFrom = (clone $now)->modify(sprintf('-%d days', self::DEFAULT_PERIOD_DAYS));

        $from = $this->dateReader->optional($arguments, 'period_from') ?? $defaultFrom->format('Y-m-d');
        $to = $this->dateReader->optional($arguments, 'period_to') ?? $now->format('Y-m-d');
        if ($from > $to) {
            throw new LocalizedException(__('"period_from" must be on or before "period_to".'));
        }
        return [$from, $to];
    }

    private function clampLimit(mixed $raw, int $max): int
    {
        if (!is_numeric($raw)) {
            return 0;
        }
        return min($max, max(0, (int) $raw));
    }

    /**
     * @return array<string, float>
     */
    private function lifetimeTotals(?int $storeId): array
    {
        $collection = $this->ordersFactory->create()->calculateTotals();
        if ($storeId !== null && $storeId > 0) {
            $collection->addFieldToFilter('store_id', ['eq' => $storeId]);
        }
        $collection->load();
        /** @var \Magento\Framework\DataObject $first */
        $first = $collection->getFirstItem();
        $data = $first->getData();
        if (!is_array($data) || $data === []) {
            return ['lifetime_sales' => 0.0, 'average_order' => 0.0];
        }
        return [
            'lifetime_sales' => isset($data['lifetime']) && is_numeric($data['lifetime']) ? (float) $data['lifetime'] : 0.0,
            'average_order' => isset($data['average']) && is_numeric($data['average']) ? (float) $data['average'] : 0.0,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function periodTotals(?int $storeId, string $from, string $to): array
    {
        $collection = $this->ordersFactory->create()->calculateSales();
        if ($storeId !== null && $storeId > 0) {
            $collection->addFieldToFilter('store_id', ['eq' => $storeId]);
        }
        $collection->addFieldToFilter('created_at', ['gteq' => $from . ' 00:00:00'])
            ->addFieldToFilter('created_at', ['lteq' => $to . ' 23:59:59'])
            ->load();
        /** @var \Magento\Framework\DataObject $first */
        $first = $collection->getFirstItem();
        $data = $first->getData();
        return is_array($data) ? $this->serializer->normalise($data) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentOrders(?int $storeId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }
        $collection = $this->ordersFactory->create()->addItemCountExpr();
        if ($storeId !== null && $storeId > 0) {
            $collection->addFieldToFilter('store_id', ['eq' => $storeId]);
        }
        $collection->setOrder('created_at', 'desc');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);
        $rows = [];
        /** @var \Magento\Framework\DataObject $order */
        foreach ($collection->getItems() as $order) {
            $data = $order->getData();
            if (!is_array($data)) {
                continue;
            }
            $rows[] = [
                'entity_id' => isset($data['entity_id']) && is_numeric($data['entity_id']) ? (int) $data['entity_id'] : null,
                'increment_id' => isset($data['increment_id']) && is_scalar($data['increment_id'])
                    ? (string) $data['increment_id'] : null,
                'store_id' => isset($data['store_id']) && is_numeric($data['store_id']) ? (int) $data['store_id'] : null,
                'customer_name' => isset($data['customer_name']) && is_scalar($data['customer_name'])
                    ? (string) $data['customer_name'] : null,
                'customer_email' => isset($data['customer_email']) && is_scalar($data['customer_email'])
                    ? (string) $data['customer_email'] : null,
                'status' => isset($data['status']) && is_scalar($data['status'])
                    ? (string) $data['status'] : null,
                'items_count' => isset($data['items_count']) && is_numeric($data['items_count']) ? (int) $data['items_count'] : null,
                'grand_total' => isset($data['grand_total']) && is_numeric($data['grand_total']) ? (float) $data['grand_total'] : null,
                'created_at' => isset($data['created_at']) && is_scalar($data['created_at'])
                    ? (string) $data['created_at'] : null,
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    private function topSearchTerms(?int $storeId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }
        $collection = $this->searchTermsFactory->create();
        if ($storeId !== null && $storeId > 0) {
            $collection->addStoreFilter($storeId);
        }
        $collection->setOrder('popularity', 'desc');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);
        $rows = [];
        /** @var \Magento\Framework\DataObject $item */
        foreach ($collection->getItems() as $item) {
            $data = $item->getData();
            if (is_array($data)) {
                $rows[] = $this->serializer->normalise($data);
            }
        }
        return $rows;
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    private function topBestsellers(?int $storeId, string $from, string $to, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }
        $collection = $this->bestsellersFactory->create();
        $collection->setPeriod('day');
        $collection->setDateRange($from, $to);
        if ($storeId !== null && $storeId > 0) {
            $collection->addStoreFilter([$storeId]);
        }
        $collection->setPageSize($limit);
        $collection->setCurPage(1);
        $rows = [];
        /** @var \Magento\Framework\DataObject $item */
        foreach ($collection->getItems() as $item) {
            $data = $item->getData();
            if (is_array($data)) {
                $rows[] = $this->serializer->normalise($data);
            }
        }
        return $rows;
    }
}
