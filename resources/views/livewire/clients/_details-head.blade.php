{{-- Details tab card header: $title, $icon (x-icon name), edit link that opens just this card's part of the client form --}}
@php($editSection = ['Basic information' => 'basic', 'Contact' => 'contact', 'Billing' => 'billing', 'Address' => 'address', 'Internal notes' => 'notes'][$title] ?? null)
<div class="mb-5 flex items-center justify-between gap-3">
    <h2 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-white">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"><x-icon :name="$icon" class="h-4 w-4" /></span>{{ $title }}
    </h2>
    <a href="{{ route('clients.edit', array_filter([$client, 'section' => $editSection])) }}" wire:navigate class="link text-sm" data-test="edit-{{ $editSection ?? 'all' }}">Edit</a>
</div>
