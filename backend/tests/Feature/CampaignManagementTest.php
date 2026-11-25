<?php

namespace Tests\Feature;

use App\Enums\IntegrityEventType;
use App\Models\Campaign;
use App\Models\Enrollment;
use App\Models\IntegrityLog;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): User
    {
        return User::factory()->create(['role' => 'instructor']);
    }

    private function campaignFor(User $instructor, array $attributes = []): Campaign
    {
        return Campaign::factory()->create(['instructor_id' => $instructor->id] + $attributes);
    }

    /** @return array<int, string> sorted titles of the campaigns passed to the dashboard view */
    private function listed(TestResponse $response): array
    {
        return $response->viewData('campaigns')->pluck('title')->sort()->values()->all();
    }

    // ---------------------------------------------------------------- delete

    public function test_instructor_can_delete_own_campaign_and_all_child_records(): void
    {
        $instructor = $this->instructor();
        $campaign = $this->campaignFor($instructor);

        $enrollments = Enrollment::factory()->count(2)->create(['campaign_id' => $campaign->id]);
        $lessonIds = [];
        foreach ($enrollments as $enrollment) {
            $lesson = LessonProgress::create([
                'enrollment_id' => $enrollment->id,
                'lesson_key' => 'module-1',
                'lesson_title' => 'Module 1',
            ]);
            $lessonIds[] = $lesson->id;

            // One log tied to a lesson, one not.
            IntegrityLog::create([
                'enrollment_id' => $enrollment->id,
                'lesson_progress_id' => $lesson->id,
                'event_type' => IntegrityEventType::cases()[0],
                'points_deducted' => 5,
                'occurred_at' => now(),
            ]);
            IntegrityLog::create([
                'enrollment_id' => $enrollment->id,
                'event_type' => IntegrityEventType::cases()[0],
                'points_deducted' => 0,
                'occurred_at' => now(),
            ]);
        }

        // Unrelated campaign (same instructor) must be left alone.
        $other = $this->campaignFor($instructor);
        $otherEnrollment = Enrollment::factory()->create(['campaign_id' => $other->id]);
        $otherLesson = LessonProgress::create(['enrollment_id' => $otherEnrollment->id, 'lesson_key' => 'x']);
        IntegrityLog::create([
            'enrollment_id' => $otherEnrollment->id,
            'event_type' => IntegrityEventType::cases()[0],
            'points_deducted' => 1,
            'occurred_at' => now(),
        ]);

        $this->actingAs($instructor)
            ->delete(route('campaigns.destroy', $campaign))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertModelMissing($campaign);
        foreach ($enrollments as $enrollment) {
            $this->assertModelMissing($enrollment);
            $this->assertDatabaseMissing('lesson_progress', ['enrollment_id' => $enrollment->id]);
            $this->assertDatabaseMissing('integrity_logs', ['enrollment_id' => $enrollment->id]);
            $this->assertModelExists($enrollment->student); // students themselves are kept
        }

        $this->assertModelExists($other);
        $this->assertModelExists($otherEnrollment);
        $this->assertModelExists($otherLesson);
        $this->assertDatabaseCount('integrity_logs', 1);
    }

    public function test_another_instructor_cannot_delete_a_campaign(): void
    {
        $owner = $this->instructor();
        $intruder = $this->instructor();
        $campaign = $this->campaignFor($owner);
        $enrollment = Enrollment::factory()->create(['campaign_id' => $campaign->id]);

        $this->actingAs($intruder)
            ->delete(route('campaigns.destroy', $campaign))
            ->assertForbidden();

        $this->assertModelExists($campaign);
        $this->assertModelExists($enrollment);
    }

    public function test_guest_cannot_delete_a_campaign(): void
    {
        $campaign = $this->campaignFor($this->instructor());

        $this->delete(route('campaigns.destroy', $campaign))->assertRedirect(route('login'));

        $this->assertModelExists($campaign);
    }

    public function test_delete_controls_are_rendered_for_the_owner(): void
    {
        $instructor = $this->instructor();
        $campaign = $this->campaignFor($instructor);

        $this->actingAs($instructor)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('campaigns.destroy', $campaign), false);

        $this->actingAs($instructor)->get(route('campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('Delete campaign');
    }

    // ---------------------------------------------------------------- search

    public function test_search_matches_title_platform_and_join_code(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Alpha Networking', 'platform_name' => 'Fortinet', 'join_code' => 'AAAA1111']);
        $this->campaignFor($instructor, ['title' => 'Beta Security', 'platform_name' => 'Coursera', 'join_code' => 'BBBB2222']);
        $this->campaignFor($instructor, ['title' => 'Gamma Cloud', 'platform_name' => 'Udemy', 'join_code' => 'CCCC3333']);

        $this->actingAs($instructor);

        $this->assertSame(['Alpha Networking'], $this->listed($this->get(route('dashboard', ['q' => 'networking']))));
        $this->assertSame(['Beta Security'], $this->listed($this->get(route('dashboard', ['q' => 'coursera']))));
        // Join codes are matched case-insensitively.
        $this->assertSame(['Gamma Cloud'], $this->listed($this->get(route('dashboard', ['q' => 'cccc33']))));
        $this->assertSame([], $this->listed($this->get(route('dashboard', ['q' => 'nothing-like-this']))));
    }

    public function test_search_never_returns_other_instructors_campaigns(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Mine Shared Term']);
        $this->campaignFor($this->instructor(), ['title' => 'Theirs Shared Term']);

        $response = $this->actingAs($instructor)->get(route('dashboard', ['q' => 'Shared Term']));

        $this->assertSame(['Mine Shared Term'], $this->listed($response));
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Ready 100% Course']);
        $this->campaignFor($instructor, ['title' => 'Plain Course']);

        $response = $this->actingAs($instructor)->get(route('dashboard', ['q' => '%']));

        $this->assertSame(['Ready 100% Course'], $this->listed($response));
    }

    public function test_malformed_query_values_do_not_break_the_dashboard(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Only One']);

        $this->actingAs($instructor)
            ->get(route('dashboard', ['q' => ['x'], 'status' => ['y']]))
            ->assertOk();
    }

    // ---------------------------------------------------------------- status filter

    public function test_status_filter_splits_open_and_closed_campaigns(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Open One']);
        $this->campaignFor($instructor, ['title' => 'Open Two']);
        $this->campaignFor($instructor, ['title' => 'Closed One', 'status' => 'closed']);
        $this->campaignFor($this->instructor(), ['title' => 'Someone Elses Closed', 'status' => 'closed']);

        $this->actingAs($instructor);

        $this->assertSame(['Closed One', 'Open One', 'Open Two'], $this->listed($this->get(route('dashboard'))));
        $this->assertSame(['Open One', 'Open Two'], $this->listed($this->get(route('dashboard', ['status' => 'open']))));
        $this->assertSame(['Closed One'], $this->listed($this->get(route('dashboard', ['status' => 'closed']))));
    }

    public function test_unknown_status_value_falls_back_to_all(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Open One']);
        $this->campaignFor($instructor, ['title' => 'Closed One', 'status' => 'closed']);

        $response = $this->actingAs($instructor)->get(route('dashboard', ['status' => 'bogus']));

        $this->assertSame(['Closed One', 'Open One'], $this->listed($response));
    }

    public function test_search_and_status_filter_combine_and_carry_over(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Alpha Open']);
        $this->campaignFor($instructor, ['title' => 'Alpha Closed', 'status' => 'closed']);
        $this->campaignFor($instructor, ['title' => 'Beta Closed', 'status' => 'closed']);

        $response = $this->actingAs($instructor)
            ->get(route('dashboard', ['q' => 'alpha', 'status' => 'closed']));

        $this->assertSame(['Alpha Closed'], $this->listed($response));

        // The search box keeps the status filter, and the tabs keep the search term.
        $response->assertSee('<input type="hidden" name="status" value="closed">', false)
            ->assertSee('value="alpha"', false)
            ->assertSee('q=alpha', false);
    }

    public function test_empty_filtered_result_shows_no_match_message(): void
    {
        $instructor = $this->instructor();
        $this->campaignFor($instructor, ['title' => 'Alpha']);

        $this->actingAs($instructor)
            ->get(route('dashboard', ['q' => 'zzz']))
            ->assertOk()
            ->assertSee('No campaigns match');
    }
}
