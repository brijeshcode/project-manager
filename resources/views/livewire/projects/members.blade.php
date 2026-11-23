<div x-data="{ ask: null }">
    <x-ui.card>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Members</h2>
            <span class="text-xs text-slate-500 dark:text-gray-400">{{ $members->where('pivot.active', true)->count() }}</span>
        </div>
        @if ($members->isEmpty())
            <p class="text-sm text-slate-500 dark:text-gray-400">No members assigned yet.</p>
        @else
            <ul class="space-y-2.5" data-test="members">
                @foreach ($members as $m)
                    @php $mi = collect(explode(' ', trim($m->name)))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode(''); @endphp
                    <li wire:key="m-{{ $m->id }}" @class(['flex items-center gap-3', 'opacity-60' => ! $m->pivot->active]) data-test="member-{{ $m->id }}">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $mi }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-gray-200">{{ $m->name }} @unless ($m->pivot->active)<x-ui.badge color="slate">Inactive</x-ui.badge>@endunless</p>
                            @if ($canEdit)
                                <div class="mt-1 flex gap-1">
                                    <select wire:change="change({{ $m->id }}, 'role_on_project', $event.target.value)" class="select h-7 py-0 text-xs" aria-label="Role of {{ $m->name }}">
                                        @foreach (\App\Livewire\Projects\Members::ROLES as $k => $lbl)<option value="{{ $k }}" @selected($m->pivot->role_on_project === $k)>{{ $lbl }}</option>@endforeach
                                    </select>
                                    <select wire:change="change({{ $m->id }}, 'access_scope', $event.target.value)" class="select h-7 py-0 text-xs" aria-label="Scope of {{ $m->name }}">
                                        @foreach (\App\Enums\AccessScope::cases() as $s)<option value="{{ $s->value }}" @selected($m->pivot->access_scope === $s->value)>{{ ucfirst($s->value) }}</option>@endforeach
                                    </select>
                                </div>
                            @else
                                <p class="text-xs text-slate-500 dark:text-gray-400">{{ Str::headline($m->pivot->role_on_project) }} &middot; {{ ucfirst($m->pivot->access_scope) }}</p>
                            @endif
                        </div>
                        @if ($canEdit)
                            <button type="button" wire:click="toggle({{ $m->id }})" title="{{ $m->pivot->active ? 'Deactivate' : 'Reactivate' }}" aria-label="{{ $m->pivot->active ? 'Deactivate' : 'Reactivate' }} {{ $m->name }}" class="text-slate-400 hover:text-brand-600" data-test="toggle-{{ $m->id }}"><x-icon :name="$m->pivot->active ? 'ban' : 'play'" class="h-4 w-4" /></button>
                            <button type="button" x-on:click="ask = { method: 'remove', id: {{ $m->id }}, title: @js('Remove '.$m->name.'?'), text: 'They lose access to this project. Their tasks, time and comments stay.', button: 'Remove' }" title="Remove" aria-label="Remove {{ $m->name }}" class="text-slate-400 hover:text-rose-600"><x-icon name="x" class="h-4 w-4" /></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canEdit && $candidates->isNotEmpty())
            <form wire:submit="add" class="mt-4 space-y-2 border-t border-slate-100 pt-3 dark:border-gray-700" data-test="add-member">
                <select wire:model="userId" class="select text-sm" aria-label="Person">
                    <option value="">Add a person…</option>
                    @foreach ($candidates as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
                @error('userId') <p class="field-error">{{ $message }}</p> @enderror
                <div class="flex gap-2">
                    <select wire:model="role" class="select text-sm" aria-label="Role">
                        @foreach (\App\Livewire\Projects\Members::ROLES as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                    </select>
                    <select wire:model="scope" class="select text-sm" aria-label="Access scope">
                        @foreach (\App\Enums\AccessScope::cases() as $s)<option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>@endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.confirm-modal />
</div>
