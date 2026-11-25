<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\LessonProgress;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LessonProgress> */
class LessonProgressFactory extends Factory
{
    protected $model = LessonProgress::class;

    public function definition(): array
    {
        $key = 'lesson-' . Str::lower(Str::random(8));

        return [
            'enrollment_id' => Enrollment::factory(),
            'lesson_key' => $key,
            'lesson_group' => null,
            'lesson_title' => fake()->sentence(3),
            'lesson_url' => "https://learn.example.com/{$key}",
            'content_type' => 'video',
            'watched_ranges' => [],
            'video_duration' => 600,
            'watched_seconds' => 0,
            'last_position' => null,
            'active_seconds' => 0,
            'max_scroll_percent' => 0,
            'completion_percent' => 0,
            'last_heartbeat_at' => null,
            'completed_at' => null,
        ];
    }

    public function reading(): static
    {
        return $this->state(['content_type' => 'reading', 'video_duration' => null]);
    }
}
