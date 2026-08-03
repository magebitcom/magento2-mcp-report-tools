<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Test\Unit\Model\Search;

use Magebit\McpReportTools\Model\Search\CustomerReportSearchBuilder;
use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Customer\Orders\Collection as CustomerOrdersCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerReportSearchBuilderTest extends TestCase
{
    /** @var TimezoneInterface&MockObject */
    private TimezoneInterface&MockObject $timezone;

    /** @var CustomerOrdersCollection&MockObject */
    private CustomerOrdersCollection&MockObject $collection;

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn('America/New_York');
        // Faithful stand-in for Magento's locale-lenient Timezone::date(): an en_US
        // IntlDateFormatter SHORT parse, which reads "2026-07-27" as 2195-10-07.
        // Kept so any reintroduction of that parser fails the ISO tests below.
        $this->timezone->method('date')->willReturnCallback(
            static function (?string $raw = null): \DateTime {
                $formatter = new \IntlDateFormatter(
                    'en_US',
                    \IntlDateFormatter::SHORT,
                    \IntlDateFormatter::NONE,
                    'UTC'
                );
                $timestamp = $raw === null ? false : $formatter->parse($raw);
                if ($timestamp === false) {
                    throw new \Exception(sprintf('Unparseable date "%s".', (string) $raw));
                }
                return (new \DateTime('now', new \DateTimeZone('UTC')))->setTimestamp((int) $timestamp);
            }
        );
        $this->collection = $this->createMock(CustomerOrdersCollection::class);
        $this->collection->method('setDateRange')->willReturnSelf();
        $this->collection->method('setStoreIds')->willReturnSelf();
        $this->collection->method('setCurPage')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
    }

    public function testAppliesDateRangeAndStoreIds(): void
    {
        $this->collection->expects($this->once())
            ->method('setDateRange')
            ->with('2026-03-01 00:00:00', '2026-03-31 23:59:59');
        $this->collection->expects($this->once())->method('setStoreIds')->with([5]);

        $meta = $this->builder()->apply($this->collection, [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'store_id' => 5,
        ]);
        $this->assertSame('2026-03-01', $meta['from']);
        $this->assertSame('2026-03-31', $meta['to']);
        $this->assertSame([5], $meta['store_ids']);
    }

    public function testKeepsIsoDatesIntact(): void
    {
        $this->collection->expects($this->once())
            ->method('setDateRange')
            ->with('2026-07-27 00:00:00', '2026-07-30 23:59:59');

        $meta = $this->builder()->apply($this->collection, [
            'from' => '2026-07-27',
            'to' => '2026-07-30',
        ]);
        $this->assertSame('2026-07-27', $meta['from']);
        $this->assertSame('2026-07-30', $meta['to']);
    }

    public function testRejectsUsFormattedDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '07/27/2026',
            'to' => '07/30/2026',
        ]);
    }

    public function testAcceptsStoreIdArray(): void
    {
        $this->collection->expects($this->once())->method('setStoreIds')->with([1, 2, 3]);
        $this->builder()->apply($this->collection, [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'store_id' => [1, 2, 3],
        ]);
    }

    public function testRejectsReversedRange(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '2026-03-31',
            'to' => '2026-03-01',
        ]);
    }

    public function testCapsPageSize(): void
    {
        $this->collection->expects($this->once())
            ->method('setPageSize')
            ->with(CustomerReportSearchBuilder::MAX_PAGE_SIZE);
        $this->builder()->apply($this->collection, [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'page_size' => 99999,
        ]);
    }

    public function testRejectsNegativeStoreId(): void
    {
        $this->expectException(LocalizedException::class);
        $this->builder()->apply($this->collection, [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'store_id' => -1,
        ]);
    }

    private function builder(): CustomerReportSearchBuilder
    {
        return new CustomerReportSearchBuilder(new DateArgReader($this->timezone));
    }
}
