{{-- Create/edit form shared by the type and label pages: name, colour, icon. Uses the host's $editing, name, color, icon, save, cancel. --}}
<form wire:submit="save" class="card mb-6 space-y-4 p-5" data-test="taxonomy-form">
    <h2 class="text-sm font-semibold text-slate-700 dark:text-gray-200">{{ $editing ? 'Edit '.$noun : 'New '.$noun }}</h2>
    <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
        <div>
            <label for="tx-name" class="label">Name</label>
            <input id="tx-name" type="text" wire:model="name" maxlength="50" class="input" autocomplete="off" data-test="name">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="tx-color" class="label">Colour</label>
            <input id="tx-color" type="color" wire:model="color" class="h-10 w-16 cursor-pointer rounded border border-slate-300 dark:border-gray-600" data-test="color">
            @error('color') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </div>
    <div>
        <p class="label">Icon</p>
        <x-icon-picker model="icon" />
        @error('icon') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div class="flex gap-2">
        <button type="submit" class="btn btn-primary" data-test="save">Save</button>
        <button type="button" wire:click="cancel" class="btn btn-secondary">Cancel</button>
    </div>
</form>
