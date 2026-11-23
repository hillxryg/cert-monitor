@extends('layouts.app')
@section('title', 'Log in')

@section('content')
<div class="mx-auto max-w-md">
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-xl font-semibold text-slate-900">Log in</h1>
        <p class="mt-1 text-sm text-slate-500">Access your campaigns and student reports.</p>

        <form method="POST" action="{{ url('login') }}" class="mt-6 space-y-4">
            @csrf
            <x-form-field name="email" label="Email">
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </x-form-field>

            <x-form-field name="password" label="Password">
                <input id="password" type="password" name="password" required autocomplete="current-password"
                       class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </x-form-field>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-indigo-600">
                Remember me
            </label>

            <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Log in</button>
        </form>
    </div>
    <p class="mt-4 text-center text-sm text-slate-500">
        No account yet? <a href="{{ route('register') }}" class="font-medium text-indigo-600 hover:underline">Register</a>
    </p>
</div>
@endsection
