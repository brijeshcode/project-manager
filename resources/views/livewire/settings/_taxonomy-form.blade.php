{{-- Create/edit form shared by the type and label pages: name, colour, icon. Uses the host's $editing, name, color, icon, save, cancel; $noun, $max, $current, $hint, $placeholder (and optional $extra, a partial shown above the buttons) from the include. --}}
<form wire:submit="save" class="card mb-6 max-w-3xl p-6" data-test="taxonomy-form">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $current ? 'Edit '.$current->name : 'New '.$noun }}</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">{{ $hint }}</p>

    <div class="mt-5 grid gap-4 sm:grid-cols-[1fr_auto]">
        <div>
            <label for="tx-name" class="label">Name</label>
            <input id="tx-name" type="text" wire:model="name" maxlength="{{ $max }}" placeholder="{{ $placeholder }}" class="input" autocomplete="off" data-test="name">
            <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Must be unique, up to {{ $max }} characters.</p>
            @error('name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="tx-color" class="label">Colour</label>
            <div class="flex items-center gap-2">
                <input id="tx-color" type="color" wire:model="color" class="h-10 w-16 cursor-pointer rounded-lg border border-gray-300 dark:border-gray-600" data-test="color">
                <span class="font-mono text-xs text-slate-500 dark:text-gray-400" x-text="$wire.color">{{ $color }}</span>
            </div>
            @error('color') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
    </div>
    <div class="mt-4">
        <p class="label">Icon (optional)</p>
        <x-icon-picker model="icon" />
        @error('icon') <p class="mt-1 field-error">{{ $message }}</p> @enderror
    </div>
    @includeWhen(! empty($extra), $extra ?? '')

    <div class="mt-6 flex flex-wrap gap-3">
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save" data-test="save">{{ $current ? 'Save changes' : 'Add '.$noun }}</button>
        <button type="button" wire:click="cancel" class="btn btn-secondary">Cancel</button>
    </div>
</form>
