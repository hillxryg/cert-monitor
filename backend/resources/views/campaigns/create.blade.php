@extends('layouts.app')
@section('title', 'New campaign')

@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="mb-6 text-2xl font-semibold text-slate-900">New campaign</h1>
    @include('campaigns._form', [
        'action' => route('campaigns.store'),
        'method' => 'POST',
        'submit' => 'Create campaign',
        'cancel' => route('dashboard'),
    ])
</div>
@endsection
