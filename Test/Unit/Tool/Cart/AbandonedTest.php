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
use Magebit\McpReportTools\Tool\AbstractLiveReportTool;
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
    /**
     * Ordered log of the collection calls that matter for correctness. A mocked
     * collection has no load state, so ordering against the load-triggering
     * `resolveCustomerNames()` is what these tests assert.
     *
     * @var array<int, array<int, mixed>>
     */
    private array $calls = [];

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
        $this->calls = [];

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
        $this->collection->method('getSize')->willReturn(0);

        $this->collection->method('addFieldToFilter')
            ->willReturnCallback(function (mixed $field, mixed $condition = null): QuoteCollection {
                $this->calls[] = ['addFieldToFilter', $field, $condition];
                return $this->collection;
            });
        $this->collection->method('setCurPage')
            ->willReturnCallback(function (mixed $page): QuoteCollection {
                $this->calls[] = ['setCurPage', $page];
                return $this->collection;
            });
        $this->collection->method('setPageSize')
            ->willReturnCallback(function (mixed $size): QuoteCollection {
                $this->calls[] = ['setPageSize', $size];
                return $this->collection;
            });
        $this->collection->method('resolveCustomerNames')
            ->willReturnCallback(function (): void {
                $this->calls[] = ['resolveCustomerNames'];
            });
        $this->collection->method('getItems')
            ->willReturnCallback(function (): array {
                $this->calls[] = ['getItems'];
                return [];
            });

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

    /**
     * Guards the load-order bug: `resolveCustomerNames()` loads the collection,
     * so every filter and both paging calls have to land before it.
     */
    public function testFiltersAndPagingAreAppliedBeforeTheCollectionLoads(): void
    {
        $this->tool()->execute([
            'from' => '2026-07-27',
            'to' => '2026-07-30',
            'page' => 2,
            'page_size' => 25,
        ]);

        // America/New_York is UTC-4 in July, so the local day boundaries shift.
        $this->assertSame(
            [
                ['addFieldToFilter', 'main_table.updated_at', ['gteq' => '2026-07-27 04:00:00']],
                ['addFieldToFilter', 'main_table.updated_at', ['lteq' => '2026-07-31 03:59:59']],
                ['setCurPage', 2],
                ['setPageSize', 25],
                ['resolveCustomerNames'],
                ['getItems'],
            ],
            $this->calls
        );
    }

    public function testPagingIsAppliedBeforeTheLoadWithoutDateFilters(): void
    {
        $this->tool()->execute([]);

        $this->assertSame(
            [
                ['setCurPage', 1],
                ['setPageSize', AbstractLiveReportTool::DEFAULT_PAGE_SIZE],
                ['resolveCustomerNames'],
                ['getItems'],
            ],
            $this->calls
        );
    }

    public function testAppliesFromOnly(): void
    {
        $this->tool()->execute(['from' => '2026-07-27']);

        $this->assertSame(
            [['addFieldToFilter', 'main_table.updated_at', ['gteq' => '2026-07-27 04:00:00']]],
            $this->filterCalls()
        );
    }

    public function testAppliesToOnly(): void
    {
        $this->tool()->execute(['to' => '2026-07-30']);

        $this->assertSame(
            [['addFieldToFilter', 'main_table.updated_at', ['lteq' => '2026-07-31 03:59:59']]],
            $this->filterCalls()
        );
    }

    public function testOmittedDatesApplyNoDateFilter(): void
    {
        $this->tool()->execute([]);

        $this->assertSame([], $this->filterCalls());
    }

    public function testPageSizeIsCappedBeforeTheLoad(): void
    {
        $this->tool()->execute(['page_size' => 5000]);

        $this->assertSame(
            [
                ['setCurPage', 1],
                ['setPageSize', AbstractLiveReportTool::MAX_PAGE_SIZE],
                ['resolveCustomerNames'],
                ['getItems'],
            ],
            $this->calls
        );
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

    public function testRejectsNonIsoDateBeforeTheCollectionLoads(): void
    {
        try {
            $this->tool()->execute(['from' => '27-07-2026']);
            $this->fail('Expected a LocalizedException.');
        } catch (LocalizedException) {
            $this->assertSame([], $this->calls);
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function filterCalls(): array
    {
        return array_values(
            array_filter($this->calls, static fn (array $call): bool => $call[0] === 'addFieldToFilter')
        );
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
