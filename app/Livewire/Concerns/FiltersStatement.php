<?php

namespace App\Livewire\Concerns;

use App\Models\Client;
use App\Support\ClientStatement;

/** The client statement with its date filter and sort; shared by the client page and the portal. */
trait FiltersStatement
{
    public string $from = '';

    public string $to = '';

    public string $dir = 'desc';

    public function toggleDir(): void
    {
        $this->dir = $this->dir === 'desc' ? 'asc' : 'desc';
    }

    private function periods(): array
    {
        return \App\Support\Periods::ranges();
    }

    public function setPeriod(string $period): void
    {
        [$this->from, $this->to] = $this->periods()[$period] ?? ['', ''];
    }

    private function validDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** Ledger rows inside the date filter plus the balance carried in from before it. */
    private function statement(Client $client): array
    {
        $all = ClientStatement::rows($client);
        $from = $this->validDate($this->from) ? $this->from : null;
        $to = $this->validDate($this->to) ? $this->to : null;
        $day = fn ($r) => (string) $r['date']?->toDateString();
        $before = $from ? $all->filter(fn ($r) => $day($r) < $from) : collect();
        $rows = $all->filter(fn ($r) => (! $from || $day($r) >= $from) && (! $to || $day($r) <= $to))->values();
        $opening = $before->isEmpty() ? 0 : $before->last()['balance'];

        return [
            'rows' => $this->dir === 'asc' ? $rows : $rows->reverse()->values(),
            'opening' => $opening,
            'debit' => $rows->where('type', 'invoice')->sum('amount'),
            'credit' => $rows->where('type', 'payment')->sum('amount'),
            'closing' => $rows->isEmpty() ? $opening : $rows->last()['balance'],
            'period' => array_search([$this->from, $this->to], $this->periods(), true),
        ];
    }
}
