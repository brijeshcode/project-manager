<?php

namespace App\Livewire\Billing;

use App\Models\BankAccount;
use Livewire\Component;

class BankAccounts extends Component
{
    public string $label = '';
    public string $account_name = '';
    public string $bank_name = '';
    public string $account_number = '';
    public string $ifsc_swift = '';
    public string $email = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    public function save(): void
    {
        $this->authorize('manage-money');

        $data = $this->validate([
            'label'          => 'bail|required|string|max:100',
            'account_name'   => 'bail|required|string|max:255',
            'bank_name'      => 'bail|required|string|max:255',
            'account_number' => 'bail|required|string|max:50',
            'ifsc_swift'     => 'bail|required|string|max:50',
            'email'          => 'bail|nullable|email|max:255',
        ]);

        BankAccount::create($data + ['email' => $data['email'] ?: null]);

        $this->reset('label', 'account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email');
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-money');
        BankAccount::findOrFail($id)->delete();
    }

    public function render()
    {
        return view('livewire.billing.bank-accounts', [
            // masked server-side; the full number never reaches the view
            'accounts' => BankAccount::orderBy('label')->get()->map(fn ($a) => (object) [
                'id' => $a->id, 'label' => $a->label, 'account_name' => $a->account_name,
                'bank_name' => $a->bank_name, 'email' => $a->email, 'masked' => $a->masked(),
            ]),
        ]);
    }
}
