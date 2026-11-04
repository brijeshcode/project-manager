<?php

namespace App\Livewire\Resources;

use App\Livewire\Concerns\HandlesMarkdownUploads;
use App\Models\Label;
use App\Models\Project;
use App\Models\Resource;
use App\Models\User;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The project's Resources tab: one row per secret. The value is never in the page: it is fetched by reveal() when a row
 * is expanded (which also writes the access log).
 *
 * Attachments on a not-yet-saved resource: the first upload from the Add form creates a hidden DRAFT resource
 * (is_draft) that owns the files; save() turns the draft into the real row, cancel deletes it (files included),
 * and drafts older than a day are pruned whenever someone opens the Add form.
 */
class Panel extends Component
{
    use HandlesMarkdownUploads;

    #[Locked]
    public Project $project;

    public string $search = '';

    public string $labelFilter = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $draftId = null;

    /** title, value, labels (ids), viewers (user ids) */
    public array $form = ['title' => '', 'value' => '', 'labels' => [], 'viewers' => []];

    public function mount(Project $project): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        $this->authorize($user->hasRole('client') ? 'viewAsClient' : 'view', $project);
        $this->project = $project;
    }

    private function canAdd(): bool
    {
        return ! auth()->user()->hasRole('client');
    }

    #[Computed]
    public function resources(): Collection
    {
        $term = mb_substr(trim($this->search), 0, 100);

        return Resource::visibleTo(auth()->user())
            ->select('id', 'project_id', 'title', 'created_by', 'created_at', 'updated_at') // never load the value for the list
            ->where('project_id', $this->project->id)
            ->when($term !== '', fn ($q) => $q->whereRaw("title like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%']))
            ->when($this->labelFilter !== '', fn ($q) => $q->whereHas('labels', fn ($l) => $l->where('labels.id', (int) $this->labelFilter)))
            ->with(['labels', 'viewers', 'creator'])
            ->latest('id')
            ->get()
            ->each->setRelation('project', $this->project); // canEdit() needs it; avoids a query per row
    }

    #[Computed]
    public function labels(): Collection
    {
        return Label::orderBy('name')->get();
    }

    /** Label filter options: only labels on resources this user can see (a client must not learn other label names). */
    #[Computed]
    public function filterLabels(): Collection
    {
        $visible = Resource::visibleTo(auth()->user())->where('project_id', $this->project->id)->select('id');

        return Label::whereIn('id', DB::table('labelables')->where('labelable_type', (new Resource)->getMorphClass())
            ->whereIn('labelable_id', $visible)->select('label_id'))->orderBy('name')->get();
    }

    /** Users who may be viewers: active project members + the project's client users. */
    #[Computed]
    public function candidates(): Collection
    {
        return User::query()
            ->where(fn ($q) => $q
                ->whereIn('id', $this->project->members()->wherePivot('active', true)->pluck('users.id'))
                ->orWhere(fn ($c) => $c->whereNotNull('client_id')->where('client_id', $this->project->client_id)))
            ->orderBy('name')
            ->get();
    }

    private function find(int $id): Resource
    {
        return Resource::visibleTo(auth()->user())->where('project_id', $this->project->id)->findOrFail($id);
    }

    private function editable(int $id): Resource
    {
        $r = $this->find($id);
        abort_unless($r->canEdit(auth()->user()), 403);

        return $r;
    }

    // ── add / edit form ──

    public function openAdd(): void
    {
        abort_unless($this->canAdd(), 403);
        Resource::where('is_draft', true)->where('updated_at', '<', now()->subDay())->get()->each->delete();
        $this->reset('form', 'editingId', 'draftId');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $r = $this->editable($id);
        $r->logView(auth()->user()); // the editor sees the value in the form
        $this->resetValidation();
        $this->form = [
            'title' => $r->title, 'value' => (string) $r->value,
            'labels' => $r->labels()->pluck('labels.id')->map(fn ($i) => (string) $i)->all(),
            'viewers' => $r->viewers()->pluck('users.id')->map(fn ($i) => (string) $i)->all(),
        ];
        $this->editingId = $r->id;
        $this->draftId = null;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->draftId && Resource::where('is_draft', true)->where('created_by', auth()->id())->whereKey($this->draftId)->first()?->delete();
        $this->reset('form', 'editingId', 'draftId', 'showForm');
    }

    protected function uploadTarget(): Model
    {
        if ($this->editingId) {
            return $this->editable($this->editingId);
        }
        abort_unless($this->canAdd(), 403);
        $draft = $this->draftId ? Resource::where('is_draft', true)->where('created_by', auth()->id())->find($this->draftId) : null;
        $draft ??= Resource::create(['project_id' => $this->project->id, 'title' => '', 'created_by' => auth()->id(), 'is_draft' => true]);
        $this->draftId = $draft->id;

        return $draft;
    }

    public function save(): void
    {
        $user = auth()->user();
        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.value' => 'required|string|max:50000',
            'form.labels' => 'array',
            'form.labels.*' => 'integer|exists:labels,id',
            'form.viewers' => 'array',
            'form.viewers.*' => 'integer|in:'.$this->candidates->pluck('id')->implode(','),
        ], ['form.title.required' => 'Give the resource a title.', 'form.value.required' => 'Enter the value to store.']);

        $labels = collect($this->form['labels'])->map(fn ($i) => (int) $i)->unique()->all();
        $viewers = collect($this->form['viewers'])->map(fn ($i) => (int) $i)->unique()->all();
        $title = trim($this->form['title']);

        if ($this->editingId) {
            $r = $this->editable($this->editingId);
            $changed = array_filter(['title' => $r->title !== $title, 'value' => $r->value !== $this->form['value']]);
            $r->update(['title' => $title, 'value' => $this->form['value']]);
            $changed && $r->logActivity('edited', implode(' and ', array_keys($changed))); // field names only, never the value
            $r->logSync('viewers', $viewers, 'viewer_added', 'viewer_removed');
            $r->syncLabels($labels);
        } else {
            abort_unless($this->canAdd(), 403);
            $draft = $this->draftId ? Resource::where('is_draft', true)->where('created_by', $user->id)->find($this->draftId) : null;
            $r = $draft ?? new Resource;
            $r->fill(['project_id' => $this->project->id, 'title' => $title, 'value' => $this->form['value'], 'created_by' => $user->id, 'is_draft' => false])->save();
            $r->logActivity('created');
            $r->viewers()->sync($viewers);
            $r->labels()->sync($labels);
        }

        $this->reset('form', 'editingId', 'draftId', 'showForm');
        unset($this->resources);
        $this->dispatch('resources-changed'); // open rows re-fetch their value and log
    }

    // ── expanded row ──

    /** Fetch the value for an expanded row (rendered + raw for Copy) and log the reveal; editors also get the access log. */
    public function reveal(int $id): array
    {
        $user = auth()->user();
        $r = $this->find($id);
        $r->logView($user);
        $out = ['html' => Markdown::render($r->value), 'raw' => (string) $r->value, 'log' => ''];
        if ($r->canEdit($user)) {
            $out['log'] = view('livewire.resources._log', ['activities' => $r->activities()->with('user')->limit(50)->get()])->render();
        }

        return $out;
    }

    public function toggleViewer(int $id, int $userId): void
    {
        $r = $this->editable($id);
        abort_unless($this->candidates->contains('id', $userId), 403);
        $ids = $r->viewers()->pluck('users.id')->all();
        $ids = in_array($userId, $ids, true) ? array_values(array_diff($ids, [$userId])) : [...$ids, $userId];
        $r->logSync('viewers', $ids, 'viewer_added', 'viewer_removed');
        unset($this->resources);
        $this->dispatch('resources-changed');
    }

    public function deleteResource(int $id): void
    {
        $r = $this->editable($id);
        $r->logActivity('deleted', null, $r->title); // the activity row outlives the resource as the audit trail
        $r->delete();
        unset($this->resources);
    }

    public function render()
    {
        return view('livewire.resources.panel');
    }
}
