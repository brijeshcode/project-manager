<div>
    <x-ui.page-header :title="$clientId ? 'Edit Client' : 'Add Client'" :subtitle="$clientId ? 'Update '.$client_code.' details' : 'Create a new client record'" />

    <form wire:submit="save" class="max-w-4xl space-y-6">

        {{-- Basic Information --}}
        <div class="card p-6">
            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Basic Information</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <span class="label">Client Type</span>
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
                    <label for="name" class="label">{{ $type === 'company' ? 'Company Name' : 'Full Name' }} *</label>
                    <input wire:model="name" id="name" type="text" class="input" />
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                @if ($type === 'company')
                    <div>
                        <label for="contact_name" class="label">Contact Person</label>
                        <input wire:model="contact_name" id="contact_name" type="text" class="input" />
                        @error('contact_name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="status" class="label">Status</label>
                    <select wire:model="status" id="status" class="select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="archived">Archived</option>
                    </select>
                    @error('status') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Contact Information --}}
        <div class="card p-6">
            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Contact Information</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
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
                <div class="md:col-span-2">
                    <label for="website" class="label">Website</label>
                    <input wire:model="website" id="website" type="url" placeholder="https://example.com" class="input" />
                    @error('website') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Address Information --}}
        <div class="card p-6">
            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Address Information</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="address" class="label">Address</label>
                    <textarea wire:model="address" id="address" rows="3" class="textarea"></textarea>
                    @error('address') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                @foreach (['city' => 'City', 'state' => 'State / Province', 'postal_code' => 'Postal Code', 'country' => 'Country'] as $id => $label)
                    <div>
                        <label for="{{ $id }}" class="label">{{ $label }}</label>
                        <input wire:model="{{ $id }}" id="{{ $id }}" type="text" class="input" />
                        @error($id) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Additional Information --}}
        <div class="card p-6">
            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Additional Information</h3>
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label for="tax_number" class="label">Tax / VAT Number</label>
                    <input wire:model="tax_number" id="tax_number" type="text" class="input" />
                    @error('tax_number') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="notes" class="label">Internal Notes</label>
                    <textarea wire:model="notes" id="notes" rows="4" placeholder="Visible to you only, never to the client" class="textarea"></textarea>
                    @error('notes') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Billing --}}
        <div class="card p-6">
            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Billing</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
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
                <div>
                    <label for="payment_terms" class="label">Payment Terms</label>
                    <input wire:model="payment_terms" id="payment_terms" type="text" placeholder="net-30" class="input" />
                    @error('payment_terms') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label for="billing_address" class="label">Billing Address</label>
                    <textarea wire:model="billing_address" id="billing_address" rows="3" class="textarea"></textarea>
                    @error('billing_address') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Bank (sensitive) --}}
        <div class="card p-6">
            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Bank Details</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="bank_name" class="label">Bank Name</label>
                    <input wire:model="bank_name" id="bank_name" type="text" class="input" />
                    @error('bank_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bank_account_number" class="label">Account Number</label>
                    <input wire:model="bank_account_number" id="bank_account_number" type="text" class="input" />
                    @error('bank_account_number') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bank_swift" class="label">SWIFT / IFSC / Routing</label>
                    <input wire:model="bank_swift" id="bank_swift" type="text" class="input" />
                    @error('bank_swift') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ $clientId ? route('clients.show', $clientId) : route('clients.index') }}" wire:navigate class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $clientId ? 'Save Changes' : 'Create Client' }}</button>
        </div>
    </form>
</div>
