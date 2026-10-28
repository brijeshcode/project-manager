<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Livewire\Component;

class Form extends Component
{
    public ?int $clientId = null;

    public string $name     = '';
    public string $currency = '';

    // Contact
    public ?string $contact_name  = null;
    public ?string $contact_email = null;
    public ?string $contact_phone = null;
    public ?string $address       = null;

    // Billing
    public ?string $tax_number      = null;
    public ?string $billing_address = null;
    public ?string $payment_terms   = null;

    // Bank
    public ?string $bank_name           = null;
    public ?string $bank_account_number = null;
    public ?string $bank_swift          = null;

    public function mount(?Client $client = null): void
    {
        if ($client && $client->exists) {
            $this->clientId           = $client->id;
            $this->name               = $client->name;
            $this->currency           = $client->currency;
            $this->contact_name       = $client->contact_name;
            $this->contact_email      = $client->contact_email;
            $this->contact_phone      = $client->contact_phone;
            $this->address            = $client->address;
            $this->tax_number         = $client->tax_number;
            $this->billing_address    = $client->billing_address;
            $this->payment_terms      = $client->payment_terms;
            $this->bank_name          = $client->bank_name;
            $this->bank_account_number = $client->bank_account_number;
            $this->bank_swift         = $client->bank_swift;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name'                => 'required|string|max:255',
            'currency'            => 'required|size:3',
            'contact_name'        => 'nullable|string|max:255',
            'contact_email'       => 'nullable|email|max:255',
            'contact_phone'       => 'nullable|string|max:50',
            'address'             => 'nullable|string|max:1000',
            'tax_number'          => 'nullable|string|max:100',
            'billing_address'     => 'nullable|string|max:1000',
            'payment_terms'       => 'nullable|string|max:100',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_swift'          => 'nullable|string|max:50',
        ]);

        $data['currency'] = strtoupper($data['currency']);

        if ($this->clientId) {
            Client::findOrFail($this->clientId)->update($data);
        } else {
            Client::create($data);
        }

        $this->redirect(route('clients.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.clients.form');
    }
}
