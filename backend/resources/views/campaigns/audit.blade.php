@extends('layouts.app')
@section('title', $enrollment->student->name.' · '.$campaign->title)

@section('content')
<a href="{{ route('campaigns.show', $campaign) }}" class="text-sm text-slate-500 hover:text-slate-800">← {{ $campaign->title }}</a>

<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $enrollment->student->name }}</h1>
        <p class="text-sm text-slate-500">{{ $enrollment->student->email }} · joined {{ $enrollment->joined_at?->format('M j, Y') }}</p>
    </div>
    <x-status-badge :status="$enrollment->audit_status" class="text-sm" />
</div>

<dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <dt class="text-xs text-slate-500">Overall verified</dt>
        <dd class="mt-1 text-2xl font-semibold">{{ number_format((float) $enrollment->completion_percent, 1) }}%</dd>
        <dd class="text-xs text-slate-400">needs {{ $campaign->required_completion_percent }}%</dd>
    </div>
    <div class="rounded-xl border p-4 {{ $enrollment->audit_status === 'flagged' ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white' }}">
        <dt class="text-xs {{ $enrollment->audit_status === 'flagged' ? 'text-red-600' : 'text-slate-500' }}">Integrity score</dt>
        <dd class="mt-1 text-2xl font-semibold {{ $enrollment->audit_status === 'flagged' ? 'text-red-700' : '' }}">{{ $enrollment->integrity_score }}</dd>
        <dd class="text-xs text-slate-400">flagged below {{ $campaign->min_integrity_score }}</dd>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <dt class="text-xs text-slate-500">Lessons tracked</dt>
        <dd class="mt-1 text-2xl font-semibold">{{ count($lessons) }}</dd>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <dt class="text-xs text-slate-500">Last activity</dt>
        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $enrollment->last_activity_at?->format('M j, Y H:i') ?? '—' }}</dd>
        <dd class="text-xs text-slate-400">{{ $enrollment->last_activity_at?->diffForHumans() }}</dd>
    </div>
</dl>

@if ($eventTotals->isNotEmpty())
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($eventTotals as $row)
            <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs ring-1 ring-slate-200">
                <span class="font-medium text-slate-800">{{ $row->event_type->label() }}</span>
                <span class="text-slate-500">×{{ $row->occurrences }}</span>
                @if ($row->points > 0)<span class="font-medium text-red-600">−{{ $row->points }} pts</span>@endif
            </span>
        @endforeach
    </div>
@endif

<h2 class="mt-10 text-lg font-semibold text-slate-900">Lessons</h2>
<div class="mt-3 space-y-4">
    @forelse ($lessons as $lesson)
        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="truncate font-medium text-slate-900">
                        @if ($lesson['url'])
                            <a href="{{ $lesson['url'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-indigo-600">{{ $lesson['title'] }}</a>
                        @else
                            {{ $lesson['title'] }}
                        @endif
                    </h3>
                    <p class="text-xs text-slate-500">
                        {{ $lesson['content_type'] === 'video' ? 'Video' : 'Reading' }}
                        @if ($lesson['last_heartbeat_at']) · last seen {{ $lesson['last_heartbeat_at']->format('M j, H:i') }} @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    @if ($lesson['completed'])
                        <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-200">Completed</span>
                    @endif
                    <span class="text-lg font-semibold tabular-nums text-slate-900">{{ number_format($lesson['verified_pct'], 1) }}%</span>
                    <span class="text-xs text-slate-500">verified</span>
                </div>
            </div>

            @if ($lesson['content_type'] === 'video')
                <div class="mt-4">
                    <div class="relative h-4 overflow-hidden rounded bg-slate-200" role="img"
                         aria-label="Watched portions of the video timeline">
                        @foreach ($lesson['bars'] as $bar)
                            <div class="absolute inset-y-0 bg-indigo-500" style="left: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%"></div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex justify-between text-xs text-slate-400">
                        <span>0:00</span>
                        <span>{{ \App\Support\Timecode::clock($lesson['duration']) }}</span>
                    </div>
                </div>

                <p class="mt-3 text-sm text-slate-600">
                    <span class="font-medium text-slate-800">{{ \App\Support\Timecode::clock($lesson['watched_seconds']) }}</span>
                    verified of {{ \App\Support\Timecode::clock($lesson['duration']) }}.
                    @if ($lesson['range_labels'])
                        Watched ranges:
                        @foreach ($lesson['range_labels'] as $label)
                            <span class="ml-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{{ $label }}</span>
                        @endforeach
                    @else
                        No verified playback yet.
                    @endif
                </p>
            @else
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">Focused reading time</dt>
                        <dd class="mt-1 font-medium">{{ \App\Support\Timecode::clock($lesson['active_seconds']) }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">Deepest scroll</dt>
                        <dd class="mt-1 font-medium">{{ $lesson['max_scroll_percent'] }}%</dd>
                    </div>
                </dl>
            @endif
        </article>
    @empty
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
            This student hasn't reported any lesson activity yet.
        </div>
    @endforelse
</div>

<div class="mt-10 flex items-baseline justify-between">
    <h2 class="text-lg font-semibold text-slate-900">Integrity log</h2>
    <p class="text-xs text-slate-500">Newest first · append-only</p>
</div>
<div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Event</th>
                    <th class="px-4 py-3">Lesson</th>
                    <th class="px-4 py-3">Details</th>
                    <th class="px-4 py-3 text-right">Points</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $log->occurred_at->format('M j, Y H:i:s') }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-200">{{ $log->event_type->label() }}</span>
                        </td>
                        <td class="max-w-[14rem] truncate px-4 py-3 text-slate-600">{{ $log->lessonProgress?->lesson_title ?: $log->lessonProgress?->lesson_key ?: '—' }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $log->event_type->describe($log->meta) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums {{ $log->points_deducted > 0 ? 'font-medium text-red-600' : 'text-slate-400' }}">
                            {{ $log->points_deducted > 0 ? '−'.$log->points_deducted : '0' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No integrity events recorded. 🎉</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
