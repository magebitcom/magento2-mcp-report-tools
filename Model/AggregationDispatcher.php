<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Dispatches `aggregate($from, $to)` on each requested report code, one at a
 * time, with per-code try/catch so a single failure doesn't abort the whole
 * refresh. Mirrors
 * {@see \Magento\Reports\Controller\Adminhtml\Report\Statistics\RefreshRecent}
 * but returns a structured per-code result instead of flashing messages.
 *
 * Resource models are obtained through their auto-generated factories
 * (resolved via {@see AggregationRegistry::getFactory()}) — no
 * `ObjectManager` use, so the wiring stays compile-time visible.
 */
class AggregationDispatcher
{
    public function __construct(
        private readonly AggregationRegistry $registry,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Build the "last 25 hours" window relative to the store timezone.
     * Matches the admin RefreshRecent controller.
     */
    public function recentWindowFrom(): \DateTimeInterface
    {
        $now = $this->timezone->date();
        return $now->modify('-25 hours');
    }

    /**
     * Run `aggregate($from, $to)` for each of the requested codes.
     *
     * @param array<int, string> $codes   codes already resolved by {@see AggregationRegistry::resolveRequested()}
     * @param \DateTimeInterface|null $from null ⇒ lifetime refresh
     * @param \DateTimeInterface|null $to   null ⇒ open-ended
     * @return array<int, array<string, mixed>>
     */
    public function dispatch(array $codes, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $results = [];
        foreach ($codes as $code) {
            $results[] = $this->runOne($code, $from, $to);
        }
        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function runOne(string $code, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $start = microtime(true);
        try {
            $factory = $this->registry->getFactory($code);
            if (!method_exists($factory, 'create')) {
                throw new LocalizedException(
                    __('Aggregator factory for "%1" has no create() method.', $code)
                );
            }
            $aggregator = $factory->create();
            if (!is_object($aggregator) || !method_exists($aggregator, 'aggregate')) {
                throw new LocalizedException(
                    __('Report resource for "%1" does not implement aggregate().', $code)
                );
            }
            $aggregator->aggregate($from, $to);
            return [
                'code' => $code,
                'success' => true,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'code' => $code,
                'success' => false,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }
}
