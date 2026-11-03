<div>
    @php
        $progress = round($module->progress() * 100);
        $openTasks = $module->openTaskCount();
        $money = auth()->user()->can('manage-money');
    @endphp

    <div class="mb-6">
        <a href="{{ route('projects.show', ['project' => $project, 'tab' => 'modules']) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; {{ $project->name }}
        </a>
    </div>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display text-2xl font-bold tracking-tight text-slate-900">{{ $module->name }}</h1>
                <x-ui.badge :color="\App\Models\Module::STATUS_COLORS[$status]" data-test="status-badge">{{ \App\Models\Module::STATUS_LABELS[$status] }}</x-ui.badge>
                @if ($module->client_visible)
                    <x-ui.badge color="brand">Visible to client</x-ui.badge>
                @endif
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $project->client->name }} &middot; {{ $project->name }}</p>
        </div>
        @if ($canManage && ! $editing)
            <div class="flex flex-wrap items-center gap-2">
                @if ($module->status_override)
                    <button wire:click="reopen" class="btn btn-secondary">Reopen</button>
                @else
                    <button wire:click="putOnHold" class="btn btn-secondary">On hold</button>
                @endif
                @if ($module->status_override !== 'completed')
                    @if ($openTasks > 0)
                        <button wire:click="markCompleted" wire:confirm="{{ $openTasks }} {{ Str::plural('task', $openTasks) }} still open. Mark this module completed anyway?" class="btn btn-secondary">Mark completed</button>
                    @else
                        <button wire:click="markCompleted" class="btn btn-secondary">Mark completed</button>
                    @endif
                @endif
                <button wire:click="edit" class="btn btn-primary">Edit module</button>
            </div>
        @endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($editing)
                <form wire:submit="save" class="card space-y-4 p-5">
                    <div>
                        <label class="label">Name</label>
                        <input type="text" wire:model="name" class="input">
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Description</label>
                        <x-ui.markdown-editor model="description" :rows="8" />
                        @error('description') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Deadline</label>
                            <input type="date" wire:model="deadline" class="input">
                            @error('deadline') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Reason for deadline change <span class="font-normal text-slate-400">(optional)</span></label>
                            <input type="text" wire:model="deadlineReason" class="input">
                        </div>
                    </div>
                    <div>
                        <label class="label">Assignees</label>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            @forelse ($candidates as $u)
                                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="assigneeIds" value="{{ $u->id }}" class="rounded border-slate-300 text-brand-600"> {{ $u->name }}</label>
                            @empty
                                <p class="text-sm text-slate-400">No project members yet.</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <label class="label">Labels</label>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            @foreach ($allLabels as $l)
                                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="labelIds" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600"> <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $l->color }}"></span> {{ $l->name }}</label>
                            @endforeach
                        </div>
                        <div class="mt-2 flex gap-2">
                            <input type="text" wire:model="newLabel" wire:keydown.enter.prevent="addLabel" placeholder="New label…" class="input h-8 max-w-xs text-sm">
                            <input type="color" wire:model="newLabelColor" class="h-8 w-10 rounded border border-slate-300">
                            <button type="button" wire:click="addLabel" class="btn btn-secondary h-8 px-2.5 py-1 text-xs">Add label</button>
                        </div>
                        @error('newLabel') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="clientVisible" class="rounded border-slate-300 text-brand-600"> Visible to the client in their portal</label>

                    @if ($money)
                        <div class="rounded-lg border border-slate-200 p-3">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Budget</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @if ($hourly)
                                    <div><label class="label">Budget hours</label><input type="number" step="0.01" min="0" wire:model="hours" class="input"></div>
                                    <div><label class="label">Hourly rate ({{ $project->currency }})</label><input type="text" wire:model="rate" class="input"></div>
                                @else
                                    <div><label class="label">Fixed amount ({{ $project->currency }})</label><input type="text" wire:model="amount" class="input"></div>
                                @endif
                                <div class="sm:col-span-2"><label class="label">Reason <span class="font-normal text-slate-400">(required when changing an existing value)</span></label><input type="text" wire:model="budgetReason" class="input"></div>
                            </div>
                            @foreach (['hours', 'rate', 'amount', 'budgetReason'] as $f)
                                @error($f) <p class="field-error">{{ $message }}</p> @enderror
                            @endforeach
                        </div>
                    @endif

                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-primary">Save</button>
                        <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                    </div>
                </form>
            @else
                <x-ui.card>
                    @if ($module->description)
                        <x-ui.markdown :text="$module->description" />
                    @elseif ($canManage)
                        <div class="space-y-2" data-test="empty-description">
                            <p class="text-sm font-medium text-slate-700">No description yet. Add one:</p>
                            <x-ui.markdown-editor model="description" :rows="5" />
                            @error('description') <p class="field-error">{{ $message }}</p> @enderror
                            <button wire:click="saveDescription" class="btn btn-primary h-8 px-3 py-1 text-xs">Save description</button>
                        </div>
                    @else
                        <p class="text-sm text-slate-400">No description yet.</p>
                    @endif
                </x-ui.card>
            @endif

            {{-- Progress + tasks --}}
            <x-ui.card padding="p-0">
                <div class="border-b border-slate-100 px-5 py-4">
                    <div class="flex items-center justify-between text-sm">
                        <h2 class="font-display font-semibold text-slate-900">Progress</h2>
                        <span class="font-semibold tabular-nums text-slate-600">{{ $progress }}%</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-blue-500" style="width: {{ $progress }}%"></div>
                    </div>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($tasks as $task)
                        <li class="flex items-center gap-3 px-5 py-3">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 hover:text-brand-600">{{ $task->title }}</a>
                            <x-ui.badge color="slate">{{ $task->column?->name }}</x-ui.badge>
                            @if ($canManage)
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:text-brand-700">Edit</a>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-3 text-sm text-slate-400">No tasks in this module yet.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            {{-- Timeline --}}
            <div>
                <h2 class="mb-3 font-display text-lg font-semibold text-slate-900">Timeline</h2>
                <x-ui.card class="mb-4 space-y-2">
                    <x-ui.markdown-editor model="commentBody" :rows="3" placeholder="Leave a comment…" />
                    @error('commentBody') <p class="field-error">{{ $message }}</p> @enderror
                    <button wire:click="postComment" class="btn btn-primary h-8 px-3 py-1 text-xs">Comment</button>
                </x-ui.card>

                <ol class="space-y-3" data-test="timeline">
                    @forelse ($timeline as $item)
                        @php($m = $item['m'])
                        @if ($item['kind'] === 'comment')
                            <li class="card p-4" data-kind="comment">
                                <div class="mb-1 text-xs text-slate-500"><span class="font-semibold text-slate-800">{{ $m->author?->name }}</span> commented &middot; {{ $m->created_at->diffForHumans() }}</div>
                                <x-ui.markdown :text="$m->body" />
                                @foreach ($m->replies as $r)
                                    <div class="mt-3 border-l-2 border-slate-200 pl-3">
                                        <div class="mb-1 text-xs text-slate-500"><span class="font-semibold text-slate-800">{{ $r->author?->name }}</span> replied &middot; {{ $r->created_at->diffForHumans() }}</div>
                                        <x-ui.markdown :text="$r->body" />
                                    </div>
                                @endforeach
                                @if ($replyTo === $m->id)
                                    <div class="mt-3 space-y-2">
                                        <x-ui.markdown-editor model="replyBody" :rows="3" :uploads="false" placeholder="Write a reply…" />
                                        @error('replyBody') <p class="field-error">{{ $message }}</p> @enderror
                                        <div class="flex gap-2">
                                            <button wire:click="postReply" class="btn btn-primary h-7 px-2.5 py-1 text-xs">Reply</button>
                                            <button wire:click="$set('replyTo', null)" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">Cancel</button>
                                        </div>
                                    </div>
                                @else
                                    <button wire:click="startReply({{ $m->id }})" class="mt-2 text-xs font-medium text-brand-600 hover:text-brand-700">Reply</button>
                                @endif
                            </li>
                        @elseif ($item['kind'] === 'activity')
                            <li class="px-1 text-xs text-slate-500" data-kind="activity">
                                <span class="font-semibold text-slate-700">{{ $m->user?->name ?? 'System' }}</span> {{ $m->summary() }}@if ($m->reason) <span class="italic">&mdash; {{ $m->reason }}</span>@endif &middot; {{ $m->created_at->diffForHumans() }}
                            </li>
                        @else
                            <li class="px-1 text-xs text-slate-500" data-kind="budget">
                                <span class="font-semibold text-slate-700">{{ $m->user?->name ?? 'System' }}</span> changed budget {{ str_replace('_', ' ', $m->field) }} from {{ $m->old ?? 'none' }} to {{ $m->new ?? 'none' }}@if ($m->reason) <span class="italic">&mdash; {{ $m->reason }}</span>@endif &middot; {{ $m->created_at->diffForHumans() }}
                            </li>
                        @endif
                    @empty
                        <li class="text-sm text-slate-400">Nothing yet.</li>
                    @endforelse
                </ol>
            </div>
        </div>

        {{-- Sidebar --}}
        <aside class="space-y-4">
            <x-ui.card class="space-y-4 text-sm">
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Assignees</p>
                    @forelse ($assignees as $u)
                        <div class="flex items-center gap-2 py-0.5">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700">{{ collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                            {{ $u->name }}
                        </div>
                    @empty
                        <p class="text-slate-400">Unassigned</p>
                    @endforelse
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Labels</p>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse ($labels as $l)
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200"><span class="h-2 w-2 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}</span>
                        @empty
                            <p class="text-slate-400">None</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Deadline</p>
                    @if ($module->deadline)
                        <span @class(['text-rose-600 font-medium' => $module->deadline->isPast() && $status !== 'completed'])>{{ $module->deadline->format('M j, Y') }}</span>
                    @else
                        <p class="text-slate-400">Not set</p>
                    @endif
                </div>
                @if ($money)
                    <div>
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Budget</p>
                        @if ($module->budgetAmount() === null)
                            <p class="text-slate-400">Not set</p>
                        @else
                            <p>{{ \App\Support\Money::format(\App\Support\Money::toCents($module->budgetAmount()), $project->currency) }}</p>
                            @if ($hourly && $module->budget_hours !== null)
                                <p class="text-xs text-slate-500">{{ $module->budget_hours }} h @ {{ $module->hourly_rate }}</p>
                            @endif
                        @endif
                    </div>
                @endif
            </x-ui.card>
        </aside>
    </div>
</div>
