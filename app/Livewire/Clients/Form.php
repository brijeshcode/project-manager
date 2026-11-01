<?php

namespace App\Livewire\Clients;

use App\Enums\BillingMode;
use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    #[Locked]
    public ?int $clientId = null;

    #[Locked]
    public ?string $client_code = null; // display only, generated on create

    public string $name     = '';
    public string $currency = '';
    public string $billing_mode = 'fixed';
    public string $type   = 'company';
    public string $status = 'active';

    // Contact
    public ?string $contact_name  = null;
    public ?string $contact_email = null;
    public ?string $contact_phone = null;
    public ?string $website       = null;

    // Address
    public ?string $address     = null;
    public ?string $city        = null;
    public ?string $state       = null;
    public ?string $postal_code = null;
    public ?string $country     = null;

    // Additional
    public ?string $tax_number = null;
    public ?string $notes      = null;

    // Billing
    public ?string $billing_address = null;
    public ?string $payment_terms   = null;

    // Bank
    public ?string $bank_name           = null;
    public ?string $bank_account_number = null;
    public ?string $bank_swift          = null;

    private const FIELDS = [
        'name', 'currency', 'contact_name', 'contact_email', 'contact_phone', 'website', 'address', 'city', 'state',
        'postal_code', 'country', 'tax_number', 'notes', 'billing_address', 'payment_terms',
        'bank_name', 'bank_account_number', 'bank_swift',
    ];

    public function mount(?Client $client = null): void
    {
        if ($client && $client->exists) {
            $this->authorize('update', $client);
            $this->clientId     = $client->id;
            $this->client_code  = $client->client_code;
            $this->billing_mode = $client->billing_mode->value;
            $this->type         = $client->type->value;
            $this->status       = $client->status->value;
            foreach (self::FIELDS as $f) {
                $this->$f = $client->$f;
            }
        }
    }

    public function save()
    {
        if ($this->clientId) {
            $this->authorize('update', Client::findOrFail($this->clientId));
        } else {
            $this->authorize('create', Client::class);
        }

        $data = $this->validate([
            'name'                => 'required|string|max:255',
            'currency'            => ['required', 'regex:/\A[A-Za-z]{3}\z/'],
            'billing_mode'        => ['required', Rule::enum(BillingMode::class)],
            'type'                => ['required', Rule::enum(ClientType::class)],
            'status'              => ['required', Rule::enum(ClientStatus::class)],
            'contact_name'        => 'nullable|string|max:255',
            'contact_email'       => 'nullable|email|max:255',
            'contact_phone'       => 'nullable|string|max:50',
            'website'             => 'nullable|url:http,https|max:255',
            'address'             => 'nullable|string|max:1000',
            'city'                => 'nullable|string|max:100',
            'state'               => 'nullable|string|max:100',
            'postal_code'         => 'nullable|string|max:20',
            'country'             => 'nullable|string|max:100',
            'tax_number'          => 'nullable|string|max:100',
            'notes'               => 'nullable|string|max:5000',
            'billing_address'     => 'nullable|string|max:1000',
            'payment_terms'       => 'nullable|string|max:100',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_swift'          => 'nullable|string|max:50',
        ]);

        $data['currency'] = strtoupper($data['currency']);
        if ($data['type'] === ClientType::Individual->value) {
            $data['contact_name'] = null; // contact person is for companies only
        }

        $client = $this->clientId
            ? tap(Client::findOrFail($this->clientId))->update($data)
            : Client::create($data);

        \App\Services\LocalCurrency::backfill();

        session()->flash('toast', 'Client saved.');

        return $this->redirect(route('clients.show', $client), navigate: true);
    }

    public function render()
    {
        return view('livewire.clients.form');
    }
}
