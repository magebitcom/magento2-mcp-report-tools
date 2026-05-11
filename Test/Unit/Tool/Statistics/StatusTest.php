<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Test\Unit\Tool\Statistics;

use Magebit\McpReportTools\Model\AggregationRegistry;
use Magebit\McpReportTools\Tool\Statistics\Status;
use Magento\Reports\Model\Flag;
use Magento\Reports\Model\FlagFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StatusTest extends TestCase
{
    /** @var AggregationRegistry&MockObject */
    private AggregationRegistry&MockObject $registry;

    /** @var FlagFactory&MockObject */
    private FlagFactory&MockObject $flagFactory;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(AggregationRegistry::class);
        $this->flagFactory = $this->createMock(FlagFactory::class);
    }

    public function testReturnsRefreshedRowWhenFlagLoads(): void
    {
        $this->registry->method('getCodes')->willReturn(['sales']);
        $this->registry->method('getFlagCode')->with('sales')->willReturn('report_flag_sales');
        $this->registry->method('getResourceClass')->with('sales')->willReturn('Foo\\Sales');

        $flag = $this->buildFlag(id: 42, lastUpdate: '2026-05-11 10:00:00', state: 2);
        $this->flagFactory->method('create')->willReturn($flag);

        $items = $this->extractItems((new Status($this->registry, $this->flagFactory))->execute([]));
        $this->assertCount(1, $items);
        $this->assertSame(true, $items[0]['refreshed']);
        $this->assertSame('2026-05-11 10:00:00', $items[0]['last_update']);
        $this->assertSame(2, $items[0]['state']);
    }

    public function testGracefullyDegradesWhenLoadSelfThrows(): void
    {
        $this->registry->method('getCodes')->willReturn(['sales', 'tax']);
        $this->registry->method('getFlagCode')->willReturnCallback(
            static fn (string $code): string => 'flag_' . $code
        );
        $this->registry->method('getResourceClass')->willReturn('Foo\\X');

        $brokenFlag = $this->buildFlag(throwingLoadSelf: new RuntimeException('db dead'));
        $goodFlag = $this->buildFlag(id: 7, lastUpdate: '2026-05-10 09:00:00', state: 2);

        $this->flagFactory->method('create')
            ->willReturnOnConsecutiveCalls($brokenFlag, $goodFlag);

        $items = $this->extractItems((new Status($this->registry, $this->flagFactory))->execute([]));

        $this->assertCount(2, $items);
        $this->assertSame(false, $items[0]['refreshed']);
        $this->assertSame('db dead', $items[0]['error']);
        $this->assertSame('sales', $items[0]['code']);

        $this->assertSame(true, $items[1]['refreshed']);
        $this->assertSame('2026-05-10 09:00:00', $items[1]['last_update']);
        $this->assertSame('tax', $items[1]['code']);
    }

    public function testReturnsUnrefreshedRowWhenNoFlagCodeRegistered(): void
    {
        $this->registry->method('getCodes')->willReturn(['custom']);
        $this->registry->method('getFlagCode')->with('custom')->willReturn(null);
        $this->registry->method('getResourceClass')->with('custom')->willReturn('Foo\\Custom');

        $this->flagFactory->expects($this->never())->method('create');

        $items = $this->extractItems((new Status($this->registry, $this->flagFactory))->execute([]));
        $this->assertCount(1, $items);
        $this->assertSame(false, $items[0]['refreshed']);
        $this->assertNull($items[0]['last_update']);
        $this->assertNull($items[0]['state']);
    }

    private function buildFlag(
        ?int $id = null,
        ?string $lastUpdate = null,
        ?int $state = null,
        ?\Throwable $throwingLoadSelf = null
    ): Flag {
        // Flag exposes getLastUpdate/getState only via __call → addMethods()
        // is the only way to mock them with strict_types on.
        $flag = $this->getMockBuilder(Flag::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setReportFlagCode', 'loadSelf', 'getId'])
            ->addMethods(['getLastUpdate', 'getState'])
            ->getMock();
        $flag->method('setReportFlagCode')->willReturnSelf();
        if ($throwingLoadSelf !== null) {
            $flag->method('loadSelf')->willThrowException($throwingLoadSelf);
        } else {
            $flag->method('loadSelf')->willReturnSelf();
        }
        $flag->method('getId')->willReturn($id);
        $flag->method('getLastUpdate')->willReturn($lastUpdate);
        $flag->method('getState')->willReturn($state);
        return $flag;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(\Magebit\Mcp\Api\ToolResultInterface $result): array
    {
        $content = $result->getContent();
        self::assertIsArray($content);
        self::assertNotEmpty($content);
        $first = $content[0];
        self::assertIsArray($first);
        $text = $first['text'] ?? '';
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        $items = $decoded['items'] ?? null;
        self::assertIsArray($items);
        /** @var array<int, array<string, mixed>> $items */
        return $items;
    }
}
