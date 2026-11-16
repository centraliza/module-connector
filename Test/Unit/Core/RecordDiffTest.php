<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Service\RecordDiff;
use PHPUnit\Framework\TestCase;

class RecordDiffTest extends TestCase
{
    public function testTheSameContentInDifferentTypesIsNotAChange(): void
    {
        $changed = (new RecordDiff())->changedFields(
            ['price' => 10, 'status' => '1', 'weight' => null, 'name' => 'Luva', 'flag' => false, 'cost' => 1.5, 'has_options' => false],
            ['price' => '10.000000', 'status' => 1, 'weight' => '', 'name' => 'Luva', 'flag' => '', 'cost' => '1.500000', 'has_options' => '0']
        );

        self::assertSame([], $changed);
    }

    public function testRealChangesAreNamed(): void
    {
        $changed = (new RecordDiff())->changedFields(
            ['price' => 12.9, 'name' => 'Outro', 'sku' => 'A', 'added' => 'x'],
            ['price' => '10.000000', 'name' => 'Luva', 'sku' => 'A', 'removed' => 'y']
        );

        self::assertSame(['price', 'name', 'added', 'removed'], $changed);
    }

    public function testIgnoredFieldsAndObjectsNeverCount(): void
    {
        $changed = (new RecordDiff())->changedFields(
            ['updated_at' => 'b', 'extension_attributes' => new \stdClass(), 'name' => 'A'],
            ['updated_at' => 'a', 'name' => 'A'],
            ['updated_at']
        );

        self::assertSame([], $changed);
    }

    public function testListsCompareByContentAndOnlyUnorderedOnesIgnoreOrder(): void
    {
        $diff = new RecordDiff();

        self::assertSame([], $diff->changedFields(['website_ids' => [2, '1']], ['website_ids' => ['1', '2']], [], ['website_ids']));
        self::assertSame(['website_ids'], $diff->changedFields(['website_ids' => [1, 2, 3]], ['website_ids' => [1, 2]], [], ['website_ids']));
        self::assertSame(['images'], $diff->changedFields(['images' => ['a', 'b']], ['images' => ['b', 'a']]));
        self::assertSame(
            [],
            $diff->changedFields(
                ['tier_price' => [['qty' => 5, 'price' => 9.5, 'group' => '1']]],
                ['tier_price' => [['price' => '9.500000', 'group' => 1, 'qty' => '5.0000']]]
            )
        );
        self::assertSame(
            ['tier_price'],
            $diff->changedFields(['tier_price' => [['qty' => 5, 'price' => 9]]], ['tier_price' => [['qty' => 5, 'price' => 9.5]]])
        );
    }
}
