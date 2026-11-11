<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/** From/to date filter with month, year and financial-year shortcuts. Pair with <x-ui.date-filter>; needs WithListSearch (pagination). */
trait WithDateFilter
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    /** Shortcuts as [from, to]. ponytail: financial year fixed to April–March; make it a setting if other clients need another start. */
    protected function periods(): array
    {
        $fy = today()->month >= 4 ? today()->setDate(today()->year, 4, 1) : today()->setDate(today()->year - 1, 4, 1);

        return array_map(fn ($r) => array_map(fn ($d) => $d->toDateString(), $r), [
            'month' => [today()->startOfMonth(), today()->endOfMonth()],
            'year' => [today()->startOfYear(), today()->endOfYear()],
            'fy' => [$fy, $fy->copy()->addYear()->subDay()],
        ]);
    }

    public function setPeriod(string $period): void
    {
        [$this->from, $this->to] = $this->periods()[$period] ?? ['', ''];
        $this->resetPage();
    }

    /** The active shortcut key, or false. */
    protected function activePeriod(): string|false
    {
        return array_search([$this->from, $this->to], $this->periods(), true);
    }

    protected function validDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** Limit $column to the valid from/to bounds; invalid input is ignored. */
    protected function applyDateFilter(Builder $q, string $column): Builder
    {
        return $q->when($this->validDate($this->from), fn ($q) => $q->whereDate($column, '>=', $this->from))
            ->when($this->validDate($this->to), fn ($q) => $q->whereDate($column, '<=', $this->to));
    }
}
