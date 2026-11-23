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
</div>
@endsection
