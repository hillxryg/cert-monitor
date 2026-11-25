@extends('layouts.app')
@section('title', 'Campaigns')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Your campaigns</h1>
        <p class="mt-1 text-sm text-slate-500">Share a join code, then track verified progress and integrity.</p>
    </div>
    <a href="{{ route('campaigns.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">New campaign</a>
</div>

@php($filtering = $search !== '' || $status !== null)

@if ($filtering || $campaigns->isNotEmpty())
<div class="mt-6 flex flex-wrap items-center justify-between gap-3">
    <form method="GET" action="{{ route('dashboard') }}" class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <input type="search" name="q" value="{{ $search }}" maxlength="100" aria-label="Search campaigns"
            placeholder="Search title, platform or join code"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:w-72">
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Search</button>
        @if ($search !== '')
        <a href="{{ route('dashboard', ['status' => $status]) }}" class="text-sm text-slate-500 hover:text-slate-800">Clear</a>
        @endif
    </form>

    <div class="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-sm">
        @foreach (['' => 'All', 'open' => 'Open', 'closed' => 'Closed'] as $key => $label)
        <a href="{{ route('dashboard', ['q' => $search !== '' ? $search : null, 'status' => $key ?: null]) }}"
            class="rounded-md px-3 py-1 font-medium {{ ($status ?? '') === $key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>
@endif

@if ($campaigns->isEmpty() && $filtering)
<div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
    <p class="font-medium text-slate-900">No campaigns match</p>
    <p class="mt-1 text-sm text-slate-500">Try a different search term or status filter.</p>
    <a href="{{ route('dashboard') }}" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:underline">Clear filters</a>
</div>
@elseif ($campaigns->isEmpty())
<div class="mt-8 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
    <p class="font-medium text-slate-900">No campaigns yet</p>
    <p class="mt-1 text-sm text-slate-500">Create one to get a join code your students can enter in the extension.</p>
    <a href="{{ route('campaigns.create') }}" class="mt-4 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Create your first campaign</a>
</div>
@else
<div class="mt-6 grid gap-5 md:grid-cols-2">
    @foreach ($campaigns as $campaign)
    <article class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold text-slate-900">
                    <a href="{{ route('campaigns.show', $campaign) }}" class="hover:text-indigo-600">{{ $campaign->title }}</a>
                </h2>
                <p class="truncate text-sm text-slate-500">{{ $campaign->platform_name ?: 'Any platform' }}</p>
            </div>
            <x-status-badge :status="$campaign->status" />
        </div>

        <div class="mt-4"><x-join-code :code="$campaign->join_code" /></div>

        <dl class="mt-5 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-lg bg-slate-50 p-3">
                <dt class="text-xs text-slate-500">Students</dt>
                <dd class="mt-1 text-xl font-semibold text-slate-900">{{ $campaign->students_count }}</dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-3">
                <dt class="text-xs text-slate-500">Avg verified</dt>
                <dd class="mt-1 text-xl font-semibold text-slate-900">{{ number_format((float) $campaign->avg_verified_pct, 1) }}%</dd>
            </div>
            <div class="rounded-lg p-3 {{ $campaign->flagged_count > 0 ? 'bg-red-50' : 'bg-slate-50' }}">
                <dt class="text-xs {{ $campaign->flagged_count > 0 ? 'text-red-600' : 'text-slate-500' }}">Flagged</dt>
                <dd class="mt-1 text-xl font-semibold {{ $campaign->flagged_count > 0 ? 'text-red-700' : 'text-slate-900' }}">{{ $campaign->flagged_count }}</dd>
            </div>
        </dl>

        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4 text-sm">
            <a href="{{ route('campaigns.show', $campaign) }}" class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700">View roster</a>
            <a href="{{ route('campaigns.edit', $campaign) }}" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">Edit</a>
            <form method="POST" action="{{ route('campaigns.toggle', $campaign) }}" class="ml-auto">
                @csrf @method('PATCH')
                <button type="submit" class="rounded-md px-3 py-1.5 font-medium text-slate-600 hover:bg-slate-100">
                    {{ $campaign->status === 'active' ? 'Close' : 'Reopen' }}
                </button>
            </form>
            <x-delete-campaign :campaign="$campaign" />
        </div>
    </article>
    @endforeach
</div>
@endif
@endsection