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
    // Bounds keep Money::convert (split math, any valid rate) far inside PHP_INT_MAX: 8e10 max total.
    public const MAX_LINE_CENTS = 2_000_000_000;
    public const MAX_SUBTOTAL_CENTS = 40_000_000_000;

    /**
     * @param  array<int>  $lineIds  pending lines of this client
     * @param  array<int, array{description:string, quantity:mixed, unit_price:mixed}>  $customLines
     */
    public function create(Client $client, array $lineIds, array $customLines = [], float $taxPercent = 0.0, ?int $bankAccountId = null, ?string $exchangeRate = null, ?string $currency = null, bool $draft = false): Invoice
    {
        if ($exchangeRate !== null && ! Money::isValidRate($exchangeRate)) {
            throw ValidationException::withMessages(['exchangeRate' => 'Enter a positive rate with up to 6 decimals.']);
        }
        $cur = strtoupper($currency ?? $client->currency);
        // every invoice carries a rate: 1 for the local currency, else the given one, else the rate table (may be none)
        $rate = $cur === LocalCurrency::code() ? '1' : ($exchangeRate ?? LocalCurrency::rateFor($cur));
        $conv = $this->lineConverter($cur, $rate);
        $lineIds = array_values(array_unique($lineIds));
        $pctHundredths = $this->validatedPercent($taxPercent);
        $custom = $this->validatedCustomLines($customLines);

        if (! $lineIds && ! $custom) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line.']);
        }

        $invoice = DB::transaction(function () use ($client, $lineIds, $custom, $pctHundredths, $bankAccountId, $cur, $rate, $conv, $draft) {
            $picked = BillableLine::pending()->where('client_id', $client->id)->whereIn('id', $lineIds)->lockForUpdate()->get();
            if ($picked->count() !== count($lineIds)) {
                throw ValidationException::withMessages(['lines' => 'Some lines are not pending lines of this client.']);
            }

            if ($picked->max('amount_cents') > self::MAX_LINE_CENTS) {
                throw ValidationException::withMessages(['lines' => 'A line amount is too large to invoice.']);
            }
            $subtotal = (int) $picked->sum(fn ($l) => $conv((int) $l->amount_cents, $l->lineCurrency())) + array_sum(array_column($custom, 'amount_cents'));
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
                'status' => $draft ? 'draft' : 'sent', // draft = not sent: no effect on the client balance
                'issued_on' => $issued,
                'due_on' => $terms && preg_match('/^net-(\d{1,3})$/i', trim($terms), $m) ? $issued->copy()->addDays((int) $m[1]) : null,
                'currency' => $cur,
                'subtotal_cents' => $subtotal,
                'tax_percent' => sprintf('%d.%02d', intdiv($pctHundredths, 100), $pctHundredths % 100),
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'bank_account_id' => $bank?->id,
                'bank_snapshot' => $bank ? $bank->only(['account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email']) : null,
                'exchange_rate' => $rate,
                'local_total_cents' => $rate === null ? null : Money::convert($subtotal + $tax, $rate),
                'terms' => $terms,
            ], $issued->format('Ymd'));

            $claimed = BillableLine::whereIn('id', $picked->pluck('id'))->whereNull('invoice_id')->update(['invoice_id' => $invoice->id]);
            if ($claimed !== $picked->count()) {
                throw ValidationException::withMessages(['lines' => 'Some lines were just invoiced elsewhere. Reload and retry.']);
            }
            $created = collect($custom)->map(fn ($line) => BillableLine::create($line + ['client_id' => $client->id, 'invoice_id' => $invoice->id]));
            // frozen copy so a voided invoice (whose lines return to pending) and later line edits never change what it shows
            $invoice->update(['lines_snapshot' => $picked->map(fn ($l) => [
                'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price_cents' => $conv((int) $l->unit_price_cents, $l->lineCurrency()),
                'amount_cents' => $conv((int) $l->amount_cents, $l->lineCurrency()), 'project_id' => $l->project_id,
            ])->concat($created->map(fn ($l) => [
                'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price_cents' => (int) $l->unit_price_cents,
                'amount_cents' => (int) $l->amount_cents, 'project_id' => $l->project_id,
            ]))->values()->all()]);

            return $invoice;
        });

        // after commit: a mail problem must never fail or roll back invoice creation; drafts are never emailed
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

    /** Not sent -> sent: the invoice now counts against the client's balance, and is emailed. */
    public function markSent(Invoice $invoice): bool
    {
        if (Invoice::whereKey($invoice->id)->where('status', 'draft')->update(['status' => 'sent']) !== 1) {
            return false;
        }
        $invoice->refresh();
        $this->send($invoice);

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
        return $invoice->status === 'sent' && filled($invoice->client?->contact_email);
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

    /**
     * Full edit of a sent invoice (number and client stay). Own lines not in $lineIds go back to pending, ticked
     * pending lines of this client are claimed, $lineEdits rewrites manual (non-task) lines in place, $customLines adds new ones.
     *
     * @param  array<int>  $lineIds  own lines to keep + pending lines of this client to add
     * @param  array<int, array{description:string, quantity:mixed, unit_price:mixed}>  $lineEdits  keyed by own line id
     * @param  array{tax_percent:mixed, issued_on:string, due_on:?string, terms:?string, bank_account_id:?int, exchange_rate:?string}  $h
     */
    public function update(Invoice $invoice, array $lineIds, array $customLines, array $h, array $lineEdits = []): Invoice
    {
        $rate = $h['exchange_rate'] ?? null;
        if ($rate !== null && ! Money::isValidRate($rate)) {
            throw ValidationException::withMessages(['exchangeRate' => 'Enter a positive rate with up to 6 decimals.']);
        }
        if (strtoupper($invoice->currency) === LocalCurrency::code()) {
            $rate = '1';
        }
        $conv = $this->lineConverter($invoice->currency, $rate);
        $lineIds = array_values(array_unique($lineIds));
        $pct = $this->validatedPercent((float) $h['tax_percent']);
        $custom = $this->validatedCustomLines($customLines);
        $edits = $this->validatedCustomLines($lineEdits);
        $editKeys = array_keys($lineEdits);
        if (! $lineIds && ! $custom) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line.']);
        }

        DB::transaction(function () use ($invoice, $lineIds, $custom, $pct, $h, $rate, $edits, $editKeys, $conv) {
            $locked = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status === 'void') {
                throw ValidationException::withMessages(['lines' => 'A cancelled invoice cannot be edited.']);
            }
            $own = BillableLine::where('invoice_id', $locked->id)->lockForUpdate()->get();
            $keep = $own->whereIn('id', $lineIds);
            // manual lines (no task source) can be rewritten; task-sourced amounts always come from the task
            foreach (array_combine($editKeys, $edits) as $id => $e) {
                if ($l = $keep->firstWhere('id', $id)) {
                    if ($l->source_type === null) {
                        $l->fill($e);
                    }
                }
            }
            $addIds = array_values(array_diff($lineIds, $keep->pluck('id')->all()));
            $added = BillableLine::pending()->where('client_id', $locked->client_id)->whereIn('id', $addIds)->lockForUpdate()->get();
            if ($added->count() !== count($addIds)) {
                throw ValidationException::withMessages(['lines' => 'Some lines are not pending lines of this client.']);
            }
            $picked = $keep->concat($added);
            if ($picked->max('amount_cents') > self::MAX_LINE_CENTS) {
                throw ValidationException::withMessages(['lines' => 'A line amount is too large to invoice.']);
            }
            $subtotal = (int) $picked->sum(fn ($l) => $conv((int) $l->amount_cents, $l->lineCurrency())) + array_sum(array_column($custom, 'amount_cents'));
            if ($subtotal > self::MAX_SUBTOTAL_CENTS) {
                throw ValidationException::withMessages(['lines' => 'The invoice subtotal is too large.']);
            }
            $tax = intdiv($subtotal * $pct + 5000, 10000);

            BillableLine::whereIn('id', $own->pluck('id')->diff($keep->pluck('id')))->update(['invoice_id' => null]);
            $keep->each->save();
            BillableLine::whereIn('id', $added->pluck('id'))->update(['invoice_id' => $locked->id]);
            $created = collect($custom)->map(fn ($line) => BillableLine::create($line + ['client_id' => $locked->client_id, 'invoice_id' => $locked->id]));

            $bankId = $h['bank_account_id'] ?? null;
            $bank = $bankId ? BankAccount::findOrFail($bankId) : null;
            $locked->update([
                'issued_on' => $h['issued_on'], 'due_on' => $h['due_on'] ?? null, 'terms' => $h['terms'] ?? null,
                'subtotal_cents' => $subtotal, 'tax_percent' => sprintf('%d.%02d', intdiv($pct, 100), $pct % 100),
                'tax_cents' => $tax, 'total_cents' => $subtotal + $tax, 'exchange_rate' => $rate,
                'local_total_cents' => $rate === null ? null : Money::convert($subtotal + $tax, $rate),
                'bank_account_id' => $bank?->id,
                // keep the frozen snapshot unless the account itself changed
                'bank_snapshot' => $bank?->id === $locked->bank_account_id ? $locked->bank_snapshot : ($bank ? $bank->only(['account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email']) : null),
                'lines_snapshot' => $picked->map(fn ($l) => [
                    'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price_cents' => $conv((int) $l->unit_price_cents, $l->lineCurrency()),
                    'amount_cents' => $conv((int) $l->amount_cents, $l->lineCurrency()), 'project_id' => $l->project_id,
                ])->concat($created->map(fn ($l) => [
                    'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price_cents' => (int) $l->unit_price_cents,
                    'amount_cents' => (int) $l->amount_cents, 'project_id' => $l->project_id,
                ]))->values()->all(),
            ]);
        });
        $invoice->refresh();
        Log::info('invoice edited', ['id' => $invoice->id, 'number' => $invoice->number, 'total_cents' => $invoice->total_cents, 'user_id' => auth()->id()]);

        return $invoice;
    }

    /** Hard delete: lines return to pending; the client balance follows automatically. */
    public function delete(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $locked = Invoice::lockForUpdate()->findOrFail($invoice->id);
            BillableLine::where('invoice_id', $locked->id)->update(['invoice_id' => null]);
            $locked->delete();
        });
        Log::info('invoice deleted', ['id' => $invoice->id, 'number' => $invoice->number, 'client_id' => $invoice->client_id, 'total_cents' => $invoice->total_cents, 'user_id' => auth()->id()]);
    }

    /**
     * Each line is priced in its own currency (its project's, else the client's). On an invoice in another currency the
     * amount is converted through the local currency (rate table, or 1 when a currency has none). Same currency = unchanged.
     */
    private function lineConverter(string $invoiceCurrency, ?string $invoiceRate): \Closure
    {
        $to = $invoiceRate ?? '1';

        return function (int $cents, ?string $lineCurrency) use ($invoiceCurrency, $to) {
            if ($lineCurrency === null || strtoupper($lineCurrency) === strtoupper($invoiceCurrency)) {
                return $cents;
            }

            return Money::unconvert(Money::convert($cents, LocalCurrency::rateOrOne($lineCurrency)), $to);
        };
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
