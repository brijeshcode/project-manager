<div data-test="sprint-panel">
    @php $fmt = fn ($h) => rtrim(rtrim(number_format($h, 1), '0'), '.').'h'; @endphp

    {{-- toolbar: filters, new sprint --}}
    <div class="mb-4 flex flex-wrap items-center gap-3" data-test="sprint-toolbar">
            <select wire:model.live="user" class="select h-9 w-40 text-sm" aria-label="Assigned to" data-test="sp-filter-user">
                <option value="">Everyone</option>
                @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
            </select>
            <select wire:model.live="type" class="select h-9 w-36 text-sm" aria-label="Type" data-test="sp-filter-type">
                <option value="">All types</option>
                @foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </select>
            <select wire:model.live="label" class="select h-9 w-36 text-sm" aria-label="Label" data-test="sp-filter-label">
                <option value="">All labels</option>
                @foreach ($labels as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
            </select>
        @if ($canManage)
            <button type="button" wire:click="openCreate" class="btn btn-primary ml-auto gap-1" data-test="new-sprint"><x-icon name="plus" /> New sprint</button>
        @endif
    </div>

        <div class="space-y-5" data-test="plan-view">
            {{-- Active sprint --}}
            @if ($active)
                @include('livewire.sprints._list', ['sprint' => $active, 'rows' => $activeTasks, 'empty' => 'Nothing in this sprint yet. Drag tasks here from the backlog.'])
            @else
                <x-ui.empty-state title="No active sprint" message="Plan a sprint, drag tasks into it, then start it. Only one sprint is active at a time." data-test="no-active">
                    @if ($canManage)
                        <x-slot:action><button type="button" wire:click="openCreate" class="btn btn-primary gap-1" data-test="start-sprint-cta"><x-icon name="play" /> Start a sprint</button></x-slot:action>
                    @endif
                </x-ui.empty-state>
            @endif

            {{-- Next sprints --}}
            @forelse ($planned as $sprint)
                @include('livewire.sprints._list', ['sprint' => $sprint, 'rows' => $tasks->where('sprint_id', $sprint->id)->values(), 'empty' => 'Empty. Drag tasks here from the backlog to plan this sprint.'])
            @empty
                <p class="text-sm text-slate-500" data-test="no-planned">No upcoming sprint planned.@if ($canManage) <button type="button" wire:click="openCreate" class="font-medium text-brand-600 hover:text-brand-700">Plan one</button>@endif</p>
            @endforelse

            {{-- Backlog --}}
            @include('livewire.sprints._list', ['sprint' => null, 'rows' => $backlog, 'empty' => 'The backlog is empty. Open tasks that are not in a sprint show up here.'])

            {{-- Past sprints --}}
            @if ($past->isNotEmpty())
                <div x-data="{ open: false }" data-test="past-sprints">
                    <button type="button" @click="open = !open" class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-slate-600 dark:text-gray-300" :aria-expanded="open" data-test="past-toggle">
                        <x-icon name="chevron-right" class="h-4 w-4 transition" ::class="open && 'rotate-90'" /> Past sprints ({{ $past->count() }})
                    </button>
                    <div x-show="open" style="display:none" class="space-y-3">
                        @foreach ($past as $p)
                            @php $pt = $pastTasks->get($p->id, collect())->filter(fn ($t) => $t->column?->stage === 'done')->values(); $isOpen = in_array($p->id, $openPast, true); @endphp
                            <x-ui.card padding="p-0" wire:key="past-{{ $p->id }}" data-test="past-sprint">
                                <button type="button" wire:click="togglePast({{ $p->id }})" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-left" data-test="past-expand">
                                    <x-icon name="chevron-right" class="h-4 w-4 text-slate-400 transition {{ $isOpen ? 'rotate-90' : '' }}" />
                                    <span class="font-display text-sm font-semibold text-slate-900">{{ $p->name }}</span>
                                    <span class="text-xs text-slate-500">{{ $p->starts_on->format('M j') }} – {{ $p->ends_on->format('M j, Y') }}</span>
                                    <span class="ml-auto text-xs text-slate-500" data-test="past-done">{{ $pt->count() }} done</span>
                                    <span class="text-xs font-medium text-slate-700">{{ $fmt(\App\Models\Sprint::hours($pt)) }}</span>
                                </button>
                                @if ($isOpen)
                                    <ul class="divide-y divide-slate-100 border-t border-slate-100 dark:divide-gray-700 dark:border-gray-700" data-test="past-tasks">
                                        @forelse ($pt as $task)
                                            @include('livewire.sprints._row', ['task' => $task, 'draggable' => false, 'canManage' => false])
                                        @empty
                                            <li class="px-4 py-3 text-sm text-slate-400">No finished tasks in this sprint.</li>
                                        @endforelse
                                    </ul>
                                @endif
                            </x-ui.card>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

    {{-- sprint form: create / edit / start --}}
    @if ($formMode)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" data-test="sprint-form" x-data x-on:keydown.escape.window="$wire.closeForm()">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80" wire:click="closeForm"></div>
                <form wire:submit="saveForm" class="card relative w-full max-w-md space-y-4 p-6">
                    <h2 class="font-display text-lg font-semibold text-slate-900">{{ ['create' => 'Plan a sprint', 'edit' => 'Edit sprint', 'start' => 'Start sprint'][$formMode] }}</h2>
                    @error('sprint') <p class="field-error" data-test="sprint-error">{{ $message }}</p> @enderror
                    <div>
                        <label class="label" for="sp-name">Name</label>
                        <input id="sp-name" type="text" wire:model="name" class="input" maxlength="80" data-test="sp-name">
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp-goal">Goal</label>
                        <textarea id="sp-goal" wire:model="goal" rows="2" class="input" placeholder="What should this sprint achieve?" data-test="sp-goal"></textarea>
                        @error('goal') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp-start">Starts on</label>
                        <div class="flex items-center gap-2">
                            <input id="sp-start" type="date" wire:model="startsOn" class="input" data-test="sp-start">
                            @if ($formMode === 'create')
                                <button type="button" wire:click="nextMonday" class="btn btn-ghost h-9 whitespace-nowrap px-2 text-xs" data-test="next-monday">Next Monday</button>
                            @endif
                        </div>
                        @error('startsOn') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($formMode === 'create')
                        <fieldset>
                            <legend class="label">Length</legend>
                            <div class="flex gap-4 text-sm">
                                @foreach ([1 => '1 week', 2 => '2 weeks'] as $w => $wl)
                                    <label class="flex items-center gap-2"><input type="radio" wire:model="weeks" value="{{ $w }}" data-test="weeks-{{ $w }}"> {{ $wl }}</label>
                                @endforeach
                            </div>
                            @error('weeks') <p class="field-error">{{ $message }}</p> @enderror
                        </fieldset>
                    @else
                        <div>
                            <label class="label" for="sp-end">Ends on</label>
                            <input id="sp-end" type="date" wire:model="endsOn" class="input" data-test="sp-end">
                            <p class="mt-1 text-xs text-slate-500">One or two weeks.</p>
                            @error('endsOn') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary" data-test="sp-save">{{ ['create' => 'Create sprint', 'edit' => 'Save', 'start' => 'Start sprint'][$formMode] }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- complete dialog --}}
    @if ($completing)
        @php
            $sum = $completing->summary();
            $nextPlanned = $planned->first();
        @endphp
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" data-test="complete-dialog" x-data x-on:keydown.escape.window="$wire.closeForm()">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80" wire:click="closeForm"></div>
                <form wire:submit="completeSprint" class="card relative w-full max-w-md space-y-4 p-6">
                    <h2 class="font-display text-lg font-semibold text-slate-900">Complete {{ $completing->name }}</h2>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <dt class="text-slate-500">Done</dt><dd class="text-right font-medium" data-test="sum-done">{{ $sum['done']->count() }} of {{ $sum['all']->count() }} tasks</dd>
                        <dt class="text-slate-500">Unfinished</dt><dd class="text-right font-medium" data-test="sum-open">{{ $sum['open']->count() }} {{ Str::plural('task', $sum['open']->count()) }}</dd>
                        <dt class="text-slate-500">Planned hours</dt><dd class="text-right font-medium" data-test="sum-planned">{{ $fmt(\App\Models\Sprint::hours($sum['all'])) }}</dd>
                        <dt class="text-slate-500">Completed hours</dt><dd class="text-right font-medium" data-test="sum-completed">{{ $fmt(\App\Models\Sprint::hours($sum['done'])) }}</dd>
                    </dl>
                    @if ($sum['open']->isNotEmpty())
                        <fieldset class="space-y-2 text-sm">
                            <legend class="label">Unfinished tasks</legend>
                            <label class="flex items-start gap-2"><input type="radio" wire:model="rollover" value="next" class="mt-1" data-test="rollover-next"> <span>Move to {{ $nextPlanned ? $nextPlanned->name : 'a new sprint ('.\App\Models\Sprint::nextName($project->id).')' }}</span></label>
                            <label class="flex items-start gap-2"><input type="radio" wire:model="rollover" value="backlog" class="mt-1" data-test="rollover-backlog"> <span>Back to the backlog</span></label>
                        </fieldset>
                    @endif
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary" data-test="confirm-complete">Complete sprint</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
