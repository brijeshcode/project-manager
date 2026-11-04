<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Currency;
use App\Services\LocalCurrency;
use App\Support\BoardTemplates;
use App\Support\Money;
use Illuminate\Validation\Rule;
use App\Models\Project;
use App\Livewire\Concerns\HandlesMarkdownUploads;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class Form extends Component
{
    use HandlesMarkdownUploads;

    // ponytail: projects have no attachments table; the editor's Attach is off here (preview only)
    protected function uploadTarget(): Model
    {
        abort(403);
    }

    public ?int $projectId = null;

    public int    $client_id      = 0;
    public string $currency       = '';   // defaults to the client's; the owner can change it
    public string $name           = '';
    public string $key            = '';   // task code prefix; blank on create = derived from the name
    public string $description    = '';
    public float  $buffer_percent = 0;
    public ?string $start_date    = null;
    public string $status         = 'draft';
    public ?string $aim           = null;
    public ?string $deadline      = null;
    public string $reason         = '';   // required when an existing deadline is changed
    public string $template      = 'software';   // board columns copied at create; edited later on the Board tab

    public function mount(?Project $project = null): void
    {
        if ($project && $project->exists) {
            $this->authorize('update', $project);
        } else {
            $this->authorize('create', Project::class);
            $this->start_date = now()->toDateString();
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
            $this->key            = $project->key;
            $this->description    = $project->description ?? '';
            $this->buffer_percent = (float) $project->buffer_percent;
            $this->start_date     = $project->start_date?->toDateString();
            $this->status         = $project->status;
            $this->aim            = $project->aim;
            $this->deadline       = $project->deadline?->toDateString();
        }
    }

    /** True when editing a project whose existing deadline was moved (needs a reason). */
    public function changingDeadline(): bool
    {
        $orig = $this->projectId ? Project::find($this->projectId)?->deadline?->toDateString() : null;

        return $orig && $this->deadline && $this->deadline !== $orig;
    }

    public function updatedKey(): void
    {
        $this->key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->key));
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
            'aim'            => 'nullable|string|max:5000',
            'currency'       => ['nullable', Rule::in($this->currencyOptions())],
            'client_id'      => 'required|exists:clients,id',
            'name'           => 'required|string|max:255',
            'key'            => ['nullable', 'regex:/^[A-Z0-9]{2,5}$/', Rule::unique('projects', 'key')->ignore($this->projectId)],
            'description'    => 'nullable|string',
            'buffer_percent' => 'numeric|min:0|max:999.99',
            'start_date'     => 'nullable|date',
            'deadline'       => array_filter(['nullable', 'date', $this->start_date ? 'after_or_equal:start_date' : null]),
            'reason'         => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->changingDeadline())],
            'status'         => 'required|string|max:50',
            'template'       => ['required', Rule::in(array_keys(BoardTemplates::ALL))],
        ]);

        $data['key'] = strtoupper(trim((string) $this->key)) ?: ($this->projectId ? Project::find($this->projectId)->key : null);
        $data['aim'] = trim((string) $data['aim']) ?: null;
        $data['currency'] = $data['currency'] ?: null; // null on create = the client's currency
        ['deadline' => $deadline, 'reason' => $reason] = $data;
        unset($data['deadline'], $data['reason']);

        if ($this->projectId) {
            unset($data['template']);
            $project = Project::findOrFail($this->projectId);
            $this->authorize('update', $project);
            $project->update($data);
            $deadline && $project->setDeadline($deadline, auth()->user(), $reason);
        } else {
            $this->authorize('create', Project::class);
            $project = Project::create($data);
            $deadline && $project->setDeadline($deadline, auth()->user());
        }

        $this->redirect(route('projects.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.projects.form', [
            'clients' => Client::orderBy('name')->get(),
            'currencies' => $this->currencyOptions(),
            'templates' => array_keys(BoardTemplates::ALL),
            'project' => $this->projectId ? Project::find($this->projectId) : null,
        ]);
    }
}
