{{-- Assignees, labels, participants. $canEdit, $panel, $assignees, $labels, $candidates, $allLabels, $participants --}}
@php($initials = fn ($u) => collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode(''))
<x-ui.side-section title="Assignees" name="assignees" :panel="$panel" :can="$canEdit">
    @forelse ($assignees as $u)
        <div class="flex items-center gap-2 py-0.5"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700">{{ $initials($u) }}</span>{{ $u->name }}</div>
    @empty
        <p class="text-slate-400">No one assigned</p>
    @endforelse
    <x-slot:editor>
        <div class="space-y-1">
            @forelse ($candidates as $u)
                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="assigneeIds" value="{{ $u->id }}" class="rounded border-slate-300 text-brand-600"> {{ $u->name }}</label>
            @empty
                <p class="text-slate-400">No project members yet.</p>
            @endforelse
        </div>
    </x-slot:editor>
</x-ui.side-section>
<x-ui.side-section title="Labels" name="labels" :panel="$panel" :can="$canEdit">
    <div class="flex flex-wrap gap-1.5">
        @forelse ($labels as $l)
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200"><span class="h-2 w-2 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}</span>
        @empty
            <p class="text-slate-400">None yet</p>
        @endforelse
    </div>
    <x-slot:editor>
        <div class="space-y-1">
            @foreach ($allLabels as $l)
                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="labelIds" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600"> <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $l->color }}"></span> {{ $l->name }}</label>
            @endforeach
        </div>
        <div class="mt-2 flex gap-1.5">
            <input type="text" wire:model="newLabel" wire:keydown.enter.prevent="addLabel" placeholder="New label…" class="input h-8 min-w-0 flex-1 text-sm">
            <input type="color" wire:model="newLabelColor" class="h-8 w-9 rounded border border-slate-300">
            <button type="button" wire:click="addLabel" class="btn btn-secondary h-8 px-2 py-1 text-xs">Add</button>
        </div>
        @error('newLabel') <p class="field-error">{{ $message }}</p> @enderror
    </x-slot:editor>
</x-ui.side-section>
