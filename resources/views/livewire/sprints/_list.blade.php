{{-- A plan list: header (count, hours, done/total for the active sprint) and a drop zone. $sprint (null = backlog), $rows, $progress, $canManage, $active, $empty. --}}
@php
    $fmt = fn ($h) => rtrim(rtrim(number_format($h, 1), '0'), '.').'h';
    $doneN = $rows->filter(fn ($t) => $t->column?->stage === 'done')->count();
@endphp
<x-ui.card padding="p-0" data-test="{{ $sprint ? 'sprint-list-'.$sprint->id : 'backlog-list' }}" wire:key="list-{{ $sprint?->id ?? 'backlog' }}">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-100 px-4 py-3 dark:border-gray-700">
        <div class="min-w-0 flex-1 basis-56">
            <div class="flex flex-wrap items-center gap-2">
                <x-icon :name="$sprint ? ($sprint->isActive() ? 'zap' : 'calendar-range') : 'archive'" class="h-4 w-4 text-slate-400" />
                <h3 class="font-display text-sm font-semibold text-slate-900" data-test="list-title">{{ $sprint ? $sprint->name : 'Backlog' }}</h3>
                @if ($sprint)
                    <x-ui.badge :color="$sprint->isActive() ? 'brand' : 'slate'">{{ ucfirst($sprint->status) }}</x-ui.badge>
                    <x-ui.sprint-dates :sprint="$sprint" />
                @else
                    <span class="text-xs text-slate-500">Open tasks that are not in a sprint</span>
                @endif
            </div>
            @if ($sprint?->goal)<p class="mt-1 text-xs text-slate-500" data-test="sprint-goal">{{ $sprint->goal }}</p>@endif
        </div>
        <span class="text-xs text-slate-500" data-test="list-count">@if ($sprint?->isActive()){{ $doneN }}/{{ $rows->count() }} done &middot; @endif{{ $rows->count() }} {{ Str::plural('task', $rows->count()) }}</span>
        <span class="text-xs font-medium text-slate-700" data-test="list-hours">{{ $fmt(\App\Models\Sprint::hours($rows)) }}</span>
        @if ($sprint?->isActive())
            <x-ui.progress :pct="$rows->isEmpty() ? 0 : $doneN / $rows->count() * 100" class="w-32" />
        @endif
        @if ($canManage && $sprint)
            <div class="flex items-center gap-1">
                @if ($sprint->isActive())
                    <button type="button" wire:click="openComplete({{ $sprint->id }})" class="btn btn-secondary h-8 gap-1 px-2.5 py-1 text-xs" data-test="complete-sprint"><x-icon name="circle-check-big" /> Complete</button>
                @elseif (! $active)
                    <button type="button" wire:click="openStart({{ $sprint->id }})" class="btn btn-primary h-8 gap-1 px-2.5 py-1 text-xs" data-test="start-sprint"><x-icon name="play" /> Start</button>
                @endif
                <button type="button" wire:click="openEdit({{ $sprint->id }})" class="p-1 text-slate-400 hover:text-brand-600" title="Edit sprint" aria-label="Edit sprint" data-test="edit-sprint"><x-icon name="pencil" /></button>
                @unless ($sprint->isActive())
                    <button type="button" wire:click="deleteSprint({{ $sprint->id }})" wire:confirm="Delete {{ $sprint->name }}? Its tasks go back to the backlog." class="p-1 text-slate-400 hover:text-rose-600" title="Delete sprint" aria-label="Delete sprint" data-test="delete-sprint"><x-icon name="trash-2" /></button>
                @endunless
            </div>
        @endif
    </div>
    <ul class="min-h-14 divide-y divide-slate-100 dark:divide-gray-700" @if ($canManage) x-data x-sortable data-sort-method="reorderList" data-sort-move="moveTask" data-sort-target="{{ $sprint?->id ?? 0 }}" data-sort-group="sprint-plan" @endif data-test="rows">
        @foreach ($rows as $task)
            @include('livewire.sprints._row', ['task' => $task, 'draggable' => $canManage])
        @endforeach
        @if ($rows->isEmpty())
            <li class="px-4 py-4 text-center text-sm text-slate-400" data-test="list-empty">{{ $empty }}</li>
        @endif
    </ul>
</x-ui.card>
