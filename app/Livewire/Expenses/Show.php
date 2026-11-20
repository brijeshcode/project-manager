<?php

namespace App\Livewire\Expenses;

use App\Livewire\Concerns\StoresExpenseFiles;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use StoresExpenseFiles;

    #[Locked]
    public int $expenseId;

/** The payment being edited in the modal; null = adding a new one. */
    #[Locked]
    public ?int $editingPaymentId = null;

    public string $paid_on = '';
    public string $amount = '';
    public string $method = '';
    public string $exchange_rate = '';
    public string $notes = '';

    /** @var array<int,mixed> files for the new payment */
    public array $files = [];

    /** @var array<int,mixed> more files for the expense itself */
    public array $expenseFiles = [];

    public function mount(Expense $expense): void
    {
        $this->authorize('manage-money');
        $this->expenseId = $expense->id;
        $this->resetPaymentForm($expense);
    }

    /** Blank add-payment form: today, the remaining amount, the expense's rate. */
    private function resetPaymentForm(Expense $e): void
    {
        $this->reset('method', 'notes', 'files', 'editingPaymentId');
        $this->resetValidation();
        $this->paid_on = today()->toDateString();
        $this->amount = Money::toInput($e->dueCents());
        $this->exchange_rate = (string) ($e->exchange_rate ?? LocalCurrency::rateFor((string) $e->currency));
    }

    /** Opens the add-payment modal with the remaining amount filled in. */
    public function openPayment(): void
    {
        $this->authorize('manage-money');
        $this->resetPaymentForm(Expense::with('payments')->findOrFail($this->expenseId));
    }

    /** Opens the modal filled with an existing payment. */
    public function editPayment(int $paymentId): void
    {
        $this->authorize('manage-money');
        $p = Expense::findOrFail($this->expenseId)->payments()->findOrFail($paymentId);
        $this->reset('files');
        $this->resetValidation();
        $this->editingPaymentId = $p->id;
        $this->paid_on = $p->paid_on->toDateString();
        $this->amount = Money::toInput($p->amount_cents);
        $this->exchange_rate = (string) ($p->exchange_rate ?? '');
        $this->method = (string) $p->method;
        $this->notes = (string) $p->notes;
    }

    public function addPayment(): void
    {
        $this->authorize('manage-money');
        $e = Expense::findOrFail($this->expenseId);
        $editing = $this->editingPaymentId ? $e->payments()->findOrFail($this->editingPaymentId) : null;
        // when editing, this payment's own amount is available again
        $max = $e->dueCents() + ($editing?->amount_cents ?? 0);
        $local = LocalCurrency::code();
        $foreign = strtoupper((string) $e->currency) !== $local;
        $this->validate($this->fileRules('files'));
        $data = $this->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.'), fn ($a, $v, $fail) => Money::toCents($v) <= $max || $fail('Cannot be more than the due amount ('.Money::format($max, (string) $e->currency).').')],
            'method' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'exchange_rate' => $foreign ? ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')] : 'nullable',
        ]);
        $rate = $foreign ? $data['exchange_rate'] : '1';
        $cents = Money::toCents($data['amount']);
        $fields = [
            'paid_on' => $data['paid_on'], 'amount_cents' => $cents, 'exchange_rate' => $rate,
            'local_amount_cents' => Money::convert($cents, $rate), 'local_currency' => $local,
            'method' => $data['method'] ?: null, 'notes' => $data['notes'] ?: null,
        ];
        $editing?->update($fields);
        $p = $editing ?? $e->payments()->create($fields);
        $this->storeFiles($p, $this->files);
        $this->resetPaymentForm($e->load('payments'));
        $this->dispatch('close-modal', 'add-payment');
        $this->dispatch('toast', message: $editing ? 'Payment updated.' : 'Payment added.');
    }

    public function uploadFiles(): void
    {
        $this->authorize('manage-money');
        $this->validate($this->fileRules('expenseFiles'));
        $this->storeFiles(Expense::findOrFail($this->expenseId), $this->expenseFiles);
        $this->reset('expenseFiles');
        $this->dispatch('toast', message: 'Files added.');
    }

    /** A file of this expense or of one of its payments. */
    public function deleteAttachment(int $id): void
    {
        $this->authorize('manage-money');
        Attachment::whereKey($id)->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('attachable_type', Expense::class)->where('attachable_id', $this->expenseId))
            ->orWhere(fn ($w) => $w->where('attachable_type', ExpensePayment::class)
                ->whereIn('attachable_id', ExpensePayment::where('expense_id', $this->expenseId)->select('id'))))
            ->firstOrFail()->delete();
        $this->dispatch('toast', message: 'File deleted.');
    }

    public function deletePayment(int $paymentId): void
    {
        $this->authorize('manage-money');
        $e = Expense::findOrFail($this->expenseId);
        $p = $e->payments()->findOrFail($paymentId);
        $p->attachments->each->delete();
        $p->delete();
        $this->resetPaymentForm($e->load('payments'));
        $this->dispatch('toast', message: 'Payment deleted.');
    }

    public function delete()
    {
        $this->authorize('manage-money');
        $e = Expense::with('attachments', 'payments.attachments')->findOrFail($this->expenseId);
        $e->attachments->each->delete();
        $e->payments->each(fn ($p) => $p->attachments->each->delete());
        $e->delete(); // payments cascade
        Log::info('expense deleted', ['id' => $e->id, 'amount_cents' => $e->amount_cents, 'user_id' => auth()->id()]);
        session()->flash('toast', 'Expense deleted.');

        return $this->redirectRoute('expenses.index');
    }

    public function render()
    {
        $this->authorize('manage-money');
        $local = LocalCurrency::code();
        $expense = Expense::with(['category', 'client', 'project', 'attachments', 'payments' => fn ($q) => $q->with('attachments')->orderBy('paid_on')->orderBy('id')])->findOrFail($this->expenseId);

        return view('livewire.expenses.show', [
            'expense' => $expense,
            'local' => $local,
            'foreign' => strtoupper((string) $expense->currency) !== $local,
        ]);
    }
}
