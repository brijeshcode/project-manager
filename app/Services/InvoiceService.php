<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    // Bounds keep all integer math below PHP_INT_MAX: 40e9 subtotal x 2 (100% tax) = 8e10 total;
    // x 99,999,999 (max INR rate 9999.9999 scaled by 1e4) = 8e18 < 9.22e18.
    public const MAX_LINE_CENTS = 2_000_000_000;
    public const MAX_SUBTOTAL_CENTS = 40_000_000_000;

    /**
     * @param  array<int>  $lineIds  pending lines of this client
     * @param  array<int, array{description:string, quantity:mixed, unit_price:mixed}>  $customLines
     */
    public function create(Client $client, array $lineIds, array $customLines = [], float $taxPercent = 0.0, ?int $bankAccountId = null, ?float $inrRate = null): Invoice
    {
        $lineIds = array_values(array_unique($lineIds));
        $pctHundredths = $this->validatedPercent($taxPercent);
        $custom = $this->validatedCustomLines($customLines);

        if (! $lineIds && ! $custom) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line.']);
        }

        $invoice = DB::transaction(function () use ($client, $lineIds, $custom, $pctHundredths, $bankAccountId, $inrRate) {
            $picked = BillableLine::pending()->where('client_id', $client->id)->whereIn('id', $lineIds)->lockForUpdate()->get();
            if ($picked->count() !== count($lineIds)) {
                throw ValidationException::withMessages(['lines' => 'Some lines are not pending lines of this client.']);
            }

            if ($picked->max('amount_cents') > self::MAX_LINE_CENTS) {
                throw ValidationException::withMessages(['lines' => 'A line amount is too large to invoice.']);
            }
            $subtotal = (int) $picked->sum('amount_cents') + array_sum(array_column($custom, 'amount_cents'));
            if ($subtotal > self::MAX_SUBTOTAL_CENTS) {
                throw ValidationException::withMessages(['lines' => 'The invoice subtotal is too large.']);
            }
            // half-up: percent is in hundredths, so tax = subtotal * pct / 10000
            $tax = intdiv($subtotal * $pctHundredths + 5000, 10000);

            $bank = $bankAccountId ? BankAccount::findOrFail($bankAccountId) : null;
            $terms = $client->payment_terms;
            $issued = now()->startOfDay();

            $invoice = $this->insertNumbered([
                'client_id' => $client->id,
                'status' => 'sent',
                'issued_on' => $issued,
                'due_on' => $terms && preg_match('/^net-(\d{1,3})$/i', trim($terms), $m) ? $issued->copy()->addDays((int) $m[1]) : null,
                'currency' => $client->currency,
                'subtotal_cents' => $subtotal,
                'tax_percent' => sprintf('%d.%02d', intdiv($pctHundredths, 100), $pctHundredths % 100),
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'bank_account_id' => $bank?->id,
                'bank_snapshot' => $bank ? $bank->only(['account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email']) : null,
                'inr_rate' => $inrRate,
                'terms' => $terms,
            ], $issued->format('Ymd'));

            BillableLine::whereIn('id', $picked->pluck('id'))->update(['invoice_id' => $invoice->id]);
            foreach ($custom as $line) {
                BillableLine::create($line + ['client_id' => $client->id, 'invoice_id' => $invoice->id]);
            }

            return $invoice;
        });

        // after commit: a mail problem must never fail or roll back invoice creation
        $this->send($invoice);

        return $invoice;
    }

    /** Email once: false if already emailed, void, or the client has no contact email. */
    public function send(Invoice $invoice): bool
    {
        if ($invoice->emailed_at !== null || ! $this->deliverable($invoice)) {
            return false;
        }
        // atomic claim so concurrent callers cannot double-dispatch
        if (Invoice::whereKey($invoice->id)->whereNull('emailed_at')->update(['emailed_at' => now()]) !== 1) {
            return false;
        }
        if (! $this->queueMail($invoice)) {
            Invoice::whereKey($invoice->id)->update(['emailed_at' => null]);

            return false;
        }
        $invoice->refresh();

        return true;
    }

    /** Owner-triggered re-send: ignores emailed_at, still refuses void / no contact email. */
    public function resend(Invoice $invoice): bool
    {
        if (! $this->deliverable($invoice) || ! $this->queueMail($invoice)) {
            return false;
        }
        Invoice::whereKey($invoice->id)->update(['emailed_at' => now()]);
        $invoice->refresh();

        return true;
    }

    private function deliverable(Invoice $invoice): bool
    {
        return $invoice->status !== 'void' && filled($invoice->client?->contact_email);
    }

    private function queueMail(Invoice $invoice): bool
    {
        try {
            Mail::queue(new InvoiceMail($invoice));

            return true;
        } catch (\Throwable $e) {
            Log::error('Invoice email failed to queue', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function void(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $locked = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status === 'void') {
                return;
            }
            $locked->update(['status' => 'void']);
            // All lines (picked or custom) go back to pending. Payments linked to this invoice stay:
            // they are real money received and keep counting toward the client balance.
            BillableLine::where('invoice_id', $locked->id)->update(['invoice_id' => null]);
        });
        $invoice->refresh();
    }

    /** Assign INV-YYYYMMDD-NN from the highest used sequence (voided numbers stay used); retry on unique collision. */
    private function insertNumbered(array $attrs, string $day): Invoice
    {
        $prefix = "INV-{$day}-";
        for ($try = 0; $try < 5; $try++) {
            $last = Invoice::where('number', 'like', $prefix.'%')->orderByRaw('LENGTH(number) desc')->orderByDesc('number')->value('number');
            $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
            try {
                // nested transaction = savepoint, so a collision doesn't poison the outer transaction
                return DB::transaction(fn () => Invoice::create($attrs + ['number' => $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT)]));
            } catch (UniqueConstraintViolationException $e) {
                if ($try === 4) {
                    throw $e;
                }
            }
        }
    }

    /** Returns the percent in hundredths (10.5% -> 1050). */
    private function validatedPercent(float $percent): int
    {
        $h = Money::toCents(number_format($percent, 2, '.', ''));
        if ($h < 0 || $h > 10000) {
            throw ValidationException::withMessages(['tax_percent' => 'Tax must be between 0 and 100.']);
        }

        return $h;
    }

    private function validatedCustomLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $i => $l) {
            $desc = trim((string) ($l['description'] ?? ''));
            $qty = $l['quantity'] ?? null;
            $price = $l['unit_price'] ?? null;
            if ($desc === '' || ! is_numeric($qty) || ! is_numeric($price)) {
                throw ValidationException::withMessages(["custom.$i" => 'Custom lines need a description, quantity and unit price.']);
            }
            $qtyH = Money::toCents($qty); // quantity in hundredths
            $unit = Money::toCents($price);
            if ($qtyH <= 0 || $unit < 0) {
                throw ValidationException::withMessages(["custom.$i" => 'Quantity must be above 0 and price cannot be negative.']);
            }
            $amount = intdiv($qtyH * $unit + 50, 100);
            if ($amount > self::MAX_LINE_CENTS) {
                throw ValidationException::withMessages(["custom.$i" => 'This line amount is too large.']);
            }
            $out[] = [
                'description' => $desc,
                'quantity' => sprintf('%d.%02d', intdiv($qtyH, 100), $qtyH % 100),
                'unit_price_cents' => $unit,
                'amount_cents' => $amount,
            ];
        }

        return $out;
    }
}
