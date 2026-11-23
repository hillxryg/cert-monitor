<?php

namespace App\Services;

use App\Enums\IntegrityEventType;
use App\Models\Enrollment;
use App\Models\IntegrityLog;
use App\Models\LessonProgress;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Server-side verification of a single client heartbeat.
 *
 * The client only reports raw telemetry. Credit is granted here, bounded by server-measured
 * time between heartbeats, and withheld whenever a rule is violated.
 */
class HeartbeatProcessor
{
    private const SPEED_EPSILON = 0.001;

    public function __construct(private readonly SegmentMerger $merger)
    {
    }

    /**
     * @param  array<string, mixed>  $input  Validated HeartbeatRequest payload
     * @return array<string, mixed>
     */
    public function process(Enrollment $enrollment, array $input): array
    {
        $now = now();
        $occurredAt = \Illuminate\Support\Carbon::createFromTimestampMs((int) $input['client_timestamp']);

        if ($occurredAt->gt($now)) {
            $occurredAt = $now->copy();
        }

        // Too old to be a legitimate offline-queue replay: drop (client should discard it).
        if ($occurredAt->lt($now->copy()->subDay())) {
            return $this->rejected('stale_heartbeat');
        }

        // Created outside the transaction so a unique-key race does not abort it.
        $progress = LessonProgress::createOrFirst(
            ['enrollment_id' => $enrollment->id, 'lesson_key' => $input['lesson_key']],
            [
                'lesson_title' => $input['lesson_title'] ?? null,
                'lesson_url' => $input['lesson_url'] ?? null,
                'content_type' => $input['content_type'],
            ],
        );

        return DB::transaction(function () use ($enrollment, $progress, $input, $occurredAt) {
            // Consistent lock order: lesson row, then enrollment row.
            $progress = LessonProgress::query()->whereKey($progress->id)->lockForUpdate()->firstOrFail();
            $enrollment = Enrollment::query()->with('campaign')->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($progress->content_type !== $input['content_type']) {
                return $this->rejected('content_type_mismatch');
            }

            // Idempotency: duplicates / out-of-order replays from the offline queue are ignored.
            if ($progress->last_heartbeat_at && ! $occurredAt->gt($progress->last_heartbeat_at)) {
                return $this->snapshot($progress, $enrollment, [], 0.0, accepted: false, reason: 'duplicate_or_out_of_order');
            }

            $cfg = config('integrity');
            $interval = (float) $cfg['heartbeat_interval'];
            $tolerance = (float) $cfg['timing_tolerance'];

            $elapsed = $progress->last_heartbeat_at
                ? max(0.0, (float) $progress->last_heartbeat_at->diffInSeconds($occurredAt))
                : $interval;
            $creditWindow = min($elapsed, $interval + $tolerance);

            $events = [];

            if ($progress->last_heartbeat_at && $elapsed > $interval * $cfg['gap_threshold_multiplier']) {
                $events[] = [IntegrityEventType::HeartbeatGap, ['gap_seconds' => round($elapsed, 1)]];
            }

            $focused = (bool) $input['tab_focused'] && (bool) $input['tab_visible'];

            $analysis = $input['content_type'] === 'video'
                ? $this->analyzeVideo($enrollment, $progress, $input, $focused, $creditWindow, $tolerance, $events)
                : $this->analyzeReading($progress, $input, $focused, $creditWindow, $events);

            $progress->forceFill($analysis['progress'] + ['last_heartbeat_at' => $occurredAt]);

            if ($progress->completed_at === null
                && (float) $progress->completion_percent >= $enrollment->campaign->required_completion_percent) {
                $progress->completed_at = $occurredAt;
            }

            $progress->save();

            $deducted = $this->recordEvents($enrollment, $progress, $events, $occurredAt);

            $enrollment->forceFill([
                'integrity_score' => max(0, (int) $enrollment->integrity_score - $deducted),
                'completion_percent' => round((float) LessonProgress::where('enrollment_id', $enrollment->id)->avg('completion_percent'), 2),
                'last_activity_at' => $occurredAt,
            ])->save();

            $flags = array_values(array_unique(array_map(static fn (array $e): string => $e[0]->value, $events)));

            return $this->snapshot($progress, $enrollment, $flags, $analysis['credited']);
        });
    }

