@extends('layouts.app')
@section('title', 'Welcome')

@section('content')
<div class="mx-auto max-w-xl rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
    <h1 class="text-xl font-semibold text-slate-900">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-3 text-sm text-slate-600">
        Students work through lessons on their course platform. Install the Cert Monitor browser extension,
        sign in with this account, and enter the join code your instructor gave you.
    </p>
    <p class="mt-3 text-sm text-slate-500">Your progress is verified automatically while you watch and read.</p>
</div>
@endsection
