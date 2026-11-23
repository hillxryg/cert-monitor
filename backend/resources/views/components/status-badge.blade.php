@props(['status'])
@php
    $map = [
        'in_progress' => ['In progress', 'bg-amber-50 text-amber-800 ring-amber-200'],
        'flagged' => ['Flagged', 'bg-red-50 text-red-700 ring-red-200'],
        'verified_complete' => ['Verified complete', 'bg-emerald-50 text-emerald-800 ring-emerald-200'],
        'active' => ['Open', 'bg-emerald-50 text-emerald-800 ring-emerald-200'],
        'closed' => ['Closed', 'bg-slate-100 text-slate-700 ring-slate-300'],
        'draft' => ['Draft', 'bg-slate-100 text-slate-700 ring-slate-300'],
    ];
    [$label, $classes] = $map[$status] ?? [ucfirst((string) $status), 'bg-slate-100 text-slate-700 ring-slate-300'];
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>{{ $label }}</span>
