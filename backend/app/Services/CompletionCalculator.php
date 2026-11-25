<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\LessonProgress;

/**
 * Rolls tracked items up into modules, and modules up into an overall completion %.
 *
 *  - Item:   one lesson_progress row (a video, a narrated slide, a reading page).
 *  - Module: items sharing a lesson_group (items without one are their own module).
 *  - Module completion = verified seconds / total seconds of its items (duration-weighted),
 *    or the plain average when no item has a duration (reading pages).
 *  - Overall = sum of module completions / max(modules started, campaign.expected_lessons),
 *    so a 12-module certification where 3 modules are done reads 25%, not 100%.
 *
 * Limit: a module's total is the sum of the items the student has actually opened. Slides
 * never opened aren't in the denominator until the campaign knows the module's real size.
 */
class CompletionCalculator
{
    /**
     * @param  iterable<array{group: string, completion: float, duration: float}>  $items
     */
    public function overall(iterable $items, ?int $expectedLessons): float
    {
        $modules = $this->groupCompletions($items);

        if ($modules === []) {
            return 0.0;
        }

        $denominator = max(count($modules), $expectedLessons ?? 0);

        return round(array_sum($modules) / $denominator, 2);
    }

    /**
     * @param  iterable<array{group: string, completion: float, duration: float}>  $items
     * @return array<string, float> module key => completion 0..100
     */
    public function groupCompletions(iterable $items): array
    {
        $totals = [];

        foreach ($items as $item) {
            $group = (string) $item['group'];
            $completion = min(100.0, max(0.0, (float) $item['completion']));
            $duration = max(0.0, (float) $item['duration']);

            $totals[$group] ??= ['weighted' => 0.0, 'duration' => 0.0, 'sum' => 0.0, 'count' => 0];
            $totals[$group]['weighted'] += $completion * $duration;
            $totals[$group]['duration'] += $duration;
            $totals[$group]['sum'] += $completion;
            $totals[$group]['count']++;
        }

        $modules = [];
        foreach ($totals as $group => $t) {
            $modules[$group] = $t['duration'] > 0
                ? $t['weighted'] / $t['duration']
                : $t['sum'] / $t['count'];
        }

        return $modules;
    }

    public function forEnrollment(Enrollment $enrollment): float
    {
        $enrollment->loadMissing('campaign');

        $items = LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->get(['lesson_key', 'lesson_group', 'completion_percent', 'video_duration'])
            ->map(fn(LessonProgress $row): array => [
                'group' => $row->lesson_group ?? $row->lesson_key,
                'completion' => (float) $row->completion_percent,
                'duration' => (float) $row->video_duration,
            ]);

        return $this->overall($items, $enrollment->campaign->expected_lessons);
    }
}
