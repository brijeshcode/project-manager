<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Livewire\Concerns\DeletesClient;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Client;
use App\Services\ClientAccount;
use App\Support\Settings;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use DeletesClient, WithListSearch;

    #[Url]
    public int|string $perPage = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);
        $this->perPage = $this->sizeOrDefault();
    }

    private function sizeOrDefault(): int
    {
        return in_array((int) $this->perPage, Settings::PAGE_SIZES, true) ? (int) $this->perPage : Settings::pageSize();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->sizeOrDefault();
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function delete(int $clientId): void
    {
        if ($this->deleteClient($clientId)) {
            $this->dispatch('toast', type: 'success', message: 'Client deleted.');
        }
    }

    public function render()
    {
        $like = $this->likeTerm();
        $perPage = $this->sizeOrDefault();

        $clients = $this->fitPage(
            Client::orderBy('name')
                ->when(ClientStatus::tryFrom($this->status), fn ($q, $s) => $q->where('status', $s->value))
                ->when($like, fn ($q) => $q->where(fn ($q) => $q
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("client_code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("contact_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("contact_email LIKE ? ESCAPE '!'", [$like]))),
            $perPage
        );

        return view('livewire.clients.index', [
            'clients' => $clients,
            'totals' => app(ClientAccount::class)->totals($clients->pluck('id')->all()),
            'perPage' => $perPage,
        ]);
    }
}
