{{-- Expense categories: optional uploaded icon image, shown instead of the picked icon. --}}
<div class="mt-4">
    <label for="cat-image" class="label">or upload your own (PNG, JPG, WebP or GIF, up to 512 KB)</label>
    <div class="flex flex-wrap items-center gap-3">
        @if ($icon_path && ! $image)
            <img src="{{ Storage::disk('public')->url($icon_path) }}" alt="Current image" class="h-8 w-8 rounded object-cover" data-test="current-image">
            <button type="button" wire:click="removeImage" class="text-sm font-medium text-rose-600 hover:underline dark:text-rose-400" data-test="remove-image">Remove image</button>
        @endif
        <input id="cat-image" type="file" wire:model="image" accept="image/png,image/jpeg,image/webp,image/gif" class="text-sm" data-test="image">
    </div>
    <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">An image wins over the icon above.</p>
    @error('image') <p class="mt-1 field-error">{{ $message }}</p> @enderror
</div>
