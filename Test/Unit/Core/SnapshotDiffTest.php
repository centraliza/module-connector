<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Service\SnapshotDiff;
use PHPUnit\Framework\TestCase;

class SnapshotDiffTest extends TestCase
{
    public function testReportsOnlyWhatChanged(): void
    {
        $verbs = (new SnapshotDiff())->verbs(
            ['same' => [1.0, 1], 'changed' => [1.0, 1], 'gone' => [2.0, 1]],
            ['same' => [1.0, 1], 'changed' => [3.0, 1], 'new' => [0.0, 0]]
        );

        self::assertSame(
            ['changed' => EventVerb::Updated, 'new' => EventVerb::Created, 'gone' => EventVerb::Deleted],
            $verbs
        );
    }

    public function testAStoredNullIsARecordThatExists(): void
    {
        self::assertSame([], (new SnapshotDiff())->verbs(['a' => null], ['a' => null]));
    }

    public function testNothingBeforeAndNothingAfterIsNothing(): void
    {
        self::assertSame([], (new SnapshotDiff())->verbs([], []));
    }
}
