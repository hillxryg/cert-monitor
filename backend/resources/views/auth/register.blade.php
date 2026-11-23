@extends('layouts.app')
@section('title', 'Register')

@section('content')
@php
    $input = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $role = old('role', 'instructor');
@endphp
<div class="mx-auto max-w-md">
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-xl font-semibold text-slate-900">Create your account</h1>

        <form method="POST" action="{{ url('register') }}" class="mt-6 space-y-4">
            @csrf

            <fieldset>
                <legend class="block text-sm font-medium text-slate-700">I am a…</legend>
                <div class="mt-2 grid grid-cols-2 gap-3">
                    @foreach (['instructor' => ['Instructor', 'Create campaigns and review students'], 'student' => ['Student', 'Join a campaign with a code']] as $value => [$title, $desc])
                        <label class="cursor-pointer rounded-lg border border-slate-300 p-3 text-sm has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 has-[:checked]:ring-1 has-[:checked]:ring-indigo-500">
                            <input type="radio" name="role" value="{{ $value }}" class="sr-only" @checked($role === $value)>
                            <span class="block font-medium text-slate-900">{{ $title }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">{{ $desc }}</span>
                        </label>
                    @endforeach
                </div>
                @error('role')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </fieldset>

            <x-form-field name="name" label="Full name">
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name" class="{{ $input }}">
            </x-form-field>

            <x-form-field name="email" label="Email">
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" class="{{ $input }}">
            </x-form-field>

            <x-form-field name="password" label="Password" hint="At least 8 characters.">
                <input id="password" type="password" name="password" required autocomplete="new-password" class="{{ $input }}">
            </x-form-field>

            <x-form-field name="password_confirmation" label="Confirm password">
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="{{ $input }}">
            </x-form-field>

            <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Create account</button>
        </form>
    </div>
    <p class="mt-4 text-center text-sm text-slate-500">
        Already registered? <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:underline">Log in</a>
    </p>
</div>
@endsection
