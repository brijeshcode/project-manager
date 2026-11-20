<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\RemembersView;
use App\Livewire\Concerns\WithDateFilter;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Invoice;
use Livewire\Attributes\Url;
use Livewire\Component;

class Invoices extends Component
{
    use RemembersView;
    use WithListSearch, WithDateFilter;

    #[Url(as: 'view')]
    public string $view = 'list';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
    }

    public function render()
    {
        $this->view = in_array($this->view, ['cards', 'list'], true) ? $this->view : 'list';
        $like = $this->likeTerm();
        // sent only (drafts and void hidden); explicit columns, never local amounts or rates
        $filtered = $this->applyDateFilter(Invoice::where('client_id', auth()->user()->client_id)->where('status', 'sent')
            ->when($like, fn ($q) => $q->whereRaw("number LIKE ? ESCAPE '!'", [$like])), 'issued_on');
        $sent = (clone $filtered)->get(['id', 'client_id', 'issued_on', 'currency_id', 'total_cents']);
        $byCur = fn ($rows) => $rows->groupBy(fn ($i) => $i->currency)->map->sum('total_cents')->all();
        $month = $sent->filter(fn ($i) => $i->issued_on->isSameMonth(today()));

        return view('livewire.portal.invoices', [
            'invoices' => $this->fitPage($filtered->latest('issued_on')->latest('id')->select(['id', 'number', 'client_id', 'issued_on', 'for_month', 'due_on', 'currency_id', 'total_cents']), \App\Support\Settings::pageSize()),
            'stats' => [
                'invoiced' => $byCur($sent),
                'count' => $sent->count(),
                'month' => $byCur($month),
                'month_count' => $month->count(),
                'average' => $sent->groupBy(fn ($i) => $i->currency)->map(fn ($rows) => intdiv((int) $rows->sum('total_cents'), $rows->count()))->all(),
            ],
            'currency' => auth()->user()->client->currency,
            'period' => $this->activePeriod(),
            'years' => $this->yearsSince(Invoice::where('client_id', auth()->user()->client_id)->where('status', 'sent')->min('issued_on')),
            'year' => $this->activeYear(),
        ])->layout('layouts.app');
    }
}
