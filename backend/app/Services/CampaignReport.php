<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use App\Models\User;
use App\Support\Timecode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/** Read-side queries and derived values for the instructor dashboard. */
class CampaignReport
{
    public const IN_PROGRESS = 'in_progress';
    public const FLAGGED = 'flagged';
    public const VERIFIED_COMPLETE = 'verified_complete';

    public const STATUSES = [self::IN_PROGRESS, self::FLAGGED, self::VERIFIED_COMPLETE];

    /** Dashboard filter key (URL) => campaigns.status value. "All" is the absence of a filter. */
    public const CAMPAIGN_FILTERS = ['open' => 'active', 'closed' => 'closed'];

    public function __construct(
        private readonly SegmentMerger $merger,
        private readonly CompletionCalculator $completion,
    ) {}

    /** Instructor's campaigns with student / average verified % / flagged counts in one query. */
    public function dashboardCampaigns(User $instructor, string $search = '', ?string $status = null): Collection
    {
        return Campaign::query()
            ->where('campaigns.instructor_id', $instructor->id)
            ->when($search !== '', fn(Builder $q) => $this->applySearch($q, $search))
            ->when(
                isset(self::CAMPAIGN_FILTERS[$status ?? '']),
                fn(Builder $q) => $q->where('campaigns.status', self::CAMPAIGN_FILTERS[$status]),
            )
            ->withCount([
                'enrollments as students_count',
                'enrollments as flagged_count' => fn(Builder $q) => $q->whereColumn('enrollments.integrity_score', '<', 'campaigns.min_integrity_score'),
            ])
            ->withAvg('enrollments as avg_verified_pct', 'completion_percent')
            ->latest()
            ->get();
    }

    /** @return array{students: int, avg_verified_pct: float, flagged: int, verified_complete: int} */
    public function summary(Campaign $campaign): array
    {
        $base = Enrollment::query()->where('campaign_id', $campaign->id);

        return [
            'students' => (clone $base)->count(),
            'avg_verified_pct' => round((float) (clone $base)->avg('completion_percent'), 1),
            'flagged' => $this->applyStatus(clone $base, $campaign, self::FLAGGED)->count(),
            'verified_complete' => $this->applyStatus(clone $base, $campaign, self::VERIFIED_COMPLETE)->count(),
        ];
    }

    /** Roster query, lowest integrity first so problem students surface at the top. */
    public function roster(Campaign $campaign, ?string $status = null): Builder
    {
        $query = Enrollment::query()
            ->where('campaign_id', $campaign->id)
            ->with('student:id,name,email')
            ->withCount('integrityLogs');

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $this->applyStatus($query, $campaign, $status);
        }

        return $query->orderBy('integrity_score')->orderBy('id');
    }

    public function statusFor(Enrollment $enrollment, Campaign $campaign): string
    {
        if ($enrollment->integrity_score < $campaign->min_integrity_score) {
            return self::FLAGGED;
        }

        if ((float) $enrollment->completion_percent >= $campaign->required_completion_percent) {
            return self::VERIFIED_COMPLETE;
        }

        return self::IN_PROGRESS;
    }

    /**
     * Per-lesson breakdown for the audit view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lessonBreakdown(Enrollment $enrollment): array
    {
        return $enrollment->lessonProgress()
            ->orderBy('created_at')
            ->get()
            ->map(fn(LessonProgress $lesson) => $this->presentLesson($lesson))
            ->all();
    }

    /**
     * Tracked items grouped into modules for the audit view. A narrated SCORM module reports
     * one item per slide; listing them flat would bury everything else.
     *
     * @return array<int, array<string, mixed>>
     */
    public function moduleBreakdown(Enrollment $enrollment): array
    {
        $lessons = $this->lessonBreakdown($enrollment);

        $verified = $this->completion->groupCompletions(array_map(
            static fn(array $lesson): array => [
                'group' => $lesson['group'],
                'completion' => $lesson['verified_pct'],
                'duration' => $lesson['duration'],
            ],
            $lessons,
        ));

        $modules = [];
        foreach ($lessons as $lesson) {
            $key = $lesson['group'];

            $modules[$key] ??= [
                'key' => $key,
                'title' => $lesson['title'],
                'url' => $lesson['url'],
                'verified_pct' => round($verified[$key] ?? 0.0, 1),
                'completed' => true,
                'items' => [],
            ];

            $modules[$key]['items'][] = $lesson;
            $modules[$key]['completed'] = $modules[$key]['completed'] && $lesson['completed'];
        }

        return array_values($modules);
    }

    /** Title / platform / join code contains the term. LIKE wildcards in the term are escaped. */
    private function applySearch(Builder $query, string $search): Builder
    {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        // Grouped so the OR chain can't leak past the instructor / status constraints.
        return $query->where(function (Builder $q) use ($like) {
            foreach (['title', 'platform_name', 'join_code'] as $column) {
                $q->orWhereRaw("campaigns.{$column} like ? escape '!'", [$like]);
            }
        });
    }

    private function applyStatus(Builder $query, Campaign $campaign, string $status): Builder
    {
        return match ($status) {
            self::FLAGGED => $query->where('integrity_score', '<', $campaign->min_integrity_score),
            self::VERIFIED_COMPLETE => $query
                ->where('integrity_score', '>=', $campaign->min_integrity_score)
                ->where('completion_percent', '>=', $campaign->required_completion_percent),
            self::IN_PROGRESS => $query
                ->where('integrity_score', '>=', $campaign->min_integrity_score)
                ->where('completion_percent', '<', $campaign->required_completion_percent),
        };
    }

    /** @return array<string, mixed> */
    private function presentLesson(LessonProgress $lesson): array
    {
        $isVideo = $lesson->content_type === 'video';
        $duration = (float) $lesson->video_duration;
        $ranges = $isVideo ? $this->merger->merge($lesson->watched_ranges ?? [], $duration > 0 ? $duration : null) : [];

        // Lesson URLs come from students' browsers: only ever link http(s).
        $url = $lesson->lesson_url && Str::startsWith($lesson->lesson_url, ['http://', 'https://'])
            ? $lesson->lesson_url
            : null;

        return [
            'id' => $lesson->id,
            'group' => $lesson->lesson_group ?? $lesson->lesson_key,
            'title' => $lesson->lesson_title ?: $lesson->lesson_key,
            'url' => $url,
            'content_type' => $lesson->content_type,
            'verified_pct' => (float) $lesson->completion_percent,
            'completed' => $lesson->completed_at !== null,
            'duration' => $duration,
            'watched_seconds' => (float) $lesson->watched_seconds,
            'range_labels' => array_map(
                static fn(array $r): string => Timecode::clock($r[0]) . ' – ' . Timecode::clock($r[1]),
                $ranges,
            ),
            'bars' => $duration > 0
                ? array_map(static fn(array $r): array => [
                    'left' => round($r[0] / $duration * 100, 2),
                    'width' => max(0.4, round(($r[1] - $r[0]) / $duration * 100, 2)),
                ], $ranges)
                : [],
            'active_seconds' => (int) $lesson->active_seconds,
            'max_scroll_percent' => (int) $lesson->max_scroll_percent,
            'last_heartbeat_at' => $lesson->last_heartbeat_at,
        ];
    }
}
