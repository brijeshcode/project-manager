<?php

namespace App\Livewire\Expenses;

use App\Livewire\Concerns\StoresExpenseFiles;
use App\Livewire\Concerns\WithDateFilter;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Services\LocalCurrency;
use App\Support\Money;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Money going out. Admin only; never shown to clients. */
class Index extends Component
{
    use StoresExpenseFiles, WithDateFilter, WithListSearch;

    /** The category id filter; named typeFilter so WithListSearch::clearFilters resets it. */
    #[Url(as: 'category')]
    public string $typeFilter = '';

    /** 'expenses' | 'payments' */
    #[Url]
    public string $tab = 'expenses';

    /** The Pay modal. */
    public ?int $payExpenseId = null;
    public string $paid_on = '';
    public string $amount = '';
    public string $exchange_rate = '';
    public string $method = '';
    public string $notes = '';

    /** @var array<int,mixed> */
    public array $files = [];

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function openPay(int $id): void
    {
        $this->authorize('manage-money');
        $e = Expense::with('payments')->findOrFail($id);
        $this->resetValidation();
        $this->reset('method', 'notes', 'files');
        $this->payExpenseId = $e->id;
        $this->paid_on = today()->toDateString();
        $this->amount = Money::toInput($e->dueCents());
        $this->exchange_rate = (string) ($e->exchange_rate ?? LocalCurrency::rateFor((string) $e->currency));
    }

    public function pay(): void
    {
        $this->authorize('manage-money');
        $e = Expense::findOrFail($this->payExpenseId);
        $local = LocalCurrency::code();
        $foreign = strtoupper((string) $e->currency) !== $local;
        $this->validate($this->fileRules('files'));
        $data = $this->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.')],
            'method' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'exchange_rate' => $foreign ? ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')] : 'nullable',
        ]);
        $rate = $foreign ? $data['exchange_rate'] : '1';
        $cents = Money::toCents($data['amount']);
        $p = $e->payments()->create([
            'paid_on' => $data['paid_on'], 'amount_cents' => $cents, 'exchange_rate' => $rate,
            'local_amount_cents' => Money::convert($cents, $rate), 'local_currency' => $local,
            'method' => $data['method'] ?: null, 'notes' => $data['notes'] ?: null,
        ]);
        $this->storeFiles($p, $this->files);
        $this->reset('payExpenseId', 'files');
        $this->dispatch('close-modal', 'pay-expense');
        $this->dispatch('toast', message: 'Payment added.');
    }

    public function render()
    {
        $this->authorize('manage-money');
        $like = $this->likeTerm();
        $categoryId = ctype_digit($this->typeFilter) ? (int) $this->typeFilter : null;
        $base = Expense::query()
            ->when($categoryId, fn ($q) => $q->where('expense_category_id', $categoryId))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("description LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("payee LIKE ? ESCAPE '!'", [$like])));
        $local = LocalCurrency::code();
        // paid in the range: payments dated in it, for expenses matching the other filters
        $paid = $this->applyDateFilter(ExpensePayment::whereIn('expense_id', (clone $base)->select('id')), 'paid_on');
        $filtered = $this->applyDateFilter(clone $base, 'incurred_on')
            ->when($this->status === 'due', fn ($q) => $q->due())
            ->when($this->status === 'paid', fn ($q) => $q->whereNotIn('id', Expense::due()->select('id')));
        $payments = $this->tab === 'payments'
            ? $this->fitPage((clone $paid)->with('expense.category')->orderByDesc('paid_on')->orderByDesc('id'), \App\Support\Settings::pageSize())
            : null;

        return view('livewire.expenses.index', [
            'local' => $local,
            'paidTotal' => (int) (clone $paid)->where('local_currency', $local)->sum('local_amount_cents'),
            'paidCount' => (clone $paid)->count(),
            'dueTotal' => (clone $filtered)->due()->with('payments')->get()->sum(fn ($e) => $e->toLocal($e->dueCents()) ?? 0),
            'count' => (clone $filtered)->count(),
            'categories' => ExpenseCategory::orderBy('position')->orderBy('name')->get(['id', 'name']),
            'period' => $this->activePeriod(),
            'years' => $this->yearsSince(Expense::min('incurred_on')),
            'year' => $this->activeYear(),
            'payExpense' => $this->payExpenseId ? Expense::find($this->payExpenseId) : null,
            'payments' => $payments,
            'expenses' => $payments ? null : $this->fitPage((clone $filtered)->with(['category', 'client', 'project', 'payments'])
                ->orderByDesc('incurred_on')->orderByDesc('id'), \App\Support\Settings::pageSize()),
        ]);
    }
}
