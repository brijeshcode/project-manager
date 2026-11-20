<div class="space-y-6" data-test="client-access">
    <div @class(['flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between', 'border-rose-200 bg-rose-50 dark:border-rose-900/50 dark:bg-rose-900/10' => $client->portalBlocked(), 'border-slate-200 dark:border-gray-700' => ! $client->portalBlocked()]) data-test="portal-block">
        <div>
            <p class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                <x-icon :name="$client->portalBlocked() ? 'lock' : 'shield'" class="h-4 w-4" />
                Portal access: <x-ui.badge :color="$client->portalBlocked() ? 'rose' : 'green'">{{ $client->portalBlocked() ? 'Blocked' : 'Allowed' }}</x-ui.badge>
            </p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                @if ($client->portalBlocked())
                    Blocked since {{ $client->portal_blocked_at->toFormattedDateString() }}. No login below can sign in.
                @else
                    People who can sign in to the client portal for {{ $client->name }}. They only ever see this client's projects, invoices and payments.
                @endif
            </p>
        </div>
        @if ($client->portalBlocked())
            <button type="button" wire:click="togglePortalBlock" class="btn btn-secondary shrink-0" data-test="toggle-portal-block">Restore access</button>
        @else
            <button type="button" x-on:click="ask = @js(['method' => 'togglePortalBlock', 'id' => null, 'title' => 'Block portal access', 'text' => 'Block portal access for every login of '.$client->name.'? Anyone signed in is signed out on their next click. You can restore it any time.', 'button' => 'Block access'])" class="btn btn-danger shrink-0" data-test="toggle-portal-block">Block portal access</button>
        @endif
    </div>

    <div class="rounded-lg border border-slate-200 p-4 dark:border-gray-700" data-test="portal-features">
        <p class="text-sm font-semibold text-gray-900 dark:text-white">What the portal shows</p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Projects, invoices and subscriptions are always shown. Tick to also show:</p>
        <div class="mt-3 space-y-2">
            @foreach (\App\Models\Client::PORTAL_FEATURES as $key => [$label, $hint])
                <label class="flex items-start gap-3 text-sm" wire:key="pf-{{ $key }}">
                    <input type="checkbox" class="mt-0.5 rounded border-slate-300 dark:border-gray-600" @checked($client->portalShows($key)) wire:click="togglePortalFeature('{{ $key }}')" data-test="portal-feature-{{ $key }}" />
                    <span><span class="font-medium text-gray-900 dark:text-white">{{ $label }}</span> <span class="text-gray-500 dark:text-gray-400">· {{ $hint }}</span></span>
                </label>
            @endforeach
        </div>
    </div>

    @if ($logins->isEmpty())
        <x-ui.empty-state title="No portal logins yet" message="Add a login below so the client can follow their projects and billing." name="user" />
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead><tr><th class="table-th">Name</th><th class="table-th">Email</th><th class="table-th">New password</th><th class="table-th"></th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($logins as $u)
                        <tr wire:key="login-{{ $u->id }}">
                            <td class="table-td font-medium">{{ $u->name }}</td>
                            <td class="table-td">{{ $u->email }}</td>
                            <td class="table-td">
                                <form wire:submit="setLoginPassword({{ $u->id }})" class="flex items-center gap-2">
                                    <input type="password" wire:model="newPassword.{{ $u->id }}" class="input h-8 w-44 text-sm" autocomplete="new-password" aria-label="New password for {{ $u->name }}" placeholder="New password">
                                    <button type="submit" class="btn btn-secondary h-8 px-3 py-1 text-xs">Change</button>
                                </form>
                                @error('newPassword.'.$u->id) <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                            <td class="table-td text-right">
                                <button type="button" x-on:click="ask = @js(['method' => 'removeLogin', 'id' => $u->id, 'title' => 'Remove portal login', 'text' => 'Remove the portal login for '.$u->email.'? They will no longer be able to sign in.', 'button' => 'Remove'])" class="btn btn-ghost h-8 px-2 text-red-600" data-test="remove-login">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form wire:submit="addLogin" class="rounded-lg border border-slate-200 p-4 dark:border-gray-700" data-test="add-login-form">
        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Add a portal login</h3>
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="label" for="login-name">Name</label>
                <input id="login-name" type="text" wire:model="login.name" class="input">
                @error('login.name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="login-email">Email</label>
                <input id="login-email" type="email" wire:model="login.email" class="input" autocomplete="off">
                @error('login.email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="login-password">Password</label>
                <input id="login-password" type="password" wire:model="login.password" class="input" autocomplete="new-password">
                @error('login.password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <button type="submit" class="btn btn-primary mt-3"><x-nav.icon name="plus" class="h-5 w-5" /> Add login</button>
    </form>
</div>
