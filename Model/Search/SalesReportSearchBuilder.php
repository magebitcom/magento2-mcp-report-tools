<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Model\Search;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection;

/**
 * Shared filter/period/paging translation for every `reports.sales.*` tool.
 *
 * The six sales collections (`Order`, `Tax`, `Invoiced`, `Shipping`,
 * `Refunded`, `Rule`) all inherit from
 * {@see \Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection}
 * and expose the same `setPeriod` / `setDateRange` / `addStoreFilter` surface,
 * so one builder handles them all.
 */
class SalesReportSearchBuilder
{
    public const MAX_PAGE_SIZE = 500;
    public const DEFAULT_PAGE_SIZE = 100;

    public const PERIOD_DAY = 'day';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_YEAR = 'year';

    public const PERIODS = [self::PERIOD_DAY, self::PERIOD_MONTH, self::PERIOD_YEAR];

    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Apply the MCP filter payload to a report collection.
     *
     * @param array<string, mixed> $args
     * @return array{page:int, page_size:int, period:string, from:string, to:string, store_id:int|null}
     * @throws LocalizedException
     */
    public function apply(AbstractCollection $collection, array $args): array
    {
        $period = $this->readPeriod($args);
        $from = $this->readRequiredDate($args, 'from');
        $to = $this->readRequiredDate($args, 'to');
        if ($from > $to) {
            throw new LocalizedException(__('"from" must be on or before "to".'));
        }

        $collection->setPeriod($period);
        $collection->setDateRange($from, $to);

        $storeId = $this->readOptionalStoreId($args);
        if ($storeId !== null) {
            $collection->addStoreFilter([$storeId]);
        }

        if (isset($args['order_statuses'])) {
            $statuses = $this->readStatuses($args['order_statuses']);
            if ($statuses !== [] && method_exists($collection, 'addOrderStatusFilter')) {
                $collection->addOrderStatusFilter($statuses);
            }
        }

        $page = isset($args['page']) && is_numeric($args['page']) ? max(1, (int) $args['page']) : 1;
        $sizeRaw = $args['page_size'] ?? self::DEFAULT_PAGE_SIZE;
        if (!is_numeric($sizeRaw)) {
            throw new LocalizedException(__('"page_size" must be numeric.'));
        }
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int) $sizeRaw));

        $collection->setCurPage($page);
        $collection->setPageSize($pageSize);

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'store_id' => $storeId,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @throws LocalizedException
     */
    private function readPeriod(array $args): string
    {
        $period = $args['period'] ?? self::PERIOD_DAY;
        if (!is_string($period) || !in_array($period, self::PERIODS, true)) {
            throw new LocalizedException(
                __('"period" must be one of: %1.', implode(', ', self::PERIODS))
            );
        }
        return $period;
    }

    /**
     * @param array<string, mixed> $args
     * @param string $key
     * @throws LocalizedException
     */
    private function readRequiredDate(array $args, string $key): string
    {
        $raw = $args[$key] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new LocalizedException(__('"%1" is required (YYYY-MM-DD).', $key));
        }
        $tz = new \DateTimeZone($this->timezone->getConfigTimezone());
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasParseErrors = is_array($errors)
            && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
        if ($dt === false || $hasParseErrors) {
            throw new LocalizedException(__('"%1" must be in YYYY-MM-DD format.', $key));
        }
        return $dt->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $args
     * @throws LocalizedException
     */
    private function readOptionalStoreId(array $args): ?int
    {
        if (!array_key_exists('store_id', $args) || $args['store_id'] === null || $args['store_id'] === '') {
            return null;
        }
        $value = $args['store_id'];
        if (!is_numeric($value) || (int) $value < 0) {
            throw new LocalizedException(__('"store_id" must be a non-negative integer.'));
        }
        return (int) $value;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     * @throws LocalizedException
     */
    private function readStatuses(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            return [$raw];
        }
        if (!is_array($raw)) {
            throw new LocalizedException(__('"order_statuses" must be a string or an array of strings.'));
        }
        $out = [];
        foreach ($raw as $status) {
            if (!is_string($status) || $status === '') {
                throw new LocalizedException(__('Each entry in "order_statuses" must be a non-empty string.'));
            }
            $out[] = $status;
        }
        return $out;
    }
}
