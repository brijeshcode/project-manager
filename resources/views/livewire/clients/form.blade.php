<div>
    <h1 class="text-2xl font-semibold text-slate-900 mb-6">
        {{ $clientId ? 'Edit Client' : 'New Client' }}
    </h1>

    <form wire:submit="save" class="max-w-2xl space-y-6">

        {{-- Base --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-slate-700 uppercase tracking-wide">General</legend>
            <div>
                <label for="name" class="label">Name</label>
                <input wire:model="name" id="name" type="text"
                       class="input" />
                @error('name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="currency" class="label">Currency (3-letter ISO code)</label>
                <input wire:model="currency" id="currency" type="text" maxlength="3" placeholder="USD"
                       class="input uppercase" />
                @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="billing_mode" class="label">Billing mode</label>
                <select wire:model="billing_mode" id="billing_mode"
                        class="select">
                    <option value="fixed">Fixed price</option>
                    <option value="hourly">Hourly</option>
                </select>
                @error('billing_mode') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        {{-- Contact --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Contact</legend>
            <div>
                <label for="contact_name" class="label">Contact Name</label>
                <input wire:model="contact_name" id="contact_name" type="text"
                       class="input" />
                @error('contact_name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="contact_email" class="label">Contact Email</label>
                <input wire:model="contact_email" id="contact_email" type="email"
                       class="input" />
                @error('contact_email') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="contact_phone" class="label">Contact Phone</label>
                <input wire:model="contact_phone" id="contact_phone" type="text"
                       class="input" />
                @error('contact_phone') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="address" class="label">Address</label>
                <textarea wire:model="address" id="address" rows="3"
                          class="textarea"></textarea>
                @error('address') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        {{-- Billing --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Billing</legend>
            <div>
                <label for="tax_number" class="label">Tax / VAT Number</label>
                <input wire:model="tax_number" id="tax_number" type="text"
                       class="input" />
                @error('tax_number') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="billing_address" class="label">Billing Address</label>
                <textarea wire:model="billing_address" id="billing_address" rows="3"
                          class="textarea"></textarea>
                @error('billing_address') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="payment_terms" class="label">Payment Terms</label>
                <input wire:model="payment_terms" id="payment_terms" type="text" placeholder="net-30"
                       class="input" />
                @error('payment_terms') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        {{-- Bank (sensitive) --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Bank Details</legend>
            <div>
                <label for="bank_name" class="label">Bank Name</label>
                <input wire:model="bank_name" id="bank_name" type="text"
                       class="input" />
                @error('bank_name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bank_account_number" class="label">Account Number</label>
                <input wire:model="bank_account_number" id="bank_account_number" type="text"
                       class="input" />
                @error('bank_account_number') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bank_swift" class="label">SWIFT / IFSC / Routing</label>
                <input wire:model="bank_swift" id="bank_swift" type="text"
                       class="input" />
                @error('bank_swift') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        <div class="flex items-center gap-4 pt-2">
            <button type="submit"
                    class="btn btn-primary">
                {{ $clientId ? 'Update' : 'Create' }}
            </button>
            <a href="{{ route('clients.index') }}" wire:navigate class="link text-sm">
                Cancel
            </a>
        </div>
    </form>
</div>
