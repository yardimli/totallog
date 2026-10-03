<?php

namespace App\Support;

use Carbon\CarbonInterface;

class DayTime
{
    public const PATTERN = '/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/D';
    public const RULE = 'regex:'.self::PATTERN;

    public static function clock(CarbonInterface $time, CarbonInterface $day): string
    {
        return $time->equalTo($day->copy()->startOfDay()->addDay())
            ? '24:00'
            : $time->format('H:i');
    }
}
