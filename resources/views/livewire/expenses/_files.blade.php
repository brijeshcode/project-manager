{{-- Bills/receipts: existing $attachments (remove calls $remove(id)) plus a multi-file input bound to $field. --}}
@if (($attachments ?? collect())->isNotEmpty())
    <ul class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-gray-700 dark:border-gray-700" data-test="files">
        @foreach ($attachments as $a)
            <li wire:key="att-{{ $a->id }}" class="flex items-center gap-2 px-3 py-2 text-sm">
                <x-icon name="file-text" class="h-4 w-4 text-slate-400" />
                <a href="{{ $a->url() }}" class="link min-w-0 flex-1 truncate">{{ $a->name }}</a>
                <button type="button" wire:click="{{ $remove }}({{ $a->id }})" wire:confirm="Delete this file?" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete {{ $a->name }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
            </li>
        @endforeach
    </ul>
@endif
<div class="mt-4">
    <label for="{{ $field }}" class="label">{{ ($attachments ?? collect())->isNotEmpty() ? 'Add files' : 'Files' }} (optional)</label>
    <input wire:model="{{ $field }}" id="{{ $field }}" type="file" multiple class="input" />
    <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Bills or receipts, 10 MB each.</p>
    @error($field) <p class="mt-1 field-error">{{ $message }}</p> @enderror
    @error($field.'.*') <p class="mt-1 field-error">{{ $message }}</p> @enderror
</div>
