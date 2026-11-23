<?php

namespace Tests\Unit;

use App\Services\SegmentMerger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SegmentMergerTest extends TestCase
{
    private SegmentMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new SegmentMerger(); // default 0.5s gap tolerance
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->merger->merge([]));
        $this->assertSame(0.0, $this->merger->totalSeconds([]));
    }

    /** @return array<string, array{0: array, 1: array}> */
    public static function mergeCases(): array
    {
        return [
            'disjoint stay separate' => [[[0, 10], [20, 30]], [[0, 10], [20, 30]]],
            'overlapping merge' => [[[0, 10], [5, 15]], [[0, 15]]],
            'adjacent (touching) merge' => [[[0, 10], [10, 20]], [[0, 20]]],
            'fully contained is absorbed' => [[[0, 100], [20, 30]], [[0, 100]]],
            'identical duplicates collapse' => [[[5, 10], [5, 10], [5, 10]], [[5, 10]]],
            'unsorted input is sorted' => [[[50, 60], [0, 10], [20, 30]], [[0, 10], [20, 30], [50, 60]]],
            'chain of overlaps merges transitively' => [[[0, 5], [4, 10], [9, 15]], [[0, 15]]],
            'late wide range bridges earlier ones' => [[[0, 5], [20, 25], [4, 21]], [[0, 25]]],
            'gap within tolerance is bridged' => [[[0, 10], [10.4, 20]], [[0, 20]]],
            'gap beyond tolerance stays separate' => [[[0, 10], [10.6, 20]], [[0, 10], [10.6, 20]]],
            'negative start is clamped to zero' => [[[-5, 10]], [[0, 10]]],
            'values are rounded to 2 decimals' => [[[0.004, 1.236]], [[0, 1.24]]],
        ];
    }

    #[DataProvider('mergeCases')]
    public function test_merge(array $input, array $expected): void
    {
        $this->assertEquals($expected, $this->merger->merge($input));
    }

    public function test_zero_tolerance_only_merges_touching_or_overlapping(): void
    {
        $strict = new SegmentMerger(0.0);

        $this->assertEquals([[0, 20]], $strict->merge([[0, 10], [10, 20]]));
        $this->assertEquals([[0, 10], [10.1, 20]], $strict->merge([[0, 10], [10.1, 20]]));
    }

    public function test_malformed_ranges_are_dropped(): void
    {
        $merged = $this->merger->merge([
            'not-an-array',
            [1],
            [1, 2, 3],
            ['a', 'b'],
            [10, 10],       // empty
            [20, 15],       // inverted
            [INF, 5],
            [0, 10],        // the only valid one
        ]);

        $this->assertEquals([[0, 10]], $merged);
    }

    public function test_ranges_are_clamped_to_duration(): void
    {
        $this->assertEquals([[0, 60]], $this->merger->merge([[0, 100]], 60.0));
        $this->assertSame([], $this->merger->merge([[70, 100]], 60.0)); // entirely past the end
    }

    public function test_add_merges_new_segment_into_existing_ranges(): void
    {
        $ranges = $this->merger->add([[0, 15], [30, 45]], 15, 30);

        $this->assertEquals([[0, 45]], $ranges);
    }

    public function test_add_does_not_mutate_input_and_handles_empty_existing(): void
    {
        $existing = [[0, 10]];
        $this->merger->add($existing, 20, 30);

        $this->assertSame([[0, 10]], $existing);
        $this->assertEquals([[5, 10]], $this->merger->add([], 5, 10));
    }

    public function test_total_seconds_sums_unique_coverage(): void
    {
        $this->assertEqualsWithDelta(30.0, $this->merger->totalSeconds([[0, 10], [20, 30], [40, 50]]), 0.001);
    }

    public function test_rewatching_the_same_seconds_never_inflates_total(): void
    {
        $ranges = [];
        for ($i = 0; $i < 20; $i++) {
            $ranges = $this->merger->add($ranges, 0, 15); // watch the same 15s twenty times
        }

        $this->assertEqualsWithDelta(15.0, $this->merger->totalSeconds($ranges), 0.001);
        $this->assertCount(1, $ranges);
    }

    public function test_total_seconds_respects_duration_clamp(): void
    {
        $this->assertEqualsWithDelta(60.0, $this->merger->totalSeconds([[0, 100]], 60.0), 0.001);
    }

    public function test_coverage_percent(): void
    {
        $this->assertEqualsWithDelta(25.0, $this->merger->coveragePercent([[0, 25]], 100.0), 0.001);
        $this->assertEqualsWithDelta(100.0, $this->merger->coveragePercent([[0, 500]], 100.0), 0.001); // capped
        $this->assertSame(0.0, $this->merger->coveragePercent([[0, 10]], 0.0));
    }
}
