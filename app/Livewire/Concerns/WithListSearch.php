<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

trait WithListSearch
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(...array_filter(['search', 'status', 'clientFilter'], fn ($p) => property_exists($this, $p)));
        $this->resetPage();
    }

    /** Trimmed, max 100 chars, LIKE-escaped (use with ESCAPE '!': portable across SQLite and MySQL); null when empty. */
    protected function likeTerm(): ?string
    {
        $t = mb_substr(trim($this->search), 0, 100);

        return $t === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t).'%';
    }

    /** Paginate, snapping a page number beyond the last back to the last page. */
    protected function fitPage(BuilderContract $query, int $perPage)
    {
        $rows = $query->paginate($perPage, ['*'], 'page', $this->getPage());
        if ($rows->currentPage() > $rows->lastPage()) {
            $this->setPage($rows->lastPage());
            $rows = $query->paginate($perPage, ['*'], 'page', $rows->lastPage());
        }

        return $rows;
    }
}
