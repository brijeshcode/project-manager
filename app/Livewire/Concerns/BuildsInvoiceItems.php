<?php

namespace App\Livewire\Concerns;

use App\Models\BillableLine;
use App\Models\Client;
use App\Services\BillableService;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Invoice items shared by create and edit: the Sprints / Modules / Tasks / Other charges picker, work groups that print as
 * one row each, custom lines, and the page-only "Set aside" list. The host gives the client via itemsClient().
 */
trait BuildsInvoiceItems
{
    /** @var array<int, array{label:string, lines:array<int,string>}> invoice items built from billable lines; each prints as one row */
    public array $groups = [];

    /** @var array<int, array{description:string, quantity:string, unit_price:string}> */
    public array $custom = [];

    /** @var array<int, array{label:string, lines:array<int,string>}> items removed from the invoice (page state only, never saved) */
    public array $parkedGroups = [];

    /** @var array<int, array{description:string, quantity:string, unit_price:string}> custom lines set aside (page state only) */
    public array $parkedCustom = [];

    public string $tab = 'sprints';

    /** @var array<int, string> ticked ids in the work picker */
    public array $pickSprints = [];
    public array $pickModules = [];
    public array $pickTasks = [];
    public array $pickCharges = [];

    public bool $showQuantity = false;

    /** Page-only preview of the total in the local currency; never saved or printed. */
    public bool $showLocal = true;

    abstract protected function itemsClient(): Client;

    protected function resetPicks(): void
    {
        $this->pickSprints = $this->pickModules = $this->pickTasks = $this->pickCharges = [];
    }

    /** Opens the first tab that has something to pick. */
    protected function pickFirstTab(Client $client): void
    {
        $p = $this->picker($client);
        $this->tab = $p['sprints']->isNotEmpty() ? 'sprints' : ($p['modules']->isNotEmpty() ? 'modules' : ($p['charges']->isNotEmpty() ? 'charges' : 'sprints'));
    }

    /** Line ids already on the invoice or set aside: hidden from the picker. */
    protected function usedLineIds(): array
    {
        return collect([...$this->groups, ...$this->parkedGroups])->pluck('lines')->flatten()->map(fn ($i) => (int) $i)->all();
    }

    /**
     * The picker: pending task lines not used yet, with what each ticked sprint / module covers. A task in a ticked sprint
     * cannot also come through its module or on its own, and a task in a ticked module cannot come on its own, so no task is billed twice.
     */
    protected function picker(Client $client): array
    {
        $used = $this->usedLineIds();
        $lines = app(BillableService::class)->pendingTaskLines($client)->reject(fn ($l) => in_array($l->id, $used, true))->values();
        $sprintIds = array_map('intval', $this->pickSprints);
        $moduleIds = array_map('intval', $this->pickModules);
        $bySprint = $lines->filter(fn ($l) => in_array($l->task->sprint_id, $sprintIds, true))->pluck('id')->all();
        $byModule = $lines->filter(fn ($l) => ! in_array($l->id, $bySprint, true) && in_array($l->task->module_id, $moduleIds, true))->pluck('id')->all();
        $charges = BillableLine::pending()->where('client_id', $client->id)->whereNull('source_type')->orderBy('id')->get()
            ->reject(fn ($l) => in_array($l->id, $used, true))->values();

        return [
            'lines' => $lines,
            'sprints' => $lines->filter(fn ($l) => $l->task->sprint_id)->groupBy(fn ($l) => $l->task->sprint_id)
                ->map(fn ($g) => ['sprint' => $g->first()->task->sprint, 'lines' => $g])->sortBy(fn ($s) => $s['sprint']->starts_on)->values(),
            'modules' => $lines->groupBy(fn ($l) => $l->task->module_id)
                ->map(fn ($g) => ['module' => $g->first()->task->module, 'lines' => $g, 'free' => $g->reject(fn ($l) => in_array($l->id, $bySprint, true))])->values(),
            'charges' => $charges,
            'bySprint' => $bySprint,
            'byModule' => $byModule,
        ];
    }

    /** A recurring line as its own item: "Hosting (Nov 2026)" with an editable amount in the line's currency. */
    protected static function recurringGroup(BillableLine $l, ?string $label = null): array
    {
        $period = strlen((string) $l->period) === 7 ? \Carbon\Carbon::createFromFormat('!Y-m', $l->period)->format('M Y') : (string) $l->period;

        return [
            'label' => $label ?? $l->description.($period !== '' ? " ($period)" : ''),
            'lines' => [(string) $l->id],
            'amount' => Money::toInput((int) $l->amount_cents),
            'period' => $period,
            'currency' => strtoupper((string) $l->lineCurrency()),
        ];
    }

    /** Every due recurring service of the client goes on the invoice by default; removing one sets it aside (stays pending). */
    protected function addDueRecurring(Client $client): void
    {
        $used = $this->usedLineIds();
        BillableLine::pending()->where('client_id', $client->id)->whereNotNull('recurring_charge_id')->orderBy('period')->orderBy('id')->get()
            ->reject(fn ($l) => in_array($l->id, $used, true))
            ->each(fn ($l) => $this->groups[] = self::recurringGroup($l));
    }

