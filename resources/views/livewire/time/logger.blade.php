<div>
    <form wire:submit="log" class="flex flex-wrap items-end gap-2">
        <div class="flex-1 min-w-24">
            <label class="label text-xs">Hours</label>
            <input type="number" step="0.25" min="0.01" wire:model="hours"
                   placeholder="0.0" class="input h-8 py-1 text-sm">
            @error('hours') <p class="field-error text-xs">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1 min-w-32">
            <label class="label text-xs">Date</label>
            <input type="date" wire:model="worked_on" class="input h-8 py-1 text-sm">
            @error('worked_on') <p class="field-error text-xs">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary h-8 px-3 py-1 text-xs whitespace-nowrap">Log time</button>
    </form>

    @if($entries->isNotEmpty())
        <ul class="mt-3 divide-y divide-slate-100">
            @foreach($entries as $entry)
                <li class="flex items-center justify-between py-1.5 text-xs text-slate-600">
                    <span>{{ $entry->worked_on->format('M j') }}</span>
                    <span class="font-semibold text-slate-800">{{ $entry->hours }}h</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
