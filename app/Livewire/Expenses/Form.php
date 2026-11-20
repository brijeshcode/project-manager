<?php

namespace App\Livewire\Expenses;

use App\Enums\ExpenseFrequency;
use App\Livewire\Concerns\StoresExpenseFiles;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Add an expense (optionally paid now) or change one. */
class Form extends Component
{
    use StoresExpenseFiles;

    #[Locked]
    public ?int $expenseId = null;

    public ?int $expense_category_id = null;
    public string $description = '';
    public string $payee = '';
    public string $amount = '';
    public string $currency = '';
    public string $incurred_on = '';
    public ?int $client_id = null;
    public ?int $project_id = null;
    public string $frequency = '';
    public int|string $remind_days = 7;
    public string $notes = '';
    public bool $paid_now = true;
    public string $exchange_rate = '1';
    public string $method = '';

    /** @var array<int,mixed> */
    public array $files = [];

    public function mount(?Expense $expense = null): void
    {
        $this->authorize('manage-money');
        if (! $expense?->exists) {
            $this->currency = LocalCurrency::code();
            $this->incurred_on = today()->toDateString();
            $this->expense_category_id = ExpenseCategory::orderBy('position')->orderBy('name')->value('id');

            return;
        }
        $this->expenseId = $expense->id;
        $this->expense_category_id = $expense->expense_category_id;
        $this->description = $expense->description;
        $this->payee = (string) $expense->payee;
        $this->amount = Money::toInput($expense->amount_cents);
        $this->currency = (string) $expense->currency;
        $this->exchange_rate = (string) ($expense->exchange_rate ?? LocalCurrency::rateFor($this->currency));
        $this->incurred_on = $expense->incurred_on->toDateString();
        $this->client_id = $expense->client_id;
        $this->project_id = $expense->project_id;
        $this->frequency = (string) $expense->frequency?->value;
        $this->remind_days = $expense->remind_days ?? 7;
        $this->notes = (string) $expense->notes;
    }

    public function updatedClientId(): void
    {
        $this->reset('project_id');
    }

    public function updatedCurrency(): void
    {
        $this->exchange_rate = (string) LocalCurrency::rateFor($this->currency);
    }

    public function removeAttachment(int $id): void
    {
        $this->authorize('manage-money');
        Expense::findOrFail($this->expenseId)->attachments()->findOrFail($id)->delete();
        $this->dispatch('toast', message: 'File removed.');
    }

    /** The local currency plus every active one. */
    private function currencyOptions(): array
    {
        return collect([LocalCurrency::code()])->merge(Currency::active()->pluck('code'))->unique()->sort()->values()->all();
    }

    private function isForeign(): bool
    {
        return strtoupper($this->currency) !== LocalCurrency::code();
    }

    public function save()
    {
        $this->authorize('manage-money');
        $local = LocalCurrency::code();
        $foreign = $this->isForeign();
        $this->validate($this->fileRules('files'));
        $data = $this->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'description' => 'required|string|max:255',
            'payee' => 'nullable|string|max:255',
            'currency' => ['required', Rule::in($this->currencyOptions())],
            'amount' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.')],
            'incurred_on' => 'required|date_format:Y-m-d',
            'client_id' => 'nullable|exists:clients,id',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('client_id', $this->client_id)],
            'frequency' => ['nullable', new Enum(ExpenseFrequency::class)],
            'remind_days' => 'required|integer|min:0|max:365',
            'notes' => 'nullable|string|max:500',
            'method' => 'nullable|string|max:100',
            'exchange_rate' => $foreign ? ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')] : 'nullable',
        ]);
        $cents = Money::toCents($data['amount']);
        $rate = $foreign ? $data['exchange_rate'] : '1';
        $fields = [
            'expense_category_id' => $data['expense_category_id'], 'description' => $data['description'], 'payee' => $data['payee'] ?: null,
            'currency' => strtoupper($data['currency']), 'amount_cents' => $cents, 'exchange_rate' => $rate, 'incurred_on' => $data['incurred_on'],
            'client_id' => $data['client_id'] ?: null, 'project_id' => $data['project_id'] ?: null,
            'frequency' => $data['frequency'] ?: null, 'remind_days' => (int) $data['remind_days'], 'notes' => $data['notes'] ?: null,
        ];

        $expense = DB::transaction(function () use ($fields, $data, $cents, $local, $rate) {
            if ($this->expenseId) {
                $expense = tap(Expense::findOrFail($this->expenseId))->update($fields);
            } else {
                $expense = Expense::create($fields);
                if ($this->paid_now) {
                    $expense->payments()->create([
                        'paid_on' => $data['incurred_on'], 'amount_cents' => $cents, 'exchange_rate' => $rate,
                        'local_amount_cents' => Money::convert($cents, $rate), 'local_currency' => $local, 'method' => $data['method'] ?: null,
                    ]);
                }
            }
            $this->storeFiles($expense, $this->files);

            return $expense;
        });
        session()->flash('toast', $this->expenseId ? 'Expense updated.' : 'Expense added.');

        return $this->redirectRoute('expenses.show', $expense);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $local = LocalCurrency::code();
        $client = $this->client_id ? Client::find($this->client_id) : null;
        $foreign = $this->isForeign();
        $localHint = $foreign && Money::isValidRate($this->exchange_rate) && preg_match('/^\d{1,7}(\.\d{1,2})?$/', $this->amount)
            ? Money::convert(Money::toCents($this->amount), $this->exchange_rate) : null;

        return view('livewire.expenses.form', [
            'local' => $local,
            'foreign' => $foreign,
            'localHint' => $localHint,
            'currencies' => $this->currencyOptions(),
            'categories' => ExpenseCategory::orderBy('position')->orderBy('name')->get(['id', 'name']),
            'frequencies' => ExpenseFrequency::cases(),
            'attachments' => $this->expenseId ? Expense::findOrFail($this->expenseId)->attachments()->orderBy('id')->get() : collect(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => $client ? $client->projects()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
