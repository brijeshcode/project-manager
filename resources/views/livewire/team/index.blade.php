<div x-data="{ ask: null }">
    <x-ui.page-header title="Team" subtitle="Internal users who can be added to projects. New people get an e-mail to set their password.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="btn btn-primary gap-1" data-test="new"><x-nav.icon name="user-plus" class="h-5 w-5" /> Add member</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table-card>
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
            <thead>
                <tr><th class="table-th">Name</th><th class="table-th">Role</th><th class="table-th">Weekly capacity</th><th class="table-th">Projects</th><th class="table-th text-right">Actions</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                @foreach ($users as $u)
                    <tr wire:key="user-{{ $u->id }}" @class(['opacity-60' => ! $u->active]) data-test="user-row">
                        <td class="table-td whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full {{ $u->active ? 'bg-green-500' : 'bg-gray-400' }}" title="{{ $u->active ? 'Active' : 'Inactive' }}"></span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ $u->name }}</span>
                                @unless ($u->active)<x-ui.badge color="slate">Inactive</x-ui.badge>@endunless
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $u->email }}</div>
                        </td>
                        <td class="table-td whitespace-nowrap">{{ $u->roles->pluck('name')->map(fn ($r) => Str::headline($r))->implode(', ') ?: '—' }}</td>
                        <td class="table-td whitespace-nowrap tabular-nums">{{ $u->weekly_capacity_hours === null ? 'No limit' : rtrim(rtrim(number_format($u->weekly_capacity_hours, 2), '0'), '.').'h' }}</td>
                        <td class="table-td whitespace-nowrap tabular-nums">{{ $u->projects_count }}</td>
                        <td class="table-td whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button type="button" wire:click="edit({{ $u->id }})" title="Edit" aria-label="Edit {{ $u->name }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400" data-test="edit"><x-nav.icon name="pencil" class="h-4 w-4" /></button>
                                @if ($u->id !== auth()->id())
                                    @if ($u->active)
                                        <button type="button" x-on:click="ask = { method: 'toggleActive', id: {{ $u->id }}, title: @js('Deactivate '.$u->name.'?'), text: 'They can no longer sign in. Their projects and task history stay.', button: 'Deactivate' }" class="text-sm font-medium text-rose-600 hover:underline dark:text-rose-400" data-test="deactivate">Deactivate</button>
                                    @else
                                        <button type="button" wire:click="toggleActive({{ $u->id }})" class="text-sm font-medium link" data-test="reactivate">Reactivate</button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.table-card>

    @if ($editing !== null)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-label="{{ $editing ? 'Edit member' : 'Add member' }}" x-data @keydown.escape.window="$wire.cancel()" data-test="user-form">
            <form wire:submit="save" class="card w-full max-w-lg space-y-4 p-5">
                <div class="flex items-center justify-between">
                    <h3 class="font-display text-base font-semibold text-slate-900 dark:text-white">{{ $editing ? 'Edit member' : 'Add member' }}</h3>
                    <button type="button" wire:click="cancel" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="h-4 w-4" /></button>
                </div>
                <div>
                    <label for="u-name" class="label">Name</label>
                    <input id="u-name" type="text" wire:model="name" class="input" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-email" class="label">Email</label>
                    <input id="u-email" type="email" wire:model="email" class="input">
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="u-role" class="label">Role</label>
                        <select id="u-role" wire:model="role" class="select">
                            @foreach (\App\Livewire\Team\Index::ROLES as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                        </select>
                        @error('role') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="u-cap" class="label">Weekly capacity <span class="font-normal text-slate-400">(hours)</span></label>
                        <input id="u-cap" type="number" step="0.5" min="0" max="168" wire:model="capacity" class="input" placeholder="No limit">
                        @error('capacity') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                @unless ($editing)<p class="text-xs text-slate-500 dark:text-gray-400">They get an e-mail with a link to set their own password.</p>@endunless
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-test="save">{{ $editing ? 'Save' : 'Add member' }}</button>
                </div>
            </form>
        </div>
    @endif

    <x-ui.confirm-modal />
</div>
