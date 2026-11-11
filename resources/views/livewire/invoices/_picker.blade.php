{{-- Work picker: Sprints / Modules / Tasks / Other charges tabs. Shared by create and edit (BuildsInvoiceItems). --}}
                <div class="card p-6">
                    <h2 class="{{ $h2 }}">Add completed work</h2>
                    <p class="{{ $hint }}">Tick a whole sprint, a whole module or single tasks. Each ticked sprint or module becomes one invoice item; ticked tasks become one item together. A task is never billed twice.</p>

                    @php
                        $tabs = ['sprints' => ['Sprints', $picker['sprints']->count()], 'modules' => ['Modules', $picker['modules']->count()], 'tasks' => ['Tasks', $picker['lines']->count()]];
                        if ($picker['charges']->isNotEmpty()) $tabs['charges'] = ['Other charges', $picker['charges']->count()];
                        if (! isset($tabs[$tab])) $tab = 'sprints';
                    @endphp
                    <div class="mt-4 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-gray-700" role="tablist">
                        @foreach ($tabs as $key => [$text, $n])
                            <button type="button" wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                                class="-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-brand-600 text-brand-700 dark:border-blue-400 dark:text-blue-300' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-gray-400 dark:hover:text-gray-200' }}">
                                {{ $text }} <span class="ml-1 rounded-full bg-slate-100 px-1.5 text-xs text-slate-600 dark:bg-gray-700 dark:text-gray-300">{{ $n }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        @if ($tab === 'sprints')
                            @forelse ($picker['sprints'] as $s)
                                @if ($loop->first)<ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-gray-700 dark:border-gray-700">@endif
                                <li wire:key="ps-{{ $s['sprint']->id }}">
                                    <label class="{{ $row }} cursor-pointer">
                                        <input type="checkbox" wire:model.live="pickSprints" value="{{ $s['sprint']->id }}" class="{{ $box }}" />
                                        <span class="min-w-0 flex-1">
                                            <span class="font-medium text-slate-800 dark:text-gray-100">{{ $s['sprint']->name }}</span>
                                            <span class="text-slate-500 dark:text-gray-400">({{ $s['sprint']->starts_on?->format('M j') }} – {{ $s['sprint']->ends_on?->format('M j') }})</span>
                                            @if ($s['sprint']->status !== 'completed')<x-ui.badge color="amber" class="ml-1">{{ $s['sprint']->status }}</x-ui.badge>@endif
                                            <span class="block text-xs text-slate-500 dark:text-gray-400">{{ $s['lines']->count() }} completed: {{ $s['lines']->map(fn ($l) => $l->task->code())->implode(', ') }}</span>
                                        </span>
                                        <span class="tabular-nums text-slate-800 dark:text-gray-100">{{ $raw($s['lines']) }}</span>
                                    </label>
                                </li>
                                @if ($loop->last)</ul>@endif
                            @empty
                                <p class="{{ $empty }}">No sprint has completed, unbilled tasks.</p>
                            @endforelse
                        @elseif ($tab === 'modules')
                            @forelse ($picker['modules'] as $m)
                                @if ($loop->first)<ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-gray-700 dark:border-gray-700">@endif
                                @php $blocked = $m['free']->isEmpty(); $partly = ! $blocked && $m['free']->count() < $m['lines']->count(); @endphp
                                <li wire:key="pm-{{ $m['module']->id }}">
                                    <label class="{{ $row }} {{ $blocked ? 'opacity-60' : 'cursor-pointer' }}">
                                        <input type="checkbox" wire:model.live="pickModules" value="{{ $m['module']->id }}" @disabled($blocked) class="{{ $box }}" />
                                        <span class="min-w-0 flex-1">
                                            <span class="font-medium text-slate-800 dark:text-gray-100">{{ $m['module']->name }}</span>
                                            <span class="block text-xs text-slate-500 dark:text-gray-400">
                                                {{ $m['free']->count() }} completed: {{ $m['free']->map(fn ($l) => $l->task->code())->implode(', ') ?: '—' }}
                                                @if ($blocked) · all in a ticked sprint @elseif ($partly) · {{ $m['lines']->count() - $m['free']->count() }} already in a ticked sprint @endif
                                            </span>
                                        </span>
                                        <span class="tabular-nums text-slate-800 dark:text-gray-100">{{ $raw($m['free']) }}</span>
                                    </label>
                                </li>
                                @if ($loop->last)</ul>@endif
                            @empty
                                <p class="{{ $empty }}">No module has completed, unbilled tasks.</p>
                            @endforelse
                        @elseif ($tab === 'tasks')
                            @forelse ($picker['lines'] as $l)
                                @if ($loop->first)<ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-gray-700 dark:border-gray-700">@endif
                                @php $why = in_array($l->id, $picker['bySprint'], true) ? 'in a ticked sprint' : (in_array($l->id, $picker['byModule'], true) ? 'in a ticked module' : null); @endphp
                                <li wire:key="pt-{{ $l->id }}">
                                    <label class="{{ $row }} {{ $why ? 'opacity-60' : 'cursor-pointer' }}">
                                        <input type="checkbox" wire:model.live="pickTasks" value="{{ $l->id }}" @disabled($why) class="{{ $box }}" />
                                        <span class="min-w-0 flex-1">
                                            <span class="text-slate-800 dark:text-gray-100">{{ $l->description }}</span>
                                            <span class="block text-xs text-slate-500 dark:text-gray-400">
                                                {{ $l->task->module?->name }}@if ($l->task->sprint) · {{ $l->task->sprint->name }}@endif
                                                @if ($l->task->completed_at) · done {{ $l->task->completed_at->format('M j') }}@endif
                                                @if ($why) · {{ $why }}@endif
                                            </span>
                                        </span>
                                        <span class="tabular-nums text-slate-800 dark:text-gray-100">{{ $raw(collect([$l])) }}</span>
                                    </label>
                                </li>
                                @if ($loop->last)</ul>@endif
                            @empty
                                <p class="{{ $empty }}">No completed, unbilled tasks.</p>
                            @endforelse
                        @else
                            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-gray-700 dark:border-gray-700">
                                @foreach ($picker['charges'] as $l)
                                    <li wire:key="pc-{{ $l->id }}" class="flex items-center">
                                        <label class="{{ $row }} flex-1 cursor-pointer">
                                            <input type="checkbox" wire:model.live="pickCharges" value="{{ $l->id }}" class="{{ $box }}" />
                                            <span class="min-w-0 flex-1 text-slate-800 dark:text-gray-100">{{ $l->description }} <span class="block text-xs text-slate-500 dark:text-gray-400">{{ $l->recurring_charge_id ? 'Recurring' : 'Manual' }}</span></span>
                                            <span class="tabular-nums text-slate-800 dark:text-gray-100">{{ $raw(collect([$l])) }}</span>
                                        </label>
                                        @unless ($l->recurring_charge_id)
                                            <button type="button" wire:click="discardLine({{ $l->id }})" wire:confirm="Delete this manual charge permanently?" class="btn btn-ghost mr-2 px-2 text-slate-400 hover:text-rose-600" title="Delete permanently" aria-label="Delete permanently">&times;</button>
                                        @endunless
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3">
                        <p class="text-sm text-slate-500 dark:text-gray-400">{{ $ticked ? $ticked.' ticked' : 'Nothing ticked yet' }}</p>
                        <div class="flex gap-2">
                            @if ($picker['lines']->isNotEmpty() || $picker['charges']->isNotEmpty())
                                <button type="button" wire:click="addAllWork" class="btn btn-secondary" title="One item per sprint, then per module, then each charge">Add everything</button>
                            @endif
                            <button type="button" wire:click="addPicked" @disabled(! $ticked) class="btn btn-primary disabled:opacity-50"><x-icon name="plus" class="h-4 w-4" />Add to invoice</button>
                        </div>
                    </div>
                </div>

