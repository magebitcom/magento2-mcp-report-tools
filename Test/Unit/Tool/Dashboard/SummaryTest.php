<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Test\Unit\Tool\Dashboard;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\Dashboard\Summary;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Order\Collection as OrderReportCollection;
use Magento\Reports\Model\ResourceModel\Order\CollectionFactory as OrderReportCollectionFactory;
use Magento\Sales\Model\ResourceModel\Report\Bestsellers\CollectionFactory as BestsellersReportCollectionFactory;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory as SearchQueryCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SummaryTest extends TestCase
{
    /** @var TimezoneInterface&MockObject */
    private TimezoneInterface&MockObject $timezone;

    /** @var OrderReportCollection&MockObject */
    private OrderReportCollection&MockObject $orders;

    /** @var OrderReportCollectionFactory&MockObject */
    private OrderReportCollectionFactory&MockObject $ordersFactory;

    /** @var BestsellersReportCollectionFactory&MockObject */
    private BestsellersReportCollectionFactory&MockObject $bestsellersFactory;

    /** @var SearchQueryCollectionFactory&MockObject */
    private SearchQueryCollectionFactory&MockObject $searchTermsFactory;

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $filters = [];

    protected function setUp(): void
    {
        $this->filters = [];

        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn('America/New_York');
        // Faithful stand-in for Magento's locale-lenient Timezone::date(): an en_US
        // IntlDateFormatter SHORT parse, which reads "2026-07-27" as 2195-10-07.
        // A null argument means "now", exactly as Magento's Timezone::date() does.
        $this->timezone->method('date')->willReturnCallback(
            static function (?string $raw = null): \DateTime {
                if ($raw === null) {
                    return new \DateTime('now', new \DateTimeZone('UTC'));
                }
                $formatter = new \IntlDateFormatter(
                    'en_US',
                    \IntlDateFormatter::SHORT,
                    \IntlDateFormatter::NONE,
                    'UTC'
                );
                $timestamp = $formatter->parse($raw);
                if ($timestamp === false) {
                    throw new \Exception(sprintf('Unparseable date "%s".', $raw));
                }
                return (new \DateTime('now', new \DateTimeZone('UTC')))->setTimestamp((int) $timestamp);
            }
        );

        $this->orders = $this->createMock(OrderReportCollection::class);
        $this->orders->method('calculateTotals')->willReturnSelf();
        $this->orders->method('calculateSales')->willReturnSelf();
        $this->orders->method('load')->willReturnSelf();
        $this->orders->method('getFirstItem')->willReturn(new DataObject([]));
        $this->orders->method('addFieldToFilter')->willReturnCallback(
            function (string $field, mixed $condition): OrderReportCollection {
                $this->filters[] = [$field, $condition];
                return $this->orders;
            }
        );

        $this->ordersFactory = $this->createMock(OrderReportCollectionFactory::class);
        $this->ordersFactory->method('create')->willReturn($this->orders);
        $this->bestsellersFactory = $this->createMock(BestsellersReportCollectionFactory::class);
        $this->searchTermsFactory = $this->createMock(SearchQueryCollectionFactory::class);
    }

    public function testKeepsIsoPeriodBoundsIntact(): void
    {
        $payload = $this->payload($this->tool()->execute($this->args([
            'period_from' => '2026-07-27',
            'period_to' => '2026-07-30',
        ])));

        $period = $payload['period'];
        self::assertIsArray($period);
        $this->assertSame('2026-07-27', $period['from']);
        $this->assertSame('2026-07-30', $period['to']);
        $this->assertContains(['created_at', ['gteq' => '2026-07-27 00:00:00']], $this->filters);
        $this->assertContains(['created_at', ['lteq' => '2026-07-30 23:59:59']], $this->filters);
    }

    public function testRejectsUsFormattedPeriodBound(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool()->execute($this->args(['period_from' => '07/27/2026']));
    }

    public function testDefaultsToTrailingThirtyDaysWhenBoundsAbsent(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $payload = $this->payload($this->tool()->execute($this->args([])));

        $period = $payload['period'];
        self::assertIsArray($period);
        $this->assertSame($now->format('Y-m-d'), $period['to']);
        $this->assertSame($now->modify('-30 days')->format('Y-m-d'), $period['from']);
    }

    public function testTreatsEmptyPeriodBoundAsAbsent(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $payload = $this->payload($this->tool()->execute($this->args(['period_to' => ''])));

        $period = $payload['period'];
        self::assertIsArray($period);
        $this->assertSame($now->format('Y-m-d'), $period['to']);
    }

    /**
     * Zeroes the three list limits so only the date-driven totals paths run.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function args(array $args): array
    {
        return $args + [
            'recent_orders_limit' => 0,
            'top_search_terms_limit' => 0,
            'top_bestsellers_limit' => 0,
        ];
    }

    private function tool(): Summary
    {
        return new Summary(
            $this->ordersFactory,
            $this->bestsellersFactory,
            $this->searchTermsFactory,
            new RowSerializer(),
            $this->timezone,
            new DateArgReader($this->timezone)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $content = $result->getContent();
        self::assertNotEmpty($content);
        $text = $content[0]['text'] ?? '';
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
