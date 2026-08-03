<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Test\Unit\Tool\Cart;

use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\Cart\Abandoned;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Reports\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbandonedTest extends TestCase
{
    /** @var TimezoneInterface&MockObject */
    private TimezoneInterface&MockObject $timezone;

    /** @var Select&MockObject */
    private Select&MockObject $select;

    /** @var QuoteCollection&MockObject */
    private QuoteCollection&MockObject $collection;

    /** @var QuoteCollectionFactory&MockObject */
    private QuoteCollectionFactory&MockObject $collectionFactory;

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn('America/New_York');

        $this->select = $this->createMock(Select::class);
        $this->select->method('reset')->willReturnSelf();
        $this->select->method('columns')->willReturnSelf();

        $this->collection = $this->createMock(QuoteCollection::class);
        $this->collection->method('getSelect')->willReturn($this->select);
        $this->collection->method('prepareForAbandonedReport')->willReturnSelf();
        $this->collection->method('addSubtotal')->willReturnSelf();
        $this->collection->method('addCustomerData')->willReturnSelf();
        $this->collection->method('setCurPage')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->collection->method('getItems')->willReturn([]);
        $this->collection->method('getSize')->willReturn(0);

        $this->collectionFactory = $this->createMock(QuoteCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);
    }

    public function testSchemaExposesDateFilters(): void
    {
        $schema = $this->tool()->getInputSchema();
        $this->assertIsArray($schema['properties'] ?? null);
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];

        foreach (['from', 'to'] as $key) {
            $this->assertArrayHasKey($key, $properties);
            $this->assertIsArray($properties[$key]);
            $this->assertSame('string', $properties[$key]['type'] ?? null);
        }

        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testSelectedColumnsOmitRemoteIp(): void
    {
        $columns = null;
        $this->select->expects($this->once())
            ->method('columns')
            ->willReturnCallback(function (mixed $cols) use (&$columns): Select {
                $columns = $cols;
                return $this->select;
            });

        $this->tool()->execute([]);

        $this->assertIsArray($columns);
        $this->assertNotContains('remote_ip', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('entity_id', $columns);
    }

    public function testAppliesStoreTimezoneDayBoundariesInUtc(): void
    {
        $calls = $this->captureFilters();

        $this->tool()->execute(['from' => '2026-07-27', 'to' => '2026-07-30']);

        // America/New_York is UTC-4 in July, so the local day boundaries shift.
        $this->assertSame(
            [
                ['main_table.updated_at', ['gteq' => '2026-07-27 04:00:00']],
                ['main_table.updated_at', ['lteq' => '2026-07-31 03:59:59']],
            ],
            $calls()
        );
    }

    public function testAppliesFromOnly(): void
    {
        $calls = $this->captureFilters();

        $this->tool()->execute(['from' => '2026-07-27']);

        $this->assertSame([['main_table.updated_at', ['gteq' => '2026-07-27 04:00:00']]], $calls());
    }

    public function testAppliesToOnly(): void
    {
        $calls = $this->captureFilters();

        $this->tool()->execute(['to' => '2026-07-30']);

        $this->assertSame([['main_table.updated_at', ['lteq' => '2026-07-31 03:59:59']]], $calls());
    }

    public function testOmittedDatesApplyNoDateFilter(): void
    {
        $this->collection->expects($this->never())->method('addFieldToFilter');

        $this->tool()->execute([]);
    }

    public function testRejectsUsFormattedDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool()->execute(['from' => '07/27/2026']);
    }

    public function testRejectsGarbageToDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool()->execute(['to' => 'last tuesday']);
    }

    /**
     * Records every `addFieldToFilter()` call; the returned closure yields them.
     *
     * @return callable(): array<int, array{0: mixed, 1: mixed}>
     */
    private function captureFilters(): callable
    {
        /** @var array<int, array{0: mixed, 1: mixed}> $calls */
        $calls = [];
        $this->collection->method('addFieldToFilter')
            ->willReturnCallback(function (mixed $field, mixed $condition = null) use (&$calls): QuoteCollection {
                $calls[] = [$field, $condition];
                return $this->collection;
            });

        return static function () use (&$calls): array {
            /** @var array<int, array{0: mixed, 1: mixed}> $calls */
            return $calls;
        };
    }

    private function tool(): Abandoned
    {
        return new Abandoned(
            new RowSerializer(),
            $this->collectionFactory,
            new DateArgReader($this->timezone)
        );
    }
}
