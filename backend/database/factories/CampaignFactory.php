<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'instructor_id' => User::factory()->state(['role' => 'instructor']),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'platform_name' => 'ExampleLearn',
            'target_url_pattern' => 'https://learn.example.com/*',
            'join_code' => strtoupper(Str::random(8)),
            'required_completion_percent' => 90,
            'min_integrity_score' => 70,
            'max_playback_speed' => 1.25,
            'allow_muted_playback' => false,
            'max_skip_seconds' => 10,
            'status' => 'active',
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }

    public function allowingMutedPlayback(): static
    {
        return $this->state(['allow_muted_playback' => true]);
    }
}
