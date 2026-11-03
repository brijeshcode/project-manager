<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Currency;
use App\Services\LocalCurrency;
use Illuminate\Validation\Rule;
use App\Models\Project;
use Livewire\Component;

class Form extends Component
{
    public ?int $projectId = null;

    public int    $client_id      = 0;
    public string $currency       = '';   // defaults to the client's; the owner can change it
    public string $name           = '';
    public string $description    = '';
    public float  $buffer_percent = 0;
    public ?string $start_date    = null;
    public string $status         = 'draft';

    public function mount(?Project $project = null): void
    {
        if ($project && $project->exists) {
            $this->authorize('update', $project);
        } else {
            $this->authorize('create', Project::class);
        }

        // ?client=ID preselects the client on a new project (linked from the client pages)
        if (! ($project && $project->exists) && ($cid = (int) request()->query('client')) && Client::whereKey($cid)->exists()) {
            $this->client_id = $cid;
            $this->currency  = strtoupper((string) Client::find($cid)->currency);
        }

        if ($project && $project->exists) {
            $this->projectId     = $project->id;
            $this->client_id      = $project->client_id;
            $this->currency       = strtoupper((string) $project->currency);
            $this->name           = $project->name;
            $this->description    = $project->description ?? '';
            $this->buffer_percent = (float) $project->buffer_percent;
            $this->start_date     = $project->start_date?->toDateString();
            $this->status         = $project->status;
        }
    }

    public function updatedClientId(): void
    {
        // a project starts in its client's currency
        $this->currency = ($c = Client::find($this->client_id)) ? strtoupper($c->currency) : '';
    }

    private function currencyOptions(): array
    {
        return collect([LocalCurrency::code(), ($c = Client::find($this->client_id)) ? strtoupper($c->currency) : null, $this->projectId ? Project::find($this->projectId)?->currency : null])
            ->merge(Currency::active()->pluck('code'))->filter()->unique()->sort()->values()->all();
    }

    public function save(): void
    {
        $data = $this->validate([
            'currency'       => ['nullable', Rule::in($this->currencyOptions())],
            'client_id'      => 'required|exists:clients,id',
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'buffer_percent' => 'numeric|min:0|max:999.99',
            'start_date'     => 'nullable|date',
            'status'         => 'required|string|max:50',
        ]);

        $data['currency'] = $data['currency'] ?: null; // null on create = the client's currency

        if ($this->projectId) {
            $project = Project::findOrFail($this->projectId);
            $this->authorize('update', $project);
            $project->update($data);
        } else {
            $this->authorize('create', Project::class);
            Project::create($data);
        }

        $this->redirect(route('projects.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.projects.form', [
            'clients' => Client::orderBy('name')->get(),
            'currencies' => $this->currencyOptions(),
        ]);
    }
}
