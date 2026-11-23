<?php

namespace App\Support;

final class Timecode
{
    /** 75 -> "1:15", 3725 -> "1:02:05" */
    public static function clock(float|int|string|null $seconds): string
    {
        $total = (int) max(0, round((float) $seconds));
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
