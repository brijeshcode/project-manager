<?php

namespace App\Livewire\Tax;

use App\Models\ExpensePayment;
use App\Models\Payment;
use App\Services\LocalCurrency;
use App\Support\IndiaTax;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Admin-only: TDS clients have deducted per financial year, plus a throwaway tax estimate (nothing is saved). */
class Index extends Component
{
    /** Start year of the financial year (2026 = FY 2026-27, April to March). */
    #[Url]
    public int $fy = 0;

    public string $receipts = '';
    public string $expenses = '';
    public bool $presumptive = false;
    public string $other = '';
    public string $deductions = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
        $this->fy = $this->fy >= 2023 && $this->fy <= $this->currentFy() ? $this->fy : $this->currentFy();
        $this->prefill();
    }

    public function updatedFy(): void
    {
        $this->prefill();
    }

    private function currentFy(): int
    {
        return today()->month >= 4 ? today()->year : today()->year - 1;
    }

    private function range(): array
    {
        return [$this->fy.'-04-01', ($this->fy + 1).'-03-31'];
    }

    private function payments()
    {
        return Payment::query()->whereHas('client', fn ($q) => $q->billable())->whereBetween('paid_on', $this->range())->where('local_currency', LocalCurrency::code());
    }

    /** Fill receipts and expenses from the books; the user can overwrite them. */
    private function prefill(): void
    {
        $this->receipts = (string) intdiv((int) $this->payments()->sum('local_amount_cents'), 100);
        $this->expenses = (string) intdiv((int) ExpensePayment::whereBetween('paid_on', $this->range())
            ->where('local_currency', LocalCurrency::code())->sum('local_amount_cents'), 100);
    }

    private static function rupees(string $v): int
    {
        return max(0, (int) round((float) str_replace(',', '', $v)));
    }

    public function render()
    {
        $this->authorize('manage-money');
        $this->fy = max(2023, min($this->fy, $this->currentFy()));
        $receipts = self::rupees($this->receipts);
        $business = $this->presumptive ? intdiv($receipts, 2) : max(0, $receipts - self::rupees($this->expenses));
        $gross = $business + self::rupees($this->other);
        $estimate = [
            'new' => IndiaTax::tax($gross, 'new', $this->fy) + ['taxable' => $gross],
            'old' => IndiaTax::tax(max(0, $gross - self::rupees($this->deductions)), 'old', $this->fy) + ['taxable' => max(0, $gross - self::rupees($this->deductions))],
        ];
        $deducted = $this->payments()->with('client:id,name')->where('deductions_cents', '>', 0)->get()
            ->groupBy('client_id')->map(fn ($rows) => [
                'client' => $rows->first()->client?->name ?? '—',
                'cents' => (int) $rows->sum('local_deductions_cents'),
                'count' => $rows->count(),
            ])->sortByDesc('cents')->values();

        return view('livewire.tax.index', [
            'local' => LocalCurrency::code(),
            'years' => range($this->currentFy(), 2023),
            'estimate' => $estimate,
            'deducted' => $deducted,
            'deductedTotal' => $deducted->sum('cents'),
            'missingRate' => $this->payments()->where('deductions_cents', '>', 0)->whereNull('local_deductions_cents')->count(),
        ]);
    }
}
