@props(['code'])
<button type="button" data-copy="{{ $code }}" title="Copy join code"
        {{ $attributes->class(['group inline-flex items-center gap-2 rounded-lg border border-dashed border-indigo-300 bg-indigo-50 px-3 py-1.5 text-left hover:bg-indigo-100']) }}>
    <span class="font-mono text-sm font-semibold tracking-widest text-indigo-900">{{ $code }}</span>
    <span data-copy-label class="text-xs font-medium text-indigo-600">Copy</span>
</button>
