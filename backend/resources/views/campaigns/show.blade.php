@extends('layouts.app')
@section('title', $campaign->title)

@section('content')
<a href="{{ route('dashboard') }}" class="text-sm text-slate-500 hover:text-slate-800">← All campaigns</a>

<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold text-slate-900">{{ $campaign->title }}</h1>
            <x-status-badge :status="$campaign->status" />
        </div>
        <p class="mt-1 text-sm text-slate-500">{{ $campaign->platform_name ?: 'Any platform' }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-join-code :code="$campaign->join_code" />
        <a href="{{ route('campaigns.edit', $campaign) }}" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit</a>
        <form method="POST" action="{{ route('campaigns.toggle', $campaign) }}">
            @csrf @method('PATCH')
            <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                {{ $campaign->status === 'active' ? 'Close campaign' : 'Reopen campaign' }}
            </button>
        </form>
    </div>
</div>

<div class="mt-4 flex flex-wrap gap-2 text-xs text-slate-600">
    <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">Complete at ≥ {{ $campaign->required_completion_percent }}% verified</span>
    <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">Flag below {{ $campaign->min_integrity_score }} integrity</span>
    <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">Max speed {{ rtrim(rtrim(number_format((float) $campaign->max_playback_speed, 2), '0'), '.') }}×</span>
    <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">Max skip {{ $campaign->max_skip_seconds }}s</span>
    <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">{{ $campaign->allow_muted_playback ? 'Muted playback allowed' : 'Muted playback not allowed' }}</span>
    <span class="rounded-md bg-white px-2 py-1 font-mono ring-1 ring-slate-200">{{ $campaign->target_url_pattern }}</span>
</div>

<dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <dt class="text-xs text-slate-500">Students</dt>
        <dd class="mt-1 text-2xl font-semibold">{{ $summary['students'] }}</dd>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <dt class="text-xs text-slate-500">Avg verified</dt>
        <dd class="mt-1 text-2xl font-semibold">{{ number_format($summary['avg_verified_pct'], 1) }}%</dd>
    </div>
    <div class="rounded-xl border p-4 {{ $summary['flagged'] > 0 ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white' }}">
        <dt class="text-xs {{ $summary['flagged'] > 0 ? 'text-red-600' : 'text-slate-500' }}">Flagged</dt>
        <dd class="mt-1 text-2xl font-semibold {{ $summary['flagged'] > 0 ? 'text-red-700' : '' }}">{{ $summary['flagged'] }}</dd>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <dt class="text-xs text-slate-500">Verified complete</dt>
        <dd class="mt-1 text-2xl font-semibold">{{ $summary['verified_complete'] }}</dd>
    </div>
</dl>

<div class="mt-8 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-slate-900">Student roster</h2>
    <div class="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-sm">
        @foreach (['' => 'All', 'in_progress' => 'In progress', 'flagged' => 'Flagged', 'verified_complete' => 'Verified complete'] as $key => $label)
            <a href="{{ route('campaigns.show', ['campaign' => $campaign, 'status' => $key ?: null]) }}"
               class="rounded-md px-3 py-1 font-medium {{ ($filter ?? '') === $key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

<div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Student</th>
                    <th class="px-4 py-3">Verified</th>
                    <th class="px-4 py-3">Integrity</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Last activity</th>
                    <th class="px-4 py-3"><span class="sr-only">Inspect</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($enrollments as $enrollment)
                    @php
                        $verified = min(100, max(0, (float) $enrollment->completion_percent));
                        $isFlagged = $enrollment->audit_status === 'flagged';
                    @endphp
                    <tr class="{{ $isFlagged ? 'bg-red-50/50' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-900">{{ $enrollment->student->name }}</div>
                            <div class="text-xs text-slate-500">{{ $enrollment->student->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-2 w-24 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full rounded-full bg-indigo-500" style="width: {{ $verified }}%"></div>
                                </div>
                                <span class="tabular-nums text-slate-700">{{ number_format($verified, 1) }}%</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 tabular-nums font-medium {{ $isFlagged ? 'text-red-700' : 'text-slate-800' }}">
                            {{ $enrollment->integrity_score }}
                            <span class="text-xs font-normal text-slate-400">/ 100</span>
                        </td>
                        <td class="px-4 py-3"><x-status-badge :status="$enrollment->audit_status" /></td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $enrollment->last_activity_at?->diffForHumans() ?? 'No activity yet' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('campaigns.audit', [$campaign, $enrollment]) }}" class="font-medium text-indigo-600 hover:underline">Inspect</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            @if ($filter)
                                No students match this filter.
                            @else
                                No students yet. Share the join code <span class="font-mono font-semibold text-slate-700">{{ $campaign->join_code }}</span> to get started.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $enrollments->links() }}</div>
@endsection
