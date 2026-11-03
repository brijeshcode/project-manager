<?php

namespace App\Livewire\Concerns;

use App\Models\Label;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Shared behaviour of the GitHub-issue style pages (module, task, subtask): inline title and description
 * editing, comments with replies, sidebar panels for assignees/labels, and the merged timeline.
 * The host provides item(), itemProject() and authorizeEdit(); extra panels go through saveExtra()/fillPanel().
 */
trait ItemPage
{
    use HandlesMarkdownUploads;

    public bool $editingTitle = false;

    public string $title = '';

    public bool $editingDesc = false;

    public string $description = '';

    public string $commentBody = '';

    public ?int $replyTo = null;

    public string $replyBody = '';

    /** Sidebar section currently being edited. */
    public ?string $panel = null;

    public array $assigneeIds = [];

    public array $labelIds = [];

    public string $newLabel = '';

    public string $newLabelColor = '#3b82f6';

    abstract protected function item(): Model;

    abstract protected function itemProject(): Project;

    abstract protected function authorizeEdit(): void;

    protected function titleField(): string
    {
        return 'title';
    }

    protected function fillPanel(string $panel): void {}

    protected function saveExtra(string $panel): void {}

    protected function uploadTarget(): Model
    {
        $this->authorize('view', $this->itemProject());

        return $this->item();
    }

    // ── title / description ───────────────────────────────────────────────────

    public function editTitle(): void
    {
        $this->authorizeEdit();
        $this->title = $this->item()->{$this->titleField()};
        $this->resetErrorBag();
        $this->editingTitle = true;
    }

    public function saveTitle(): void
    {
        $this->authorizeEdit();
        $this->validate(['title' => 'required|string|max:255']);
        $this->item()->update([$this->titleField() => trim($this->title)]);
        $this->editingTitle = false;
    }

    public function editDescription(): void
    {
        $this->authorizeEdit();
        $this->description = $this->item()->description ?? '';
        $this->resetErrorBag();
        $this->editingDesc = true;
    }

    public function saveDescription(): void
    {
        $this->authorizeEdit();
        $this->validate(['description' => 'nullable|string|max:20000']);
        $this->item()->update(['description' => trim($this->description) ?: null]);
        $this->editingDesc = false;
    }

    public function cancelDescription(): void
    {
        $this->editingDesc = false;
    }

    // ── comments ──────────────────────────────────────────────────────────────

    public function postComment(): void
    {
        $this->authorize('view', $this->itemProject());
        $this->validate(['commentBody' => 'required|string|max:10000']);
        $this->item()->addComment(auth()->user(), $this->commentBody);
        $this->reset('commentBody');
    }

    public function startReply(int $commentId): void
    {
        $this->replyTo = $commentId;
        $this->replyBody = '';
    }

    public function postReply(): void
    {
        $this->authorize('view', $this->itemProject());
        $this->validate(['replyBody' => 'required|string|max:10000', 'replyTo' => 'required|integer']);
        $this->item()->addComment(auth()->user(), $this->replyBody, $this->replyTo);
        $this->reset('replyBody', 'replyTo');
    }

    // ── sidebar panels ────────────────────────────────────────────────────────

    public function openPanel(string $panel): void
    {
        $this->authorizeEdit();
        $this->resetErrorBag();
        $this->assigneeIds = $this->item()->assignees()->pluck('users.id')->all();
        $this->labelIds = $this->item()->labels()->pluck('labels.id')->all();
        $this->fillPanel($panel);
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->resetErrorBag();
    }

    public function addLabel(): void
    {
        $this->authorizeEdit();
        $this->validate(['newLabel' => 'required|string|max:50', 'newLabelColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $label = Label::firstOrCreate(['name' => trim($this->newLabel)], ['color' => $this->newLabelColor]);
        $this->labelIds = array_values(array_unique([...$this->labelIds, $label->id]));
        $this->reset('newLabel');
    }

    public function savePanel(): void
    {
        $this->authorizeEdit();
        match ($this->panel) {
            // only active project members can be assigned
            'assignees' => $this->item()->syncAssignees(array_values(array_intersect(array_map('intval', $this->assigneeIds), $this->candidates()->pluck('id')->all()))),
            'labels' => $this->item()->syncLabels(Label::whereIn('id', $this->labelIds)->pluck('id')->all()),
            default => $this->saveExtra((string) $this->panel),
        };
        if (! $this->getErrorBag()->any()) {
            $this->closePanel();
        }
    }

    /** Active project members: who can be assigned. */
    protected function candidates(): Collection
    {
        return $this->itemProject()->members()->wherePivot('active', true)->orderBy('name')->get();
    }

    // ── read side ─────────────────────────────────────────────────────────────

    /** Extra timeline rows ('kind' => 'budget' etc.) a host wants merged in. */
    protected function extraTimeline(): Collection
    {
        return collect();
    }

    /** Comment threads and activity as one list, oldest first (like a GitHub issue). */
    public function timeline(): Collection
    {
        $m = $this->item();
        $items = $m->commentThreads()->map(fn ($c) => ['at' => $c->created_at, 'kind' => 'comment', 'seq' => $c->id, 'm' => $c]);
        $items = $items->concat($m->activities()->with('user')->get()->map(fn ($a) => ['at' => $a->created_at, 'kind' => 'activity', 'seq' => $a->id, 'm' => $a]));
        $items = $items->concat($this->extraTimeline());

        return $items->sortBy(fn ($i) => [$i['at']?->getTimestamp() ?? 0, $i['seq']])->values();
    }

    /** Assignees plus everyone who commented. */
    protected function participants(): Collection
    {
        $m = $this->item();

        return $m->assignees()->get()->concat($m->comments()->with('author')->get()->pluck('author')->filter())->unique('id')->values();
    }

    /** Data every page view needs for the sidebar editors. */
    protected function panelData(): array
    {
        return [
            'candidates' => in_array($this->panel, ['assignees'], true) ? $this->candidates() : collect(),
            'allLabels' => $this->panel === 'labels' ? Label::orderBy('name')->get() : collect(),
            'assignees' => $this->item()->assignees()->orderBy('name')->get(),
            'labels' => $this->item()->labels()->orderBy('name')->get(),
            'participants' => $this->participants(),
            'timeline' => $this->timeline(),
        ];
    }
}
