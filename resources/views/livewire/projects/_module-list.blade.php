{{-- Modules tab list (admin and portal): filter bar, rows, Completed section. Data from FiltersModules::moduleRows(); $client hides the user filter and drag. --}}
@php $client ??= false; @endphp
                <div class="mb-4 flex flex-wrap items-center gap-3" data-test="module-filters">
                    <div class="inline-flex overflow-hidden rounded-lg border border-slate-200 text-sm dark:border-gray-700" role="group" aria-label="Module filter">
                        @foreach (['sprint' => 'In active sprint', 'backlog' => 'Not in sprint', 'completed' => 'Completed', 'all' => 'All'] as $k => $lbl)
                            <button type="button" wire:click="$set('filter', '{{ $k }}')" aria-pressed="{{ $filter === $k ? 'true' : 'false' }}" data-test="filter-{{ $k }}" @class(['px-3 py-1.5 font-medium', 'bg-brand-600 text-white' => $filter === $k, 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-transparent dark:text-gray-300' => $filter !== $k])>{{ $lbl }}</button>
                        @endforeach
                    </div>
                    @unless ($client)
                    <select wire:model.live="assignedTo" class="select h-9 w-48 text-sm" aria-label="Assigned to" data-test="filter-user">
                        <option value="">Everyone</option>
                        @foreach ($userOptions as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                    @endunless
                </div>

                @php
                    $help = match ($filter) {
                        'sprint' => $activeSprint ? 'Showing modules that have at least one task in '.$activeSprint->name : ($client ? 'No sprint is active right now' : 'No active sprint — start one in the Sprint tab'),
                        'backlog' => 'Showing modules that are not completed and have no task in the active sprint',
                        'completed' => 'Showing completed modules',
                        default => 'Showing all modules; completed ones are in the section at the bottom',
                    };
                    $helpUser = ! $client && $assignedTo !== '' ? $userOptions->firstWhere('id', (int) $assignedTo) : null;
                @endphp
                <p class="-mt-2 mb-4 text-xs text-slate-500 dark:text-gray-400" data-test="filter-help">{{ $help }}@if ($helpUser). Only modules assigned to {{ $helpUser->name }}, or with a task assigned to them.@endif</p>

                <div @unless ($client) x-data x-sortable data-sort-method="reorderModules" data-sort-handle=".module-handle" @endunless data-test="module-rows">
                    @foreach ($mainRows as $r)
                        @php($module = $r['m'])
                        @php($mStatus = $r['status'])
                        @include('livewire.projects._module-row')
                    @endforeach
                </div>

                @if ($modules->isEmpty())
                    @if ($client)
                        <x-ui.empty-state title="No modules shared" message="Modules shared with you will appear here." />
                    @else
                        <x-ui.empty-state title="No modules yet" message="Modules group related tasks — like “Checkout” or “Onboarding”. Add one to start building the plan."></x-ui.empty-state>
                    @endif
                @elseif ($filter === 'sprint' && ! $activeSprint)
                    <x-ui.empty-state title="No active sprint" :message="$client ? 'Nothing is in progress right now.' : 'Start a sprint in the Sprint tab to see which modules are part of it.'" data-test="module-no-sprint"></x-ui.empty-state>
                @elseif ($mainRows->isEmpty() && $doneRows->isEmpty())
                    <x-ui.empty-state title="No modules match" :message="$client ? 'Nothing fits this filter. Try another one.' : 'Nothing fits this filter. Try another one, or pick Everyone.'" data-test="module-empty"></x-ui.empty-state>
                @endif

                @if ($doneRows->isNotEmpty())
                    <div x-data="{ done: false }" class="mt-2" data-test="completed-section">
                        <button type="button" @click="done = !done" class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-green-700 dark:text-green-400" :aria-expanded="done" data-test="completed-toggle">
                            <x-icon name="chevron-right" class="h-4 w-4 transition" ::class="done && 'rotate-90'" /> Completed ({{ $doneRows->count() }})
                        </button>
                        <div x-show="done" style="display:none" data-test="completed-rows">
                            @foreach ($doneRows as $r)
                                @php($module = $r['m'])
                                @php($mStatus = $r['status'])
                                @include('livewire.projects._module-row')
                            @endforeach
                        </div>
                    </div>
                @endif
