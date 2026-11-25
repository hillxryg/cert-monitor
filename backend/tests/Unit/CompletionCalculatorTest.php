<?php

namespace Tests\Unit;

use App\Services\CompletionCalculator;
use PHPUnit\Framework\TestCase;

class CompletionCalculatorTest extends TestCase
{
    private CompletionCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CompletionCalculator();
    }

    /** @return array{group: string, completion: float, duration: float} */
    private function item(string $group, float $completion, float $duration = 0.0): array
    {
        return ['group' => $group, 'completion' => $completion, 'duration' => $duration];
    }

    public function test_no_items_is_zero(): void
    {
        $this->assertSame(0.0, $this->calculator->overall([], null));
        $this->assertSame(0.0, $this->calculator->overall([], 12));
    }

    public function test_items_without_a_shared_group_average_like_before(): void
    {
        // Each item is its own module (the pre-SCORM behaviour): plain average.
        $overall = $this->calculator->overall([$this->item('a', 50), $this->item('b', 100)], null);

        $this->assertEqualsWithDelta(75.0, $overall, 0.001);
    }

    public function test_items_in_one_module_are_weighted_by_duration(): void
    {
        // A 10s slide fully heard + a 30s slide not heard at all = 25% of the module.
        $modules = $this->calculator->groupCompletions([
            $this->item('m1', 100, 10),
            $this->item('m1', 0, 30),
        ]);

        $this->assertEqualsWithDelta(25.0, $modules['m1'], 0.001);
    }

    public function test_module_without_durations_uses_the_plain_average(): void
    {
        $modules = $this->calculator->groupCompletions([$this->item('page', 40), $this->item('page', 60)]);

        $this->assertEqualsWithDelta(50.0, $modules['page'], 0.001);
    }

    public function test_expected_lessons_is_the_denominator_when_larger_than_modules_started(): void
    {
        // One of twelve modules fully verified.
        $overall = $this->calculator->overall([$this->item('m1', 100, 60)], 12);

        $this->assertEqualsWithDelta(8.33, $overall, 0.001);
    }

    public function test_expected_lessons_smaller_than_modules_started_never_pushes_past_100(): void
    {
        $overall = $this->calculator->overall(
            [$this->item('m1', 100, 10), $this->item('m2', 100, 10), $this->item('m3', 100, 10)],
            2,
        );

        $this->assertEqualsWithDelta(100.0, $overall, 0.001);
    }

    public function test_many_slides_in_one_module_still_count_as_one_module(): void
    {
        $slides = [];
        for ($i = 0; $i < 30; $i++) {
            $slides[] = $this->item('module-1', 100, 20);
        }

        // 30 fully heard slides = ONE finished module out of 12, not 30 finished lessons.
        $this->assertEqualsWithDelta(8.33, $this->calculator->overall($slides, 12), 0.001);
    }

    public function test_out_of_range_completion_is_clamped(): void
    {
        $modules = $this->calculator->groupCompletions([$this->item('a', 150, 10), $this->item('b', -20, 10)]);

        $this->assertEqualsWithDelta(100.0, $modules['a'], 0.001);
        $this->assertEqualsWithDelta(0.0, $modules['b'], 0.001);
    }
}
