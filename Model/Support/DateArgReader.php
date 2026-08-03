<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Model\Support;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Strict ISO (YYYY-MM-DD) date-argument reader shared by all report tools.
 * Locale-lenient parsing is banned here: a mangled date must error, never
 * silently return an empty report.
 */
class DateArgReader
{
    /**
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    public function required(array $args, string $key): string
    {
        $raw = $args[$key] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new LocalizedException(__('"%1" is required (YYYY-MM-DD).', $key));
        }
        return $this->parse($raw, $key);
    }

    /**
     * @param array<string, mixed> $args
     * @param string $key
     * @return string|null
     * @throws LocalizedException
     */
    public function optional(array $args, string $key): ?string
    {
        $raw = $args[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new LocalizedException(__('"%1" must be a YYYY-MM-DD string.', $key));
        }
        return $this->parse($raw, $key);
    }

    /**
     * Converts a store-timezone calendar day boundary into a UTC timestamp string.
     *
     * @param string $date
     * @param bool $endOfDay
     * @return string
     * @throws \Exception
     */
    public function utcBoundary(string $date, bool $endOfDay): string
    {
        $tz = new \DateTimeZone($this->timezone->getConfigTimezone());
        $dt = new \DateTimeImmutable($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), $tz);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param string $raw
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    private function parse(string $raw, string $key): string
    {
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
}
