<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Model;

use Magento\Framework\Exception\LocalizedException;

/**
 * Catalog of refreshable report codes wired via DI. Mirrors the admin
 * controller's `reportTypes` array (`vendor/magento/module-reports/etc/adminhtml/di.xml`)
 * but also tracks the {@see \Magento\Reports\Model\Flag} code for each entry
 * so `reports.statistics.status` can resolve a last-refresh timestamp without
 * hard-coding the mapping inside a tool class.
 *
 * Each registered code is wired to a Magento auto-generated `*Factory`
 * (typed as `object` because Magento's auto-factories share no interface).
 * The dispatcher calls `create()` on the factory at refresh time, which
 * keeps the resource model out of `setup:di:compile`'s shared-instance pool
 * and matches admin controller behaviour.
 *
 * Adding a new aggregation is a one-line DI merge:
 *
 * ```xml
 * <type name="Magebit\McpReportTools\Model\AggregationRegistry">
 *     <arguments>
 *         <argument name="aggregatorFactories" xsi:type="array">
 *             <item name="settlement" xsi:type="object">Vendor\Module\Model\ResourceModel\Report\SettlementFactory</item>
 *         </argument>
 *         <argument name="flagCodes" xsi:type="array">
 *             <item name="settlement" xsi:type="string">vendor_settlement_aggregated</item>
 *         </argument>
 *     </arguments>
 * </type>
 * ```
 */
class AggregationRegistry
{
    /**
     * @param array<string, object> $aggregatorFactories code => Magento auto-factory for the resource model
     * @param array<string, string> $flagCodes           code => report_flag.flag_code value
     */
    public function __construct(
        private readonly array $aggregatorFactories = [],
        private readonly array $flagCodes = []
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getCodes(): array
    {
        return array_keys($this->aggregatorFactories);
    }

    /**
     * Return the auto-generated factory registered for the given code. The
     * caller is expected to invoke `create()` at runtime — Magento factories
     * share no compile-time interface, so this method's return type is
     * intentionally `object`.
     *
     * @throws LocalizedException when $code is not registered
     */
    public function getFactory(string $code): object
    {
        if (!array_key_exists($code, $this->aggregatorFactories)) {
            throw new LocalizedException(
                __('Unknown report code "%1". Known codes: %2.', $code, implode(', ', $this->getCodes()))
            );
        }
        return $this->aggregatorFactories[$code];
    }

    /**
     * Return the resource-model class name that the registered factory
     * produces. Derived by stripping the trailing `Factory` from the
     * factory's class name — Magento auto-factories follow this convention.
     * Used by the `reports.statistics.status` tool for display only.
     *
     * @throws LocalizedException when $code is not registered
     */
    public function getResourceClass(string $code): string
    {
        $factory = $this->getFactory($code);
        $factoryClass = $factory::class;
        return str_ends_with($factoryClass, 'Factory')
            ? substr($factoryClass, 0, -7)
            : $factoryClass;
    }

    /**
     * @return string|null flag code for the report, or null if none is registered
     */
    public function getFlagCode(string $code): ?string
    {
        $flag = $this->flagCodes[$code] ?? null;
        return is_string($flag) && $flag !== '' ? $flag : null;
    }

    /**
     * Expand 'ALL' or an input list into a deduplicated list of known codes.
     *
     * @param mixed $input either 'ALL', a single code, or an array of codes
     * @return array<int, string>
     * @throws LocalizedException when any requested code is not registered
     */
    public function resolveRequested(mixed $input): array
    {
        if ($input === null || $input === '' || $input === [] || $input === 'ALL') {
            return $this->getCodes();
        }
        $list = is_array($input) ? $input : [$input];
        $resolved = [];
        foreach ($list as $code) {
            if (!is_string($code) || $code === '') {
                throw new LocalizedException(__('Report codes must be non-empty strings.'));
            }
            if ($code === 'ALL') {
                return $this->getCodes();
            }
            if (!array_key_exists($code, $this->aggregatorFactories)) {
                throw new LocalizedException(
                    __('Unknown report code "%1". Known codes: %2.', $code, implode(', ', $this->getCodes()))
                );
            }
            $resolved[$code] = true;
        }
        return array_keys($resolved);
    }
}
