<?php

namespace App\Services;

/**
 * Pure interval arithmetic for watched video ranges ([[start, end], ...] in seconds).
 * Re-watching the same seconds never inflates the total: overlapping ranges collapse.
 */
final class SegmentMerger
{
    /**
     * @param float $gapTolerance Ranges separated by <= this many seconds are bridged
     *                            (absorbs timeupdate granularity between heartbeats).
     */
    public function __construct(private readonly float $gapTolerance = 0.5)
    {
    }

    /**
     * Normalize and merge ranges: drops malformed/empty ranges, clamps to [0, $duration],
     * sorts, and merges overlapping or near-adjacent ranges.
     *
     * @param  array<int, mixed>  $ranges
     * @return array<int, array{0: float, 1: float}>
     */
    public function merge(array $ranges, ?float $duration = null): array
    {
        $clean = [];

        foreach ($ranges as $range) {
            if (! is_array($range) || count($range) !== 2) {
                continue;
            }

            [$start, $end] = array_values($range);

            if (! is_numeric($start) || ! is_numeric($end)) {
                continue;
            }

            $start = max(0.0, (float) $start);
            $end = (float) $end;

            if ($duration !== null) {
                $end = min($end, $duration);
            }

            if (! is_finite($start) || ! is_finite($end) || $end <= $start) {
                continue;
            }

            $clean[] = [round($start, 2), round($end, 2)];
        }

        usort($clean, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($clean as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1] + $this->gapTolerance) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * Add one newly watched segment to an existing set of ranges.
     *
     * @param  array<int, mixed>  $existing
     * @return array<int, array{0: float, 1: float}>
     */
    public function add(array $existing, float $start, float $end, ?float $duration = null): array
    {
        return $this->merge([...$existing, [$start, $end]], $duration);
    }

    /**
     * Total unique verified seconds covered by the ranges.
     *
     * @param array<int, mixed> $ranges
     */
    public function totalSeconds(array $ranges, ?float $duration = null): float
    {
        $total = 0.0;

        foreach ($this->merge($ranges, $duration) as [$start, $end]) {
            $total += $end - $start;
        }

        return round($total, 2);
    }

    /**
     * Percentage (0-100) of the video covered by the ranges.
     *
     * @param array<int, mixed> $ranges
     */
    public function coveragePercent(array $ranges, float $duration): float
    {
        if ($duration <= 0) {
            return 0.0;
        }

        return round(min(100.0, $this->totalSeconds($ranges, $duration) / $duration * 100), 2);
    }
}
