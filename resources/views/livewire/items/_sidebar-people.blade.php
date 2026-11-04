{{-- Assignees, labels, participants. $canEdit, $panel, $assignees, $labels, $candidates, $allLabels, $participants --}}
<x-ui.side-section title="Assignees" name="assignees" :panel="$panel" :can="$canEdit">
    @forelse ($assignees as $u)
        <div class="flex items-center gap-2 py-0.5"><x-ui.avatar :user="$u" ring="" />{{ $u->name }}</div>
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
            <x-ui.label-chip :label="$l" />
        @empty
            <p class="text-slate-400">None yet</p>
        @endforelse
    </div>
    <x-slot:editor>
        <div class="space-y-1">
            @foreach ($allLabels as $l)
                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="labelIds" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600"> <x-ui.label-chip :label="$l" /></label>
            @endforeach
        </div>
        @can('manage-taxonomy')
            <a href="{{ route('settings.labels') }}" wire:navigate class="mt-2 inline-block text-xs text-brand-600 hover:underline" data-test="manage-labels">Manage labels</a>
        @endcan
    </x-slot:editor>
</x-ui.side-section>
