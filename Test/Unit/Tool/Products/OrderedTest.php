<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Test\Unit\Tool\Products;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magebit\McpReportTools\Model\Support\RowSerializer;
use Magebit\McpReportTools\Tool\Products\Ordered;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Product\Sold\Collection as SoldCollection;
use Magento\Reports\Model\ResourceModel\Product\Sold\CollectionFactory as SoldCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderedTest extends TestCase
{
    /** @var TimezoneInterface&MockObject */
    private TimezoneInterface&MockObject $timezone;

    /** @var SoldCollection&MockObject */
    private SoldCollection&MockObject $collection;

    /** @var SoldCollectionFactory&MockObject */
    private SoldCollectionFactory&MockObject $collectionFactory;

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

        $this->collection = $this->createMock(SoldCollection::class);
        $this->collection->method('setDateRange')->willReturnSelf();
        $this->collection->method('setStoreIds')->willReturnSelf();
        $this->collection->method('setCurPage')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->collection->method('getItems')->willReturn([]);
        $this->collection->method('getSize')->willReturn(0);

        $this->collectionFactory = $this->createMock(SoldCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);
    }

    public function testKeepsIsoDatesIntact(): void
    {
        $this->collection->expects($this->once())
            ->method('setDateRange')
            ->with('2026-07-27 00:00:00', '2026-07-30 23:59:59');

        $payload = $this->payload($this->tool()->execute([
            'from' => '2026-07-27',
            'to' => '2026-07-30',
        ]));
        $this->assertSame('2026-07-27', $payload['from']);
        $this->assertSame('2026-07-30', $payload['to']);
    }

    public function testRejectsUsFormattedDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool()->execute(['from' => '07/27/2026', 'to' => '07/30/2026']);
    }

    public function testRejectsMissingDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool()->execute(['to' => '2026-07-30']);
    }

    public function testRejectsEmptyDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool()->execute(['from' => '', 'to' => '2026-07-30']);
    }

    private function tool(): Ordered
    {
        return new Ordered(
            $this->collectionFactory,
            new RowSerializer(),
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
