<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Service\Backoff;
use PHPUnit\Framework\TestCase;

class BackoffTest extends TestCase
{
    public function testDoublesPerAttemptUntilTheCap(): void
    {
        $backoff = new Backoff(30, 3600);

        self::assertSame(30, $backoff->delaySeconds(1, 1.0));
        self::assertSame(60, $backoff->delaySeconds(2, 1.0));
        self::assertSame(240, $backoff->delaySeconds(4, 1.0));
        self::assertSame(3600, $backoff->delaySeconds(8, 1.0));
        self::assertSame(3600, $backoff->delaySeconds(500, 1.0));
    }

    public function testJitterSpreadsOverTheUpperHalf(): void
    {
        $backoff = new Backoff(30, 3600);

        self::assertSame(60, $backoff->delaySeconds(3, 0.0));
        self::assertSame(90, $backoff->delaySeconds(3, 0.5));
        self::assertSame(120, $backoff->delaySeconds(3, 1.0));
    }

    public function testOutOfRangeInputIsClamped(): void
    {
        $backoff = new Backoff(30, 3600);

        self::assertSame(30, $backoff->delaySeconds(0, 5.0));
        self::assertSame(15, $backoff->delaySeconds(-3, -1.0));
    }
}
