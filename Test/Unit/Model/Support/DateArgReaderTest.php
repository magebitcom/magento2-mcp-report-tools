<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpReportTools\Test\Unit\Model\Support;

use Magebit\McpReportTools\Model\Support\DateArgReader;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DateArgReaderTest extends TestCase
{
    /** @var TimezoneInterface&MockObject */
    private TimezoneInterface&MockObject $timezone;

    private DateArgReader $reader;

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn('America/New_York');
        $this->reader = new DateArgReader($this->timezone);
    }

    public function testAcceptsIsoDate(): void
    {
        self::assertSame('2026-07-27', $this->reader->required(['from' => '2026-07-27'], 'from'));
    }

    public function testRejectsUsFormatLoudly(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->required(['from' => '07/27/2026'], 'from');
    }

    public function testRejectsIsoLookalikeWithBadDay(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->required(['from' => '2026-02-30'], 'from');
    }

    public function testRequiredRejectsMissingKey(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->required([], 'from');
    }

    public function testRequiredRejectsNonStringValue(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->required(['from' => 20260727], 'from');
    }

    public function testOptionalReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->reader->optional([], 'from'));
    }

    public function testOptionalReturnsNullWhenEmptyString(): void
    {
        self::assertNull($this->reader->optional(['from' => ''], 'from'));
    }

    public function testOptionalAcceptsIsoDate(): void
    {
        self::assertSame('2026-07-27', $this->reader->optional(['from' => '2026-07-27'], 'from'));
    }

    public function testOptionalRejectsNonStringValue(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->optional(['from' => ['2026-07-27']], 'from');
    }

    public function testOptionalRejectsNonIsoDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->optional(['from' => '27.07.2026'], 'from');
    }

    public function testUtcBoundaryShiftsStartOfDayFromStoreTimezone(): void
    {
        self::assertSame('2026-07-27 04:00:00', $this->reader->utcBoundary('2026-07-27', false));
    }

    public function testUtcBoundaryShiftsEndOfDayFromStoreTimezone(): void
    {
        self::assertSame('2026-07-28 03:59:59', $this->reader->utcBoundary('2026-07-27', true));
    }
}
