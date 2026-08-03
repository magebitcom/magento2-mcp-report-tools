<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Model\Search;

use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magento\Framework\Data\Collection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Filter + paging translation for customer-oriented live reports
 * (`reports.customers.orders`, `reports.customers.totals`,
 * `reports.customers.new`). They share a `setDateRange(string, string)`
 * and `setStoreIds(array)` surface.
 */
class CustomerReportSearchBuilder
{
    public const MAX_PAGE_SIZE = 500;
    public const DEFAULT_PAGE_SIZE = 100;

    /**
     * @param DateArgReader $dateReader
     */
    public function __construct(
        private readonly DateArgReader $dateReader
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{page:int, page_size:int, from:string, to:string, store_ids:array<int,int>}
     * @throws LocalizedException
     */
    public function apply(Collection $collection, array $args): array
    {
        $from = $this->readDate($args, 'from');
        $to = $this->readDate($args, 'to');
        if ($from > $to) {
            throw new LocalizedException(__('"from" must be on or before "to".'));
        }

        $storeIds = $this->readStoreIds($args);

        if (method_exists($collection, 'setDateRange')) {
            $collection->setDateRange($from . ' 00:00:00', $to . ' 23:59:59');
        }
        if (method_exists($collection, 'setStoreIds')) {
            $collection->setStoreIds($storeIds);
        }

        $page = isset($args['page']) && is_numeric($args['page']) ? max(1, (int) $args['page']) : 1;
        $sizeRaw = $args['page_size'] ?? self::DEFAULT_PAGE_SIZE;
        if (!is_numeric($sizeRaw)) {
            throw new LocalizedException(__('"page_size" must be numeric.'));
        }
        $size = min(self::MAX_PAGE_SIZE, max(1, (int) $sizeRaw));

        $collection->setCurPage($page);
        $collection->setPageSize($size);

        return [
            'page' => $page,
            'page_size' => $size,
            'from' => $from,
            'to' => $to,
            'store_ids' => $storeIds,
        ];
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

    /**
     * @param array<string, mixed> $args
     * @return array<int, int>
     * @throws LocalizedException
     */
    private function readStoreIds(array $args): array
    {
        if (!array_key_exists('store_id', $args) || $args['store_id'] === null || $args['store_id'] === '') {
            return [];
        }
        $raw = $args['store_id'];
        $list = is_array($raw) ? $raw : [$raw];
        $out = [];
        foreach ($list as $v) {
            if (!is_numeric($v) || (int) $v < 0) {
                throw new LocalizedException(__('"store_id" values must be non-negative integers.'));
            }
            $out[] = (int) $v;
        }
        return $out;
    }
}
