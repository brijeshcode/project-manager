{{-- Status for invoice $i: a badge when cancelled, otherwise the Not sent / Sent picker. $color, $label from the caller;
     optional $statusMethod (Livewire action, default setStatus) and $statusKey (suffix when one invoice shows twice on a page). --}}
@php $sk = $i->id.($statusKey ?? ''); @endphp
@if ($i->status === 'void')
    <x-ui.badge :color="$color">{{ $label }}</x-ui.badge>
@else
    @php $pickValue = $i->status; @endphp
    <label for="status-{{ $sk }}" class="sr-only">Status of {{ $i->number }}</label>
    <select id="status-{{ $sk }}" wire:change="{{ $statusMethod ?? 'setStatus' }}({{ $i->id }}, $event.target.value)" wire:confirm="Change this invoice's status? Sent counts in the client's balance and is emailed once." wire:key="status-{{ $sk }}-{{ $pickValue }}" class="badge badge-{{ $color }} cursor-pointer border-0 py-1 pl-2.5 pr-7 text-xs [color-scheme:light] focus:ring-2 focus:ring-brand-500 dark:[color-scheme:dark]" data-test="row-status">
        <option value="draft" class="bg-white text-slate-900 dark:bg-gray-800 dark:text-gray-100" @selected($pickValue === 'draft')>Not sent</option>
        <option value="sent" class="bg-white text-slate-900 dark:bg-gray-800 dark:text-gray-100" @selected($pickValue === 'sent')>Sent</option>
    </select>
@endif