    private static function codes(Collection $lines): string
    {
        return $lines->map(fn ($l) => $l->task->code())->sortBy(fn ($c) => (int) substr($c, strrpos($c, '-') + 1))->implode(', ');
    }

    private static function range($from, $to): string
    {
        return $from && $to ? $from->format('M j').($from->isSameDay($to) ? '' : ' – '.$to->format('M j')) : '';
    }

    /** Turns the ticked sprints, modules, tasks and charges into invoice items with a ready-made, editable label. */
    public function addPicked(): void
    {
        $this->authorize('manage-money');
        $p = $this->picker($this->itemsClient());
        $ids = fn (Collection $c) => $c->pluck('id')->map(fn ($i) => (string) $i)->all();

        foreach ($p['sprints'] as $s) {
            if (in_array((string) $s['sprint']->id, $this->pickSprints, true)) {
                $this->groups[] = ['label' => $s['sprint']->name.' ('.self::range($s['sprint']->starts_on, $s['sprint']->ends_on).'): completed '.self::codes($s['lines']), 'lines' => $ids($s['lines'])];
            }
        }
        foreach ($p['modules'] as $m) {
            if (in_array((string) $m['module']->id, $this->pickModules, true) && $m['free']->isNotEmpty()) {
                $this->groups[] = ['label' => $m['module']->name.': completed '.self::codes($m['free']), 'lines' => $ids($m['free'])];
            }
        }
        $tasks = $p['lines']->filter(fn ($l) => in_array((string) $l->id, $this->pickTasks, true) && ! in_array($l->id, [...$p['bySprint'], ...$p['byModule']], true))->values();
        if ($tasks->count() === 1) {
            $this->groups[] = ['label' => $tasks[0]->description, 'lines' => $ids($tasks)];
        } elseif ($tasks->isNotEmpty()) {
            $dates = $tasks->map(fn ($l) => $l->task->completed_at)->filter()->sort()->values();
            $range = self::range($dates->first(), $dates->last());
            $this->groups[] = ['label' => 'Tasks'.($range !== '' ? " ($range)" : '').': completed '.self::codes($tasks), 'lines' => $ids($tasks)];
        }
        foreach ($p['charges'] as $c) {
            if (in_array((string) $c->id, $this->pickCharges, true)) {
                $this->groups[] = $c->recurring_charge_id ? self::recurringGroup($c) : ['label' => $c->description, 'lines' => [(string) $c->id]];
            }
        }
        $this->resetPicks();
    }

    /** Everything unbilled in one click: one item per sprint, then per module for tasks outside a sprint, then each charge. */
    public function addAllWork(): void
    {
        $this->authorize('manage-money');
        $p = $this->picker($this->itemsClient());
        $this->pickSprints = $p['sprints']->map(fn ($s) => (string) $s['sprint']->id)->all();
        $this->pickModules = $p['modules']->map(fn ($m) => (string) $m['module']->id)->all();
        $this->pickTasks = [];
        $this->pickCharges = $p['charges']->map(fn ($c) => (string) $c->id)->all();
        $this->addPicked();
    }

    public function removeGroup(int $g): void
    {
        $this->authorize('manage-money');
        if (isset($this->groups[$g])) {
            $this->parkedGroups[] = $this->groups[$g];
            unset($this->groups[$g]);
            $this->groups = array_values($this->groups);
        }
    }

    public function restoreGroup(int $g): void
    {
        $this->authorize('manage-money');
        if (isset($this->parkedGroups[$g])) {
            $this->groups[] = $this->parkedGroups[$g];
            unset($this->parkedGroups[$g]);
            $this->parkedGroups = array_values($this->parkedGroups);
        }
    }

    /** Dropping an item from "Set aside": its work stays off this invoice and is billable again. */
    public function forgetGroup(int $g): void
    {
        $this->authorize('manage-money');
        unset($this->parkedGroups[$g]);
        $this->parkedGroups = array_values($this->parkedGroups);
    }

    public function discardLine(int $lineId): void
    {
        $this->authorize('manage-money');
        // task-sourced lines re-sync from tasks, so only custom/recurring lines of this client may be dropped
        BillableLine::where('client_id', $this->itemsClient()->id)->whereNull('invoice_id')->whereNull('source_type')->findOrFail($lineId)->delete();
        $this->pickCharges = array_values(array_diff($this->pickCharges, [(string) $lineId]));
    }

    public function addCustom(): void
    {
        $this->authorize('manage-money');
        $this->custom[] = ['description' => '', 'quantity' => '1', 'unit_price' => '', 'pass_through' => false];
    }

    /** Removing a line sets it aside instead of losing it; a blank line is just dropped. */
    public function removeCustom(int $i): void
    {
        $this->authorize('manage-money');
        $row = $this->custom[$i] ?? null;
        unset($this->custom[$i]);
        $this->custom = array_values($this->custom);
        if ($row && (trim((string) ($row['description'] ?? '')) !== '' || trim((string) ($row['unit_price'] ?? '')) !== '')) {
            $this->parkedCustom[] = $row;
        }
    }

