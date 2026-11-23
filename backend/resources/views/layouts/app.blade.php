<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    {{-- Tailwind via CDN for zero-build development. For production, compile with Vite instead. --}}
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex h-14 max-w-6xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold text-slate-900">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-600 text-sm text-white">✓</span>
                <span class="hidden sm:inline">Cert Monitor</span>
            </a>

            <nav class="flex items-center gap-3 text-sm">
                @auth
                    @if (auth()->user()->role === 'instructor')
                        <a href="{{ route('dashboard') }}" class="rounded-md px-3 py-1.5 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">Campaigns</a>
                    @endif
                    <span class="hidden text-slate-500 sm:inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">Log out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="rounded-md px-3 py-1.5 font-medium text-slate-600 hover:bg-slate-100">Log in</a>
                    <a href="{{ route('register') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-500">Register</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        @if (session('status'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>

    <script>
        // One-click copy for any element with data-copy="…" (e.g. campaign join codes).
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy]');
            if (!button) return;

            const text = button.dataset.copy;
            try {
                await navigator.clipboard.writeText(text);
            } catch (_) {
                // Fallback for non-HTTPS origins.
                const field = document.createElement('textarea');
                field.value = text;
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }

            const label = button.querySelector('[data-copy-label]');
            if (label) {
                const original = label.textContent;
                label.textContent = 'Copied!';
                setTimeout(() => (label.textContent = original), 1500);
            }
        });
    </script>
    @stack('scripts')
</body>
</html>
