<?php

namespace App\Livewire\Invoices;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Services\ClientAccount;
use App\Services\InvoiceService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $invoiceId;

    public string $bankAccountId = '';

    public string $statusPick = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorize('manage-money');
        $this->invoiceId = $invoice->id;
        $this->bankAccountId = (string) ($invoice->bank_account_id ?? '');
    }

    /** Pick which bank details print on the invoice, or none; the chosen account is snapshotted like on create. */
    public function updatedBankAccountId(): void
    {
        $this->authorize('manage-money');
        $invoice = Invoice::findOrFail($this->invoiceId);
        abort_if($invoice->status === 'void', 403, 'A cancelled invoice cannot be changed.');
        $bank = $this->bankAccountId === '' ? null : BankAccount::find((int) $this->bankAccountId);
        if ($this->bankAccountId !== '' && ! $bank) {
            $this->bankAccountId = (string) ($invoice->bank_account_id ?? '');

            return;
        }
        $invoice->update([
            'bank_account_id' => $bank?->id,
            'bank_snapshot' => $bank?->only(['account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email']),
        ]);
        $this->dispatch('toast', type: 'success', message: $bank ? 'Bank details added to the invoice.' : 'Bank details removed from the invoice.');
    }

    public function updatedStatusPick(InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $ok = $svc->setStatus(Invoice::findOrFail($this->invoiceId), $this->statusPick);
        $this->dispatch('toast', type: $ok ? 'success' : 'error', message: $ok ? 'Status updated.' : 'This invoice cannot change status.');
    }

    public function void(InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $svc->void(Invoice::findOrFail($this->invoiceId));
        $this->dispatch('toast', type: 'success', message: 'Invoice voided.');
    }

    public function delete(InvoiceService $svc)
    {
        $this->authorize('manage-money');
        $svc->delete(Invoice::findOrFail($this->invoiceId));
        session()->flash('toast', 'Invoice deleted.');

        return $this->redirectRoute('invoices.index');
    }

    public function sendNow(InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $ok = $svc->markSent(Invoice::with('client')->findOrFail($this->invoiceId));
        $this->dispatch('toast', type: $ok ? 'success' : 'error', message: $ok ? 'Invoice sent.' : 'This invoice was already sent.');
    }

    public function resend(InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $ok = $svc->resend(Invoice::with('client')->findOrFail($this->invoiceId));
        $this->dispatch('toast', type: $ok ? 'success' : 'error', message: $ok ? 'Invoice email queued.' : 'Could not email this invoice (void, no contact email, or mail error).');
    }

    public function render()
    {
        $this->authorize('manage-money');
        $invoice = Invoice::with(['client', 'lines'])->findOrFail($this->invoiceId);
        $account = app(ClientAccount::class)->totals([$invoice->client_id])[$invoice->client_id];
        $balance = $account['billed'] - $account['received'];
        $this->statusPick = $invoice->status;

        return view('livewire.invoices.show', [
            'invoice' => $invoice,
            'balance' => $balance,
            'banks' => BankAccount::orderBy('label')->get(['id', 'label', 'bank_name']),
        ]);
    }
}
