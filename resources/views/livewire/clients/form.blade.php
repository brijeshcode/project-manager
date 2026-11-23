@php
    $show = fn (string $key) => $section === '' || $section === $key;
    $back = $clientId ? route('clients.show', $clientId) : route('clients.index');
    $head = fn (string $title, string $icon, ?string $hint = null) => new \Illuminate\Support\HtmlString(
        '<div class="mb-5"><h2 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-white">'
        .'<span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">'.preg_replace('/^<svg /', '<svg class="h-4 w-4" aria-hidden="true" ', (string) \App\Support\Icon::svg($icon), 1).'</span>'.e($title).'</h2>'
        .($hint ? '<p class="mt-1 text-sm text-slate-500 dark:text-gray-400">'.e($hint).'</p>' : '').'</div>'
    );
    $title = ! $clientId ? 'New client' : ($section !== '' ? 'Edit '.\App\Livewire\Clients\Form::SECTIONS[$section] : 'Edit client');
@endphp
<div>
    <x-ui.breadcrumb :items="$clientId
        ? [['Clients', route('clients.index')], [$name ?: $client_code, route('clients.show', $clientId)], ['Edit', null]]
        : [['Clients', route('clients.index')], ['New client', null]]" />

    <x-ui.page-header :title="$title" :subtitle="$clientId ? $client_code.' · '.$name : 'Create a new client record'">
        @if ($section !== '')
            <x-slot:actions>
                <button type="button" wire:click="$set('section', '')" class="btn btn-secondary" data-test="show-all-fields">Show all fields</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <form wire:submit="save" class="max-w-4xl space-y-6" data-test="client-form">
        @if ($show('basic'))
            <section class="card p-6" data-test="section-basic">
                {{ $head('Basic information', 'tag') }}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <span class="label">Client type</span>
                        <div class="flex gap-6">
                            @foreach (['company' => 'Company', 'individual' => 'Individual'] as $value => $label)
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                    <input wire:model.live="type" type="radio" value="{{ $value }}" class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700" />
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('type') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="name" class="label">{{ $type === 'company' ? 'Company name' : 'Full name' }} *</label>
                        <input wire:model="name" id="name" type="text" class="input" />
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="company_name" class="label">Company / legal name</label>
                        <input wire:model="company_name" id="company_name" type="text" maxlength="255" class="input" data-test="company-name" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Shown on invoices as the billed party.</p>
                        @error('company_name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="status" class="label">Status</label>
                        <select wire:model="status" id="status" class="select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="archived">Archived</option>
                        </select>
                        @error('status') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="currency" class="label">Currency (3-letter ISO code) *</label>
                        <input wire:model="currency" id="currency" type="text" maxlength="3" placeholder="USD" class="input uppercase" />
                        @error('currency') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="billing_mode" class="label">Billing mode</label>
                        <select wire:model="billing_mode" id="billing_mode" class="select">
                            <option value="fixed">Fixed price</option>
                            <option value="hourly">Hourly</option>
                        </select>
                        @error('billing_mode') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 sm:col-span-2">
                        <input type="checkbox" wire:model="is_internal" id="is_internal" class="h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                        Internal (personal, not billed)
                    </label>
                </div>
            </section>
        @endif

        @if ($show('contact'))
            <section class="card p-6" data-test="section-contact">
                {{ $head('Contact', 'user') }}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @if ($type === 'company')
                        <div class="sm:col-span-2">
                            <label for="contact_name" class="label">Contact person</label>
                            <input wire:model="contact_name" id="contact_name" type="text" class="input" />
                            @error('contact_name') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label for="contact_email" class="label">Email</label>
                        <input wire:model="contact_email" id="contact_email" type="email" class="input" />
                        @error('contact_email') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="contact_phone" class="label">Phone</label>
                        <input wire:model="contact_phone" id="contact_phone" type="text" class="input" />
                        @error('contact_phone') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="website" class="label">Website</label>
                        <input wire:model="website" id="website" type="url" placeholder="https://example.com" class="input" />
                        @error('website') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>
        @endif

        @if ($show('address'))
            <section class="card p-6" data-test="section-address">
                {{ $head('Address', 'globe') }}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="address" class="label">Street address</label>
                        <textarea wire:model="address" id="address" rows="3" class="textarea"></textarea>
                        @error('address') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @foreach (['city' => 'City', 'state' => 'State / Province', 'postal_code' => 'Postal code', 'country' => 'Country'] as $id => $label)
                        <div>
                            <label for="{{ $id }}" class="label">{{ $label }}</label>
                            <input wire:model="{{ $id }}" id="{{ $id }}" type="text" class="input" />
                            @error($id) <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($show('billing'))
            <section class="card p-6" data-test="section-billing">
                {{ $head('Billing', 'landmark') }}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="tax_number" class="label">Tax / VAT number</label>
                        <input wire:model="tax_number" id="tax_number" type="text" class="input" />
                        @error('tax_number') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="payment_terms" class="label">Payment terms</label>
                        <input wire:model="payment_terms" id="payment_terms" type="text" placeholder="net-30" class="input" />
                        @error('payment_terms') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="billing_address" class="label">Billing address</label>
                        <textarea wire:model="billing_address" id="billing_address" rows="3" class="textarea"></textarea>
                        @error('billing_address') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h3 class="mb-3 mt-6 border-t border-slate-100 pt-5 text-sm font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Bank details <span class="font-normal text-slate-500 dark:text-gray-400">· stored encrypted</span></h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="bank_name" class="label">Bank name</label>
                        <input wire:model="bank_name" id="bank_name" type="text" class="input" />
                        @error('bank_name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="bank_account_number" class="label">Account number</label>
                        <input wire:model="bank_account_number" id="bank_account_number" type="text" class="input" />
                        @error('bank_account_number') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="bank_swift" class="label">SWIFT / IFSC / Routing</label>
                        <input wire:model="bank_swift" id="bank_swift" type="text" class="input" />
                        @error('bank_swift') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>
        @endif

        @if ($show('notes'))
            <section class="card p-6" data-test="section-notes">
                {{ $head('Internal notes', 'lock', 'Only your team sees this; never shown in the portal.') }}
                <label for="notes" class="sr-only">Internal notes</label>
                <textarea wire:model="notes" id="notes" rows="5" placeholder="Anything the team should know about this client" class="textarea"></textarea>
                @error('notes') <p class="field-error">{{ $message }}</p> @enderror
            </section>
        @endif

        @if ($section !== '' && $errors->any())
            {{-- an error in a hidden section still blocks saving: say so and offer the full form --}}
            <p class="field-error">Some fields outside this section need attention. <button type="button" wire:click="$set('section', '')" class="link">Show all fields</button></p>
        @endif

        <div class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-slate-200 bg-white/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border dark:border-gray-700 dark:bg-gray-800/90">
            <a href="{{ $back }}" wire:navigate class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $clientId ? 'Save changes' : 'Create client' }}</button>
        </div>
    </form>
</div>
