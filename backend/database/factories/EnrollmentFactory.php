<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'student_id' => User::factory()->state(['role' => 'student']),
            'integrity_score' => 100,
            'completion_percent' => 0,
            'joined_at' => now(),
            'last_activity_at' => null,
            'completed_at' => null,
        ];
    }
}
