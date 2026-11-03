{{-- Icon picker shared by the type and label pages: a grid of the curated icons plus "or type an emoji". $model is the Livewire property holding the value. --}}
@props(['model' => 'icon'])
<div x-data="{ v: $wire.entangle('{{ $model }}') }" class="space-y-2" data-test="icon-picker">
    <div class="flex flex-wrap gap-1">
        <button type="button" @click="v = ''" :class="!v ? 'ring-2 ring-brand-500' : 'ring-1 ring-slate-200 dark:ring-gray-600'" class="flex h-8 items-center rounded-md px-2 text-xs text-slate-500" title="No icon" aria-label="No icon">None</button>
        @foreach (\App\Support\Icon::PICKER as $name)
            <button type="button" @click="v = '{{ $name }}'" :class="v === '{{ $name }}' ? 'ring-2 ring-brand-500 bg-brand-50 dark:bg-gray-700' : 'ring-1 ring-slate-200 dark:ring-gray-600'" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-600 hover:bg-slate-50 dark:text-gray-300" title="{{ $name }}" aria-label="Icon {{ $name }}" data-icon="{{ $name }}"><x-icon :name="$name" class="h-4 w-4" /></button>
        @endforeach
    </div>
    <label class="flex items-center gap-2 text-xs text-slate-500">or type an emoji
        <input type="text" x-model="v" maxlength="16" class="input h-8 w-28 text-center text-sm" placeholder="e.g. 🔥" autocomplete="off" data-test="icon-text">
    </label>
</div>
