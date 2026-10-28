<div>
    <h1 class="text-2xl font-semibold text-gray-900 mb-6">
        {{ $clientId ? 'Edit Client' : 'New Client' }}
    </h1>

    <form wire:submit="save" class="max-w-2xl space-y-6">

        {{-- Base --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-700 uppercase tracking-wide">General</legend>
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                <input wire:model="name" id="name" type="text"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="currency" class="block text-sm font-medium text-gray-700">Currency (3-letter ISO code)</label>
                <input wire:model="currency" id="currency" type="text" maxlength="3" placeholder="USD"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm uppercase" />
                @error('currency') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        {{-- Contact --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Contact</legend>
            <div>
                <label for="contact_name" class="block text-sm font-medium text-gray-700">Contact Name</label>
                <input wire:model="contact_name" id="contact_name" type="text"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('contact_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="contact_email" class="block text-sm font-medium text-gray-700">Contact Email</label>
                <input wire:model="contact_email" id="contact_email" type="email"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('contact_email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="contact_phone" class="block text-sm font-medium text-gray-700">Contact Phone</label>
                <input wire:model="contact_phone" id="contact_phone" type="text"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('contact_phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                <textarea wire:model="address" id="address" rows="3"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"></textarea>
                @error('address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        {{-- Billing --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Billing</legend>
            <div>
                <label for="tax_number" class="block text-sm font-medium text-gray-700">Tax / VAT Number</label>
                <input wire:model="tax_number" id="tax_number" type="text"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('tax_number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="billing_address" class="block text-sm font-medium text-gray-700">Billing Address</label>
                <textarea wire:model="billing_address" id="billing_address" rows="3"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"></textarea>
                @error('billing_address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="payment_terms" class="block text-sm font-medium text-gray-700">Payment Terms</label>
                <input wire:model="payment_terms" id="payment_terms" type="text" placeholder="net-30"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('payment_terms') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        {{-- Bank (sensitive) --}}
        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Bank Details</legend>
            <div>
                <label for="bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label>
                <input wire:model="bank_name" id="bank_name" type="text"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('bank_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bank_account_number" class="block text-sm font-medium text-gray-700">Account Number</label>
                <input wire:model="bank_account_number" id="bank_account_number" type="text"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('bank_account_number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bank_swift" class="block text-sm font-medium text-gray-700">SWIFT / IFSC / Routing</label>
                <input wire:model="bank_swift" id="bank_swift" type="text"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                @error('bank_swift') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        <div class="flex items-center gap-4 pt-2">
            <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">
                {{ $clientId ? 'Update' : 'Create' }}
            </button>
            <a href="{{ route('clients.index') }}" wire:navigate class="text-sm text-gray-600 hover:underline">
                Cancel
            </a>
        </div>
    </form>
</div>
