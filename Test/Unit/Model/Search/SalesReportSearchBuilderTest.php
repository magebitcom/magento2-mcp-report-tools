<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Test\Unit\Model\Search;

use Magebit\McpReportTools\Model\Search\SalesReportSearchBuilder;
use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Report\Collection\AbstractCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SalesReportSearchBuilderTest extends TestCase
{
    /** @var TimezoneInterface&MockObject */
    private TimezoneInterface&MockObject $timezone;

    /** @var AbstractCollection&MockObject */
    private AbstractCollection&MockObject $collection;

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn('UTC');
        $this->collection = $this->createMock(AbstractCollection::class);
        $this->collection->method('setPeriod')->willReturnSelf();
        $this->collection->method('setDateRange')->willReturnSelf();
        $this->collection->method('addStoreFilter')->willReturnSelf();
        $this->collection->method('setCurPage')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
    }

    public function testAppliesDefaultPeriodAndDates(): void
    {
        $this->collection->expects($this->once())->method('setPeriod')->with('day');
        $this->collection->expects($this->once())->method('setDateRange')->with('2026-04-01', '2026-04-10');

        $meta = $this->builder()->apply($this->collection, [
            'from' => '2026-04-01',
            'to' => '2026-04-10',
        ]);

        $this->assertSame('day', $meta['period']);
        $this->assertSame('2026-04-01', $meta['from']);
        $this->assertSame('2026-04-10', $meta['to']);
        $this->assertSame(1, $meta['page']);
        $this->assertSame(SalesReportSearchBuilder::DEFAULT_PAGE_SIZE, $meta['page_size']);
    }

    public function testRequiresFromAndTo(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, []);
    }

    public function testRejectsInvalidPeriod(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '2026-04-01',
            'to' => '2026-04-10',
            'period' => 'hourly',
        ]);
    }

    public function testRejectsReversedDateRange(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '2026-04-10',
            'to' => '2026-04-01',
        ]);
    }

    public function testCapsPageSizeAtMax(): void
    {
        $this->collection->expects($this->once())
            ->method('setPageSize')
            ->with(SalesReportSearchBuilder::MAX_PAGE_SIZE);

        $this->builder()->apply($this->collection, [
            'from' => '2026-04-01',
            'to' => '2026-04-10',
            'page_size' => SalesReportSearchBuilder::MAX_PAGE_SIZE * 2,
        ]);
    }

    public function testAppliesStoreFilterWhenProvided(): void
    {
        $this->collection->expects($this->once())->method('addStoreFilter')->with([3]);
        $this->builder()->apply($this->collection, [
            'from' => '2026-04-01',
            'to' => '2026-04-10',
            'store_id' => 3,
        ]);
    }

    public function testOmitsStoreFilterWhenMissing(): void
    {
        $this->collection->expects($this->never())->method('addStoreFilter');
        $this->builder()->apply($this->collection, [
            'from' => '2026-04-01',
            'to' => '2026-04-10',
        ]);
    }

    public function testRejectsNonNumericPageSize(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '2026-04-01',
            'to' => '2026-04-10',
            'page_size' => 'lots',
        ]);
    }

    public function testParsesIsoDateRegardlessOfTimezoneServiceBehavior(): void
    {
        $this->timezone->method('date')->willReturn(new \DateTimeImmutable('2179-10-04'));

        $this->collection
            ->expects($this->once())
            ->method('setDateRange')
            ->with('2026-04-11', '2026-05-11');

        $meta = $this->builder()->apply($this->collection, [
            'from' => '2026-04-11',
            'to' => '2026-05-11',
        ]);

        $this->assertSame('2026-04-11', $meta['from']);
        $this->assertSame('2026-05-11', $meta['to']);
    }

    public function testRejectsNonIsoDateFormat(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '04/11/2026',
            'to' => '05/11/2026',
        ]);
    }

    public function testRejectsNonsenseDateString(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => 'tomorrow',
            'to' => 'next year',
        ]);
    }

    public function testRejectsOutOfRangeCalendarDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '2026-13-45',
            'to' => '2026-14-99',
        ]);
    }

    private function builder(): SalesReportSearchBuilder
    {
        return new SalesReportSearchBuilder(new DateArgReader($this->timezone));
    }
}