    public function restoreCustom(int $i): void
    {
        $this->authorize('manage-money');
        if (isset($this->parkedCustom[$i])) {
            $this->custom[] = $this->parkedCustom[$i];
            unset($this->parkedCustom[$i]);
            $this->parkedCustom = array_values($this->parkedCustom);
        }
    }

    /** Hiding the quantity column folds every editable row into one unit at its line amount (2 x 50 becomes 1 x 100). */
    public function updatedShowQuantity(): void
    {
        if (! $this->showQuantity) {
            $this->custom = array_map(fn ($r) => self::asSingleUnit($r), $this->custom);
            if (property_exists($this, 'edits')) {
                $this->edits = array_map(fn ($r) => self::asSingleUnit($r), $this->edits);
            }
        }
    }

    protected static function asSingleUnit(array $r): array
    {
        $price = self::num($r['unit_price'] ?? '');

        return $price > 0 ? array_merge($r, ['quantity' => '1', 'unit_price' => Money::toInput(intdiv(self::num($r['quantity'] ?? '') * $price + 50, 100))]) : array_merge($r, ['quantity' => '1']);
    }

    /** Without the quantity column a custom line is one unit at the typed amount. */
    protected function flattenQuantities(): void
    {
        if (! $this->showQuantity) {
            $this->custom = array_map(fn ($r) => array_merge($r, ['quantity' => '1']), $this->custom);
        }
    }

    protected function itemRules(): array
    {
        return [
            'groups' => 'array|max:200',
            'groups.*.label' => ['bail', 'required', 'string', 'max:500'],
            'groups.*.lines' => 'required|array|max:500',
            'groups.*.lines.*' => ['bail', 'regex:/^\d{1,18}$/'],
            'groups.*.amount' => ['bail', 'sometimes', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('Must be greater than zero.')],
            'custom' => 'array|max:50',
        ];
    }

    /** @return array{0: array<int,int>, 1: array<int,string>} line ids in item order, and line id => printed label */
    protected function groupLines(): array
    {
        $ids = [];
        $labels = [];
        foreach ($this->groups as $g) {
            foreach ($g['lines'] as $id) {
                $ids[] = (int) $id;
                $labels[(int) $id] = $g['label'];
            }
        }

        return [$ids, $labels];
    }

    /** @return array<int,int> line id => typed amount in cents, for recurring items */
    protected function groupAmounts(): array
    {
        $out = [];
        foreach ($this->groups as $g) {
            if (isset($g['amount']) && count($g['lines']) === 1) {
                $out[(int) $g['lines'][0]] = Money::toCents($g['amount']);
            }
        }

        return $out;
    }

    protected static function num($v, int $digits = 7): int
    {
        return preg_match('/^\d{1,'.$digits.'}(\.\d+)?$/', trim((string) $v)) ? Money::toCents($v) : 0;
    }

    protected function rowAmount(array $r): int
    {
        return intdiv(($this->showQuantity ? self::num($r['quantity'] ?? '') : 100) * self::num($r['unit_price'] ?? '') + 50, 100);
    }

    /** Live totals; same math as InvoiceService, invalid inputs count as 0. $extra = cents already in the invoice currency (edit's own manual lines). */
    protected function itemsSummary(Client $client, string $cur, ?string $rate, int $extra = 0, int $extraCount = 0): array
    {
        $conv = app(InvoiceService::class)->lineConverter($cur, $rate);
        $ids = collect([...$this->groups, ...$this->parkedGroups])->pluck('lines')->flatten()->map(fn ($i) => (int) $i)->unique();
        $work = BillableLine::with(['project', 'client'])->where('client_id', $client->id)->whereIn('id', $ids)->get()->keyBy('id');
        $sum = fn (array $g) => (int) collect($g['lines'])->sum(fn ($id) => ($l = $work[(int) $id] ?? null)
            ? $conv(isset($g['amount']) ? self::num($g['amount']) : (int) $l->amount_cents, $l->lineCurrency()) : 0);
        $custom = array_map(fn ($r) => $this->rowAmount($r), $this->custom);
        $subtotal = array_sum(array_map($sum, $this->groups)) + array_sum($custom) + $extra;
        $tax = $subtotal < intdiv(PHP_INT_MAX, 20000) ? intdiv($subtotal * min(self::num($this->taxPercent, 3), 10000) + 5000, 10000) : 0;

        return [
            'rate' => $rate === null ? null : (str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate),
            'local' => $rate !== null && $cur !== LocalCurrency::code() ? Money::convert($subtotal + $tax, $rate) : null,
            'currency' => $cur,
            'count' => count($this->groups) + count($custom) + $extraCount,
            'custom' => $custom,
            'groups' => array_map($sum, $this->groups),
            'parked' => array_map($sum, $this->parkedGroups),
            'subtotal' => $subtotal,
            'tax' => $tax,
        ];
    }
}
