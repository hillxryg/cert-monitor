@extends('layouts.app')
@section('title', 'Edit '.$campaign->title)

@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="text-2xl font-semibold text-slate-900">Edit campaign</h1>
    <p class="mb-6 mt-1 text-sm text-slate-500">Rule changes apply to heartbeats received from now on; past logs are not rewritten.</p>
    @include('campaigns._form', [
    'action' => route('campaigns.update', $campaign),
    'method' => 'PUT',
    'submit' => 'Save changes',
    'cancel' => route('campaigns.show', $campaign),
    ])

    {{-- Separate form: it can't be nested inside the edit form. --}}
    <section class="mt-10 rounded-xl border border-red-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="font-semibold text-red-700">Delete campaign</h2>
        <p class="mt-1 text-sm text-slate-600">
            Permanently removes this campaign, its students’ progress and every integrity log. This cannot be undone.
            To keep the records, close the campaign instead.
        </p>
        <div class="mt-4">
            <x-delete-campaign :campaign="$campaign" solid>Delete campaign</x-delete-campaign>
        </div>
    </section>
</div>
@endsection