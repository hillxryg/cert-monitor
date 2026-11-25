@php
    $input = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
@endphp
<form method="POST" action="{{ $action }}" class="space-y-8">
    @csrf
    @if ($method !== 'POST') @method($method) @endif

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="font-semibold text-slate-900">Campaign</h2>

        <x-form-field name="title" label="Title">
            <input id="title" name="title" type="text" required value="{{ old('title', $campaign->title) }}" class="{{ $input }}">
        </x-form-field>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-form-field name="platform_name" label="Platform name" hint="e.g. Coursera, Udemy (optional)">
                <input id="platform_name" name="platform_name" type="text" value="{{ old('platform_name', $campaign->platform_name) }}" class="{{ $input }}">
            </x-form-field>

            <x-form-field name="target_url_pattern" label="Target URL pattern" hint="Pages the extension monitors, e.g. https://*.coursera.org/learn/*">
                <input id="target_url_pattern" name="target_url_pattern" type="text" required value="{{ old('target_url_pattern', $campaign->target_url_pattern) }}" class="{{ $input }}">
            </x-form-field>
        </div>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="font-semibold text-slate-900">Completion & integrity</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-form-field name="required_completion_percent" label="Required verified completion (%)" hint="Share of each lesson that must be verified as watched or read.">
                <input id="required_completion_percent" name="required_completion_percent" type="number" min="1" max="100" step="1" required
                       value="{{ old('required_completion_percent', $campaign->required_completion_percent) }}" class="{{ $input }}">
            </x-form-field>

            <x-form-field name="min_integrity_score" label="Minimum integrity score (0–100)" hint="Students below this are flagged.">
                <input id="min_integrity_score" name="min_integrity_score" type="number" min="0" max="100" step="1" required
                       value="{{ old('min_integrity_score', $campaign->min_integrity_score) }}" class="{{ $input }}">
            </x-form-field>
        </div>

        <x-form-field name="expected_lessons" label="Expected modules (optional)" hint="Total modules in the certification, e.g. 12. Overall completion is measured against this, so finishing 3 of 12 reads 25%. Leave blank to average only the modules a student has started.">
            <input id="expected_lessons" name="expected_lessons" type="number" min="1" max="500" step="1"
                   value="{{ old('expected_lessons', $campaign->expected_lessons) }}" class="{{ $input }}">
        </x-form-field>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="font-semibold text-slate-900">Playback rules</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-form-field name="max_playback_speed" label="Max playback speed (×)" hint="Faster playback earns no credit and is logged.">
                <input id="max_playback_speed" name="max_playback_speed" type="number" min="0.25" max="4" step="0.05" required
                       value="{{ old('max_playback_speed', $campaign->max_playback_speed) }}" class="{{ $input }}">
            </x-form-field>

            <x-form-field name="max_skip_seconds" label="Max forward skip (seconds)" hint="Larger forward jumps are logged as timeline skips.">
                <input id="max_skip_seconds" name="max_skip_seconds" type="number" min="0" max="3600" step="1" required
                       value="{{ old('max_skip_seconds', $campaign->max_skip_seconds) }}" class="{{ $input }}">
            </x-form-field>
        </div>

        <label class="flex items-start gap-3 text-sm">
            <input type="checkbox" name="allow_muted_playback" value="1" class="mt-0.5 rounded border-slate-300 text-indigo-600"
                   @checked(old('allow_muted_playback', $campaign->allow_muted_playback))>
            <span>
                <span class="font-medium text-slate-800">Allow muted playback</span>
                <span class="block text-xs text-slate-500">When off, video playing while muted earns no credit and is logged.</span>
            </span>
        </label>
        @error('allow_muted_playback')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </section>

    <div class="flex items-center justify-end gap-3">
        <a href="{{ $cancel }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ $submit }}</button>
    </div>
</form>