    /**
     * @param  array<int, array{0: IntegrityEventType, 1: array<string, mixed>}>  $events
     * @return array{progress: array<string, mixed>, credited: float}
     */
    private function analyzeVideo(
        Enrollment $enrollment,
        LessonProgress $progress,
        array $input,
        bool $focused,
        float $creditWindow,
        float $tolerance,
        array &$events,
    ): array {
        $campaign = $enrollment->campaign;

        $start = (float) $input['segment_start'];
        $end = (float) $input['segment_end'];
        $position = (float) $input['current_time'];
        $rate = (float) $input['playback_rate'];
        $playing = (bool) $input['is_playing'];
        $muted = (bool) $input['muted'];

        // Reported duration can't be verified server-side; keep the largest value seen so a
        // later honest report corrects an understated one.
        $duration = max((float) $progress->video_duration, (float) $input['duration']);

        // Forward timeline skip vs. where the previous heartbeat left off.
        $from = $playing ? $start : $position;
        if ($progress->last_position !== null && ($from - (float) $progress->last_position) > $campaign->max_skip_seconds) {
            $events[] = [IntegrityEventType::SeekSkip, [
                'from' => round((float) $progress->last_position, 2),
                'to' => round($from, 2),
            ]];
        }

        $speedViolation = $rate > (float) $campaign->max_playback_speed + self::SPEED_EPSILON;
        if ($speedViolation) {
            $events[] = [IntegrityEventType::SpeedViolation, [
                'playback_rate' => $rate,
                'max_allowed' => (float) $campaign->max_playback_speed,
            ]];
        }

        $mutedViolation = $playing && $muted && ! $campaign->allow_muted_playback;
        if ($mutedViolation) {
            $events[] = [IntegrityEventType::MutedPlayback, []];
        }

        if ($playing && ! $focused) {
            $events[] = [IntegrityEventType::TabHidden, [
                'tab_focused' => (bool) $input['tab_focused'],
                'tab_visible' => (bool) $input['tab_visible'],
            ]];
        }

        $ranges = $progress->watched_ranges ?? [];
        $watched = (float) $progress->watched_seconds;

        $creditable = $playing && $focused && ! $speedViolation && ! $mutedViolation && $end > $start;

        if ($creditable) {
            // A segment can't contain more media time than the wall-clock window allows.
            $maxSpan = $creditWindow * max(1.0, $rate) + $tolerance;

            if (($end - $start) > $maxSpan) {
                $events[] = [IntegrityEventType::SeekSkip, [
                    'reason' => 'oversized_segment',
                    'claimed_seconds' => round($end - $start, 2),
                    'allowed_seconds' => round($maxSpan, 2),
                ]];
                $end = $start + $maxSpan;
            }

            $ranges = $this->merger->add($ranges, $start, $end, $duration);
            $watched = $this->merger->totalSeconds($ranges, $duration);
        }

        return [
            'credited' => max(0.0, $watched - (float) $progress->watched_seconds),
            'progress' => [
                'video_duration' => $duration,
                'watched_ranges' => $ranges,
                'watched_seconds' => $watched,
                'last_position' => $playing ? (float) $input['segment_end'] : $position,
                'completion_percent' => $this->merger->coveragePercent($ranges, $duration),
            ],
        ];
    }

    /**
     * @param  array<int, array{0: IntegrityEventType, 1: array<string, mixed>}>  $events
     * @return array{progress: array<string, mixed>, credited: float}
     */
    private function analyzeReading(
        LessonProgress $progress,
        array $input,
        bool $focused,
        float $creditWindow,
        array &$events,
    ): array {
        $scroll = (int) $input['scroll_percent'];

        if (($scroll - (int) $progress->max_scroll_percent) > config('integrity.scroll_jump_percent')) {
            $events[] = [IntegrityEventType::ScrollTooFast, [
                'from' => (int) $progress->max_scroll_percent,
                'to' => $scroll,
            ]];
        }

        if (! $focused) {
            $events[] = [IntegrityEventType::TabHidden, [
                'tab_focused' => (bool) $input['tab_focused'],
                'tab_visible' => (bool) $input['tab_visible'],
            ]];
        }

        $credited = $focused ? $creditWindow : 0.0;
        $active = (int) $progress->active_seconds + (int) round($credited);
        $maxScroll = max((int) $progress->max_scroll_percent, $scroll);

        // Scrolling to the bottom instantly earns nothing: depth only counts once enough
        // focused dwell time has accumulated for it.
        $dwellPercent = $active / (float) config('integrity.reading_seconds_per_percent');
        $completion = round(min(100.0, (float) $maxScroll, $dwellPercent), 2);

        return [
            'credited' => $credited,
            'progress' => [
                'active_seconds' => $active,
                'max_scroll_percent' => $maxScroll,
                'completion_percent' => $completion,
            ],
        ];
    }

    /**
     * Persist anomalies (rate-limited per type) and return total points deducted.
     *
     * @param  array<int, array{0: IntegrityEventType, 1: array<string, mixed>}>  $events
     */
    private function recordEvents(Enrollment $enrollment, LessonProgress $progress, array $events, CarbonInterface $occurredAt): int
    {
        $budget = (int) config('integrity.max_deduction_per_heartbeat');
        $cooldown = (int) config('integrity.log_cooldown_seconds');
        $total = 0;

        foreach ($events as [$type, $meta]) {
            $recent = IntegrityLog::query()
                ->where('lesson_progress_id', $progress->id)
                ->where('event_type', $type->value)
                ->where('occurred_at', '>=', $occurredAt->copy()->subSeconds($cooldown))
                ->exists();

            if ($recent) {
                continue;
            }

            $points = min((int) config("integrity.points.{$type->value}", 0), $budget);
            $budget -= $points;
            $total += $points;

            IntegrityLog::create([
                'enrollment_id' => $enrollment->id,
                'lesson_progress_id' => $progress->id,
                'event_type' => $type,
                'points_deducted' => $points,
                'meta' => $meta ?: null,
                'occurred_at' => $occurredAt,
            ]);
        }

        return $total;
    }

    /**
     * @param  array<int, string>  $flags
     * @return array<string, mixed>
     */
    private function snapshot(
        LessonProgress $progress,
        Enrollment $enrollment,
        array $flags,
        float $credited,
        bool $accepted = true,
        ?string $reason = null,
    ): array {
        return array_filter([
            'accepted' => $accepted,
            'reason' => $reason,
            'credited_seconds' => round($credited, 2),
            'flags' => $flags,
            'lesson' => [
                'completion_percent' => (float) $progress->completion_percent,
                'completed' => $progress->completed_at !== null,
            ],
            'enrollment' => [
                'completion_percent' => (float) $enrollment->completion_percent,
                'integrity_score' => (int) $enrollment->integrity_score,
            ],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string, mixed> */
    private function rejected(string $reason): array
    {
        return ['accepted' => false, 'reason' => $reason];
    }
}
