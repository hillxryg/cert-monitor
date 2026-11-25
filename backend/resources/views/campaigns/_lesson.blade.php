{{-- One tracked item (a video, a narrated slide or a reading page). Expects $lesson. --}}
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
