<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentIndexTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/enrollments';

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->state(['role' => 'student'])->create();
        $this->withToken($this->student->createToken('test')->plainTextToken);
    }

    private function enroll(Campaign $campaign, ?User $student = null): Enrollment
    {
        return Enrollment::factory()->create([
            'campaign_id' => $campaign->id,
            'student_id' => ($student ?? $this->student)->id,
        ]);
    }

    /** @return array<int, int> sorted campaign ids in the response */
    private function campaignIds($response): array
    {
        $ids = collect($response->json('data'))->pluck('campaign.id')->sort()->values()->all();

        return array_map('intval', $ids);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_returns_an_empty_list_when_the_student_has_no_enrollments(): void
    {
        $this->getJson(self::URL)
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_lists_only_the_authenticated_students_enrollments(): void
    {
        $mine = Campaign::factory()->count(2)->create();
        $theirs = Campaign::factory()->create();

        foreach ($mine as $campaign) {
            $this->enroll($campaign);
        }
        $this->enroll($theirs, User::factory()->state(['role' => 'student'])->create());

        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame($mine->pluck('id')->sort()->values()->all(), $this->campaignIds($response));
    }

    public function test_returns_campaign_details_and_rules(): void
    {
        $campaign = Campaign::factory()->create([
            'title' => 'Cloud Basics',
            'platform_name' => 'ExampleLearn',
            'target_url_pattern' => 'https://learn.example.com/cloud/*',
            'max_playback_speed' => 1.5,
            'allow_muted_playback' => true,
            'max_skip_seconds' => 20,
            'required_completion_percent' => 80,
            'min_integrity_score' => 60,
        ]);
        $enrollment = $this->enroll($campaign);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'enrollment_id',
                        'joined_at',
                        'campaign' => [
                            'id', 'title', 'platform_name', 'target_url_pattern',
                            'rules' => [
                                'max_playback_speed', 'allow_muted_playback', 'max_skip_seconds',
                                'required_completion_percent', 'min_integrity_score', 'heartbeat_interval_seconds',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.0.enrollment_id', $enrollment->id)
            ->assertJsonPath('data.0.campaign.id', $campaign->id)
            ->assertJsonPath('data.0.campaign.title', 'Cloud Basics')
            ->assertJsonPath('data.0.campaign.target_url_pattern', 'https://learn.example.com/cloud/*')
            ->assertJsonPath('data.0.campaign.rules.max_playback_speed', 1.5)
            ->assertJsonPath('data.0.campaign.rules.allow_muted_playback', true)
            ->assertJsonPath('data.0.campaign.rules.max_skip_seconds', 20)
            ->assertJsonPath('data.0.campaign.rules.required_completion_percent', 80)
            ->assertJsonPath('data.0.campaign.rules.min_integrity_score', 60);
    }

    public function test_reflects_instructor_edits_to_the_campaign(): void
    {
        $campaign = Campaign::factory()->create(['target_url_pattern' => 'https://old.example.com/*']);
        $this->enroll($campaign);

        $this->getJson(self::URL)->assertJsonPath('data.0.campaign.target_url_pattern', 'https://old.example.com/*');

        $campaign->forceFill([
            'target_url_pattern' => 'https://new.example.com/*',
            'max_playback_speed' => 1.0,
            'allow_muted_playback' => true,
        ])->save();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.campaign.target_url_pattern', 'https://new.example.com/*')
            ->assertJsonPath('data.0.campaign.rules.max_playback_speed', 1)
            ->assertJsonPath('data.0.campaign.rules.allow_muted_playback', true);
    }

    public function test_excludes_closed_campaigns(): void
    {
        $open = Campaign::factory()->create();
        $closed = Campaign::factory()->closed()->create();
        $this->enroll($open);
        $this->enroll($closed);

        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame([$open->id], $this->campaignIds($response));
    }

    public function test_a_reopened_campaign_reappears(): void
    {
        $campaign = Campaign::factory()->closed()->create();
        $this->enroll($campaign);

        $this->getJson(self::URL)->assertJsonCount(0, 'data');

        $campaign->forceFill(['status' => 'active'])->save();

        $this->getJson(self::URL)->assertJsonCount(1, 'data');
    }

    public function test_respects_the_campaign_date_window(): void
    {
        $current = Campaign::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $notStarted = Campaign::factory()->create(['starts_at' => now()->addDay()]);
        $ended = Campaign::factory()->create(['ends_at' => now()->subDay()]);

        foreach ([$current, $notStarted, $ended] as $campaign) {
            $this->enroll($campaign);
        }

        $response = $this->getJson(self::URL)->assertOk();

        $this->assertSame([$current->id], $this->campaignIds($response));
    }

    public function test_uses_the_same_shape_as_the_join_response(): void
    {
        $campaign = Campaign::factory()->create();

        $join = $this->postJson('/api/v1/enrollments/join', ['join_code' => $campaign->join_code])->assertCreated();
        $index = $this->getJson(self::URL)->assertOk();

        // The extension maps both responses with the same code, so they must not drift apart.
        $this->assertEquals($join->json('data'), $index->json('data.0'));
    }
}
