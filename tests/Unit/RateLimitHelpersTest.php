<?php

declare(strict_types=1);

namespace MeoDrive\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RateLimitHelpersTest extends TestCase
{
    public function testWindowIsNotExpiredJustAfterStart(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:00:00');

        self::assertFalse(isRateLimitWindowExpired('2026-07-20 12:00:00', 900, $now));
    }

    public function testWindowIsNotExpiredWithinLimit(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:10:00'); // 10 min later
        self::assertFalse(isRateLimitWindowExpired('2026-07-20 12:00:00', 900, $now)); // 15 min venster
    }

    public function testWindowIsExpiredAfterLimit(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:16:00'); // 16 min later
        self::assertTrue(isRateLimitWindowExpired('2026-07-20 12:00:00', 900, $now)); // 15 min venster
    }

    public function testWindowExactlyAtBoundaryIsExpired(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:15:00'); // exact 15 min later
        self::assertTrue(isRateLimitWindowExpired('2026-07-20 12:00:00', 900, $now));
    }

    public function testNextLockedUntilAddsSecondsToNow(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:00:00');

        self::assertSame('2026-07-20 12:15:00', nextLockedUntil(900, $now));
    }

    public function testNullLockedUntilMeansNotLockedOut(): void
    {
        self::assertFalse(isLockedOut(null));
        self::assertFalse(isLockedOut(''));
    }

    public function testFutureLockedUntilMeansLockedOut(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:00:00');
        self::assertTrue(isLockedOut('2026-07-20 12:05:00', $now));
    }

    public function testPastLockedUntilMeansNotLockedOutAnymore(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:10:00');
        self::assertFalse(isLockedOut('2026-07-20 12:05:00', $now));
    }

    public function testMinutesUntilRoundsUp(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:00:00');
        // 61 seconden resterend -> moet als 2 minuten worden getoond (nooit afronden naar "te weinig")
        self::assertSame(2, minutesUntil('2026-07-20 12:01:01', $now));
    }

    public function testMinutesUntilNeverNegative(): void
    {
        $now = new DateTimeImmutable('2026-07-20 12:10:00');
        self::assertSame(0, minutesUntil('2026-07-20 12:00:00', $now));
    }
}
