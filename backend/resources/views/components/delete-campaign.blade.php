@props(['campaign', 'solid' => false])
{{-- Hard-delete form with a native confirm() prompt. @js() makes the title safe inside the attribute. --}}
<form method="POST" action="{{ route('campaigns.destroy', $campaign) }}"
      onsubmit="return confirm(@js('Permanently delete “'.$campaign->title.'”? This also deletes every student’s progress and integrity logs, and cannot be undone.'))">
    @csrf
    @method('DELETE')
    <button type="submit"
            class="{{ $solid
                ? 'rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500'
                : 'rounded-md px-3 py-1.5 font-medium text-red-600 hover:bg-red-50' }}">
        {{ $slot->isEmpty() ? 'Delete' : $slot }}
    </button>
</form>
