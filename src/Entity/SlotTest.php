<?php

namespace App\Entity;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class SlotTest extends TestCase
{
    /**
     * Tests that a slot is considered stale some time after creation
     * @dataProvider isStaleProvider
     * @param string $createAt
     * @param string $now
     * @param bool $expectedResult
     */
    public function testIsStale(string $createAt, string $now, bool $expectedResult)
    {
        Carbon::setTestNow($createAt);

        $slot = new Slot(
            doctorId: 1,
            start: new \DateTime('2022-01-01 12:00:00'),
            end: new \DateTime('2022-01-01 12:30:00')
        );

        Carbon::setTestNow($now);

        $actualResult = $slot->isStale();

        self::assertEquals($expectedResult, $actualResult);
    }

    public function isStaleProvider()
    {
        return [
            ['2022-01-01 12:00:00', '2022-01-01 12:02:00', false], // Slot created less than 5 min ago
            ['2022-01-01 12:00:00', '2022-01-01 12:05:00', false], // Slot created exactly 5 min ago
            ['2022-01-01 12:00:00', '2022-01-01 12:06:00', true], // Slot create over 5 min ago
        ];
    }
}
