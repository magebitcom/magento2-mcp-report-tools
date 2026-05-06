<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Model\Support;

/**
 * Shared normaliser for report rows.
 *
 * Magento collections return every column as a string regardless of the
 * underlying DB type. Re-coerce pure numerics back to int/float so MCP
 * clients receive a typed JSON payload instead of all-strings.
 */
class RowSerializer
{
    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    public function normalise(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->normalise($value);
                continue;
            }
            if ($value === null || !is_string($value)) {
                $out[$key] = $value;
                continue;
            }
            if (is_numeric($value)) {
                $out[$key] = str_contains($value, '.') ? (float) $value : (int) $value;
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
