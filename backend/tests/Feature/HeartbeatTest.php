<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/heartbeats';
    private const LESSON = 'lesson-1';

    private Carbon $base;
    private Campaign $campaign;
    private User $student;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, whole-second clock so elapsed time between heartbeats is exact.
        Carbon::setTestNow($this->base = Carbon::create(2026, 11, 24, 12, 0, 0));

        $this->campaign = Campaign::factory()->create(); // 1.25x max, muted disallowed, 10s max skip
        $this->student = User::factory()->state(['role' => 'student'])->create();
        $this->enrollment = Enrollment::factory()->create([
            'campaign_id' => $this->campaign->id,
            'student_id' => $this->student->id,
        ]);

        // Real bearer token so the request goes through the actual Sanctum guard.
        $this->withToken($this->student->createToken('test')->plainTextToken);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** Video heartbeat for the 15s of media starting at $offset (offset seconds after the base time). */
    private function payload(int $offset, array $overrides = []): array
    {
        return array_merge([
            'campaign_id' => $this->campaign->id,
            'client_timestamp' => $this->base->copy()->addSeconds($offset)->getTimestampMs(),
            'lesson_key' => self::LESSON,
            'lesson_title' => 'Intro',
            'lesson_url' => 'https://learn.example.com/lesson-1',
            'content_type' => 'video',
            'tab_focused' => true,
            'tab_visible' => true,
            'segment_start' => $offset,
            'segment_end' => $offset + 15,
            'current_time' => $offset + 15,
            'duration' => 600,
            'playback_rate' => 1.0,
            'muted' => false,
            'is_playing' => true,
        ], $overrides);
    }

    /** Move the server clock to base+offset and post a heartbeat stamped at that moment. */
    private function send(int $offset, array $overrides = []): TestResponse
    {
        Carbon::setTestNow($this->base->copy()->addSeconds($offset));

        return $this->postJson(self::URL, $this->payload($offset, $overrides));
    }

    private function progress(): LessonProgress
    {
        return LessonProgress::where('enrollment_id', $this->enrollment->id)
            ->where('lesson_key', self::LESSON)
            ->firstOrFail();
    }

    private function integrityScore(): int
    {
        return (int) $this->enrollment->fresh()->integrity_score;
    }

    private function logsOfType(string $type)
    {
        return $this->enrollment->integrityLogs()->where('event_type', $type);
    }

    private function updateCampaign(array $attributes): void
    {
        $this->campaign->forceFill($attributes)->save();
    }

    // ----------------------------------------------------------- normal flow

    public function test_normal_playback_credits_verified_seconds(): void
    {
        $first = $this->send(0)->assertOk();
        $second = $this->send(15)->assertOk();

        $this->assertTrue($first->json('accepted'));
        $this->assertEqualsWithDelta(15.0, $first->json('credited_seconds'), 0.001);
        $this->assertEqualsWithDelta(15.0, $second->json('credited_seconds'), 0.001);
        $this->assertSame([], $second->json('flags'));

        $progress = $this->progress();
        $this->assertEqualsWithDelta(30.0, (float) $progress->watched_seconds, 0.001);
        $this->assertEquals([[0, 30]], $progress->watched_ranges);
        $this->assertEqualsWithDelta(5.0, (float) $progress->completion_percent, 0.001); // 30 / 600

        $this->assertSame(0, $this->enrollment->integrityLogs()->count());
        $this->assertSame(100, $this->integrityScore());
        $this->assertEqualsWithDelta(5.0, (float) $this->enrollment->fresh()->completion_percent, 0.001);
    }

    public function test_rewatching_does_not_double_count(): void
    {
        $this->send(0);
        $this->send(15, ['segment_start' => 0, 'segment_end' => 15, 'current_time' => 15]); // rewind and rewatch

        $this->assertEqualsWithDelta(15.0, (float) $this->progress()->watched_seconds, 0.001);
    }

    public function test_lesson_is_completed_when_required_percent_is_reached(): void
    {
        $this->updateCampaign(['required_completion_percent' => 5]);

        $this->send(0);
        $response = $this->send(15);

        $this->assertTrue($response->json('lesson.completed'));
        $this->assertNotNull($this->progress()->completed_at);
    }

    // ------------------------------------------------------ rule violations

    public function test_speed_violation_is_logged_and_credit_discarded(): void
    {
        $response = $this->send(0, ['playback_rate' => 2.0, 'segment_end' => 30, 'current_time' => 30])->assertOk();

        $this->assertSame(['speed_violation'], $response->json('flags'));
        $this->assertEqualsWithDelta(0.0, $response->json('credited_seconds'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->progress()->watched_seconds, 0.001);

        $log = $this->logsOfType('speed_violation')->firstOrFail();
        $this->assertSame(5, $log->points_deducted);
        $this->assertEquals(2.0, $log->meta['playback_rate']);
        $this->assertSame(95, $this->integrityScore());
    }

    public function test_playback_at_exactly_the_max_speed_is_allowed(): void
    {
        $response = $this->send(0, ['playback_rate' => 1.25, 'segment_end' => 18, 'current_time' => 18])->assertOk();

        $this->assertSame([], $response->json('flags'));
        $this->assertGreaterThan(0, $response->json('credited_seconds'));
    }

    public function test_forward_skip_beyond_max_is_logged_but_watched_slice_is_credited(): void
    {
        $this->send(0); // position now 15

        $response = $this->send(15, [
            'segment_start' => 60, // jumped 45s ahead (> 10s max)
            'segment_end' => 75,
            'current_time' => 75,
        ])->assertOk();

        $this->assertSame(['seek_skip'], $response->json('flags'));
        $this->assertEqualsWithDelta(15.0, $response->json('credited_seconds'), 0.001);

        $progress = $this->progress();
        $this->assertEqualsWithDelta(30.0, (float) $progress->watched_seconds, 0.001); // 0-15 and 60-75 only
        $this->assertEquals([[0, 15], [60, 75]], $progress->watched_ranges);

        $log = $this->logsOfType('seek_skip')->firstOrFail();
        $this->assertEquals(15.0, $log->meta['from']);
        $this->assertEquals(60.0, $log->meta['to']);
        $this->assertSame(95, $this->integrityScore());
    }

    public function test_small_forward_seek_and_backward_seek_are_not_flagged(): void
    {
        $this->send(0);                                                                   // position 15
        $small = $this->send(15, ['segment_start' => 22, 'segment_end' => 37, 'current_time' => 37]); // +7s <= 10s
        $back = $this->send(30, ['segment_start' => 5, 'segment_end' => 20, 'current_time' => 20]);   // rewind

        $this->assertSame([], $small->json('flags'));
        $this->assertSame([], $back->json('flags'));
        $this->assertSame(0, $this->enrollment->integrityLogs()->count());
    }

    public function test_oversized_segment_is_trimmed_and_logged_as_seek_skip(): void
    {
        // Claims 100s of media inside a 15s window.
        $response = $this->send(0, ['segment_start' => 0, 'segment_end' => 100, 'current_time' => 100])->assertOk();

        $this->assertSame(['seek_skip'], $response->json('flags'));
        $this->assertEqualsWithDelta(17.0, $response->json('credited_seconds'), 0.001); // 15s window + 2s tolerance
        $this->assertSame('oversized_segment', $this->logsOfType('seek_skip')->firstOrFail()->meta['reason']);
    }

    public function test_muted_playback_is_logged_and_not_credited_when_disallowed(): void
    {
        $response = $this->send(0, ['muted' => true])->assertOk();

        $this->assertSame(['muted_playback'], $response->json('flags'));
        $this->assertEqualsWithDelta(0.0, $response->json('credited_seconds'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->progress()->watched_seconds, 0.001);
        $this->assertSame(3, $this->logsOfType('muted_playback')->firstOrFail()->points_deducted);
        $this->assertSame(97, $this->integrityScore());
    }

    public function test_muted_playback_is_credited_when_campaign_allows_it(): void
    {
        $this->updateCampaign(['allow_muted_playback' => true]);

        $response = $this->send(0, ['muted' => true])->assertOk();

        $this->assertSame([], $response->json('flags'));
        $this->assertEqualsWithDelta(15.0, $response->json('credited_seconds'), 0.001);
        $this->assertSame(0, $this->enrollment->integrityLogs()->count());
    }

    /** @return array<string, array{0: array<string, bool>}> */
    public static function hiddenTabStates(): array
    {
        return [
            'window unfocused' => [['tab_focused' => false]],
            'tab not visible' => [['tab_visible' => false]],
            'both' => [['tab_focused' => false, 'tab_visible' => false]],
        ];
    }

    #[DataProvider('hiddenTabStates')]
    public function test_unfocused_or_hidden_tab_pauses_credit_and_logs_anomaly(array $state): void
    {
        $response = $this->send(0, $state)->assertOk();

        $this->assertSame(['tab_hidden'], $response->json('flags'));
        $this->assertEqualsWithDelta(0.0, $response->json('credited_seconds'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->progress()->watched_seconds, 0.001);
        $this->assertSame(2, $this->logsOfType('tab_hidden')->firstOrFail()->points_deducted);
        $this->assertSame(98, $this->integrityScore());
    }

    public function test_paused_video_in_a_hidden_tab_is_not_penalized(): void
    {
        $response = $this->send(0, [
            'tab_visible' => false,
            'is_playing' => false,
            'segment_end' => 0,
            'current_time' => 0,
        ])->assertOk();

        $this->assertSame([], $response->json('flags'));
        $this->assertSame(0, $this->enrollment->integrityLogs()->count());
    }

    public function test_repeated_violations_are_throttled_to_one_log_per_cooldown_window(): void
    {
        $this->send(0, ['tab_focused' => false]);
        $second = $this->send(15, ['tab_focused' => false]);

        $this->assertSame(['tab_hidden'], $second->json('flags')); // still reported to the client
        $this->assertSame(1, $this->logsOfType('tab_hidden')->count()); // but logged once
        $this->assertSame(98, $this->integrityScore());
    }

    public function test_integrity_score_never_drops_below_zero(): void
    {
        $this->enrollment->forceFill(['integrity_score' => 3])->save();

        $this->send(0, ['playback_rate' => 3.0]); // -5 would underflow

        $this->assertSame(0, $this->integrityScore());
    }

    // -------------------------------------------- duplicate / offline replays

    public function test_duplicate_heartbeat_is_rejected_without_double_crediting(): void
    {
        $this->send(0)->assertOk();
        $duplicate = $this->send(0)->assertOk();

        $this->assertFalse($duplicate->json('accepted'));
        $this->assertSame('duplicate_or_out_of_order', $duplicate->json('reason'));
        $this->assertEqualsWithDelta(0.0, $duplicate->json('credited_seconds'), 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $this->progress()->watched_seconds, 0.001);
    }

    public function test_out_of_order_heartbeat_is_rejected(): void
    {
        $this->send(30)->assertOk();

        // An older queued heartbeat arrives late (server clock is already at +30s).
        $late = $this->postJson(self::URL, $this->payload(15))->assertOk();

        $this->assertFalse($late->json('accepted'));
        $this->assertSame('duplicate_or_out_of_order', $late->json('reason'));
        $this->assertEqualsWithDelta(15.0, (float) $this->progress()->watched_seconds, 0.001);
    }

    public function test_heartbeat_older_than_24_hours_is_rejected_as_stale(): void
    {
        $response = $this->postJson(self::URL, $this->payload(-(86400 + 60), [
            'segment_start' => 0,
            'segment_end' => 15,
            'current_time' => 15,
        ]))->assertOk();

        $this->assertFalse($response->json('accepted'));
        $this->assertSame('stale_heartbeat', $response->json('reason'));
        $this->assertSame(0, LessonProgress::count());
    }

    public function test_future_client_timestamp_is_clamped_to_server_time(): void
    {
        $this->postJson(self::URL, $this->payload(3600))->assertOk(); // claims to be an hour ahead

        $this->assertEquals($this->base->timestamp, $this->progress()->last_heartbeat_at->timestamp);
    }

    // ---------------------------------------------------------- reading flow

    public function test_reading_heartbeat_credits_dwell_and_flags_instant_scroll(): void
    {
        $response = $this->postJson(self::URL, [
            'campaign_id' => $this->campaign->id,
            'client_timestamp' => $this->base->getTimestampMs(),
            'lesson_key' => 'reading-1',
            'content_type' => 'reading',
            'tab_focused' => true,
            'tab_visible' => true,
            'scroll_percent' => 80, // 0 -> 80 in one beat (> 50 jump)
        ])->assertOk();

        $this->assertSame(['scroll_too_fast'], $response->json('flags'));
        $this->assertEqualsWithDelta(15.0, $response->json('credited_seconds'), 0.001);

        $progress = LessonProgress::where('lesson_key', 'reading-1')->firstOrFail();
        $this->assertSame(15, $progress->active_seconds);
        $this->assertSame(80, $progress->max_scroll_percent);
        // 15s of dwell at 0.5s/percent supports only 30% completion despite 80% scroll depth.
        $this->assertEqualsWithDelta(30.0, (float) $progress->completion_percent, 0.001);
    }

    public function test_reading_in_hidden_tab_earns_no_dwell_time(): void
    {
        $response = $this->postJson(self::URL, [
            'campaign_id' => $this->campaign->id,
            'client_timestamp' => $this->base->getTimestampMs(),
            'lesson_key' => 'reading-1',
            'content_type' => 'reading',
            'tab_focused' => false,
            'tab_visible' => true,
            'scroll_percent' => 10,
        ])->assertOk();

        $this->assertSame(['tab_hidden'], $response->json('flags'));
        $this->assertSame(0, LessonProgress::where('lesson_key', 'reading-1')->firstOrFail()->active_seconds);
    }

    // ------------------------------------------------ access & validation

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders(); // drop the bearer token set in setUp

        $this->postJson(self::URL, $this->payload(0))->assertUnauthorized();
    }

    public function test_student_not_enrolled_in_the_campaign_gets_404(): void
    {
        $outsider = User::factory()->state(['role' => 'student'])->create();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($outsider->createToken('test')->plainTextToken);

        $this->send(0)->assertNotFound();
        $this->assertSame(0, LessonProgress::count());
    }

    public function test_closed_campaign_does_not_accept_progress(): void
    {
        $this->updateCampaign(['status' => 'closed']);

        $this->send(0)->assertForbidden();
        $this->assertSame(0, LessonProgress::count());
    }

    public function test_invalid_payload_is_rejected_with_validation_errors(): void
    {
        $payload = $this->payload(0);
        unset($payload['playback_rate'], $payload['muted']);
        $payload['content_type'] = 'podcast';

        $this->postJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_type']);

        $video = $this->payload(0);
        unset($video['playback_rate'], $video['muted'], $video['segment_start']);

        $this->postJson(self::URL, $video)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['playback_rate', 'muted', 'segment_start']);
    }

    public function test_segment_end_before_start_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(0, ['segment_start' => 20, 'segment_end' => 10]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['segment_end']);
    }
}
