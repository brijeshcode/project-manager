<?php

namespace App\Livewire\Billing;

use App\Models\BankAccount;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BankAccounts extends Component
{
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $label = '';
    public string $account_name = '';
    public string $bank_name = '';
    public string $account_number = '';
    public string $ifsc_swift = '';
    public string $email = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
        $this->showForm = ! BankAccount::exists();
    }

    public function create(): void
    {
        $this->authorize('manage-money');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-money');
        $a = BankAccount::findOrFail($id);
        $this->resetForm();
        $this->editingId = $a->id;
        $this->fill($a->only(['label', 'account_name', 'bank_name']) + ['email' => (string) $a->email]);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorize('manage-money');
        // editing: blank number / IFSC keeps the stored (encrypted) value
        $secret = $this->editingId ? 'bail|nullable|string|max:50' : 'bail|required|string|max:50';

        $data = $this->validate([
            'label'          => 'bail|required|string|max:100',
            'account_name'   => 'bail|required|string|max:255',
            'bank_name'      => 'bail|required|string|max:255',
            'account_number' => $secret,
            'ifsc_swift'     => $secret,
            'email'          => 'bail|nullable|email|max:255',
        ]);
        $data['email'] = $data['email'] ?: null;

        if ($this->editingId) {
            foreach (['account_number', 'ifsc_swift'] as $k) {
                if (blank($data[$k])) {
                    unset($data[$k]);
                }
            }
            BankAccount::findOrFail($this->editingId)->update($data);
            $msg = 'Bank account saved.';
        } else {
            BankAccount::create($data);
            $msg = 'Bank account added.';
        }

        $this->resetForm();
        $this->dispatch('toast', message: $msg);
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-money');
        BankAccount::findOrFail($id)->delete();
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        $this->dispatch('toast', message: 'Bank account deleted.');
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'label', 'account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email');
        $this->resetValidation();
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
