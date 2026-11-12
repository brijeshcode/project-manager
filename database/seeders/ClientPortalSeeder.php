<?php

namespace Database\Seeders;

use App\Enums\PaymentType;
use App\Enums\RecurringFrequency;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RecurringCharge;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Billing data for the portal login client@example.com (Northwind Trading): subscriptions,
 * sent invoices (paid, part paid, unpaid), a draft, and payments. Idempotent on the NW- invoice numbers.
 * Run: php artisan db:seed --class=ClientPortalSeeder
 */
class ClientPortalSeeder extends Seeder
{
    public function run(): void
    {
        if (! Client::where('name', 'Northwind Trading')->exists()) {
            $this->call(DemoSeeder::class);
        }
        $client = Client::where('name', 'Northwind Trading')->firstOrFail();
        $project = $client->projects()->orderBy('id')->first();
        $currency = $client->currency;
        $rate = LocalCurrency::rateFor($currency) ?? '1';
        $local = LocalCurrency::code();
        $bank = BankAccount::first();

        foreach ([
            ['Hosting & maintenance', 12000, RecurringFrequency::Monthly, true, null],
            ['Domain renewal', 2500, RecurringFrequency::Yearly, true, null],
            ['Priority support', 30000, RecurringFrequency::Monthly, false, $project?->id],
        ] as [$desc, $cents, $freq, $active, $projectId]) {
            RecurringCharge::firstOrCreate(['client_id' => $client->id, 'description' => $desc], [
                'project_id' => $projectId, 'amount_cents' => $cents, 'frequency' => $freq, 'active' => $active,
                'next_due_on' => $freq === RecurringFrequency::Monthly ? now()->addMonthNoOverflow()->startOfMonth() : now()->addMonths(5)->startOfMonth(),
            ]);
        }
        // a new service the client still has to approve in the portal
        RecurringCharge::firstOrCreate(['client_id' => $client->id, 'description' => 'SEO package'], [
            'amount_cents' => 15000, 'frequency' => RecurringFrequency::Monthly, 'active' => false,
            'client_state' => RecurringCharge::AWAITING, 'next_due_on' => now()->addMonthNoOverflow()->startOfMonth(),
        ]);

        if (Invoice::where('number', 'like', 'NW-%')->exists()) {
            return;
        }

        // [number, days ago issued, status, [description, qty, unit cents], paid fraction]
        $invoices = [
            ['NW-0001', 80, 'sent', ['Storefront discovery & design', 1, 350000], 1.0],
            ['NW-0002', 45, 'sent', ['Checkout development', 32, 7500], 0.5],
            ['NW-0003', 12, 'sent', ['Hosting & maintenance', 3, 12000], 0.0],
            ['NW-0004', 0, 'draft', ['Order email templates', 8, 7500], 0.0],
        ];
        foreach ($invoices as [$number, $ago, $status, [$desc, $qty, $unit], $paidFraction]) {
            $issued = now()->subDays($ago)->startOfDay();
            $subtotal = $qty * $unit;
            $tax = (int) round($subtotal * 0.10);
            $invoice = Invoice::create([
                'number' => $number, 'client_id' => $client->id, 'status' => $status, 'issued_on' => $issued,
                'due_on' => $issued->copy()->addDays(30), 'currency' => $currency,
                'subtotal_cents' => $subtotal, 'tax_percent' => '10.00', 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax,
                'bank_account_id' => $bank?->id, 'bank_snapshot' => $bank?->only(['account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email']),
                'terms' => 'net-30', 'emailed_at' => $status === 'sent' ? $issued : null, 'exchange_rate' => $rate,
                'lines_snapshot' => [['description' => $desc, 'quantity' => number_format($qty, 2, '.', ''), 'unit_price_cents' => $unit, 'amount_cents' => $subtotal, 'project_id' => $project?->id]],
            ]);
            BillableLine::create([
                'client_id' => $client->id, 'project_id' => $project?->id, 'description' => $desc, 'quantity' => $qty,
                'unit_price_cents' => $unit, 'amount_cents' => $subtotal, 'invoice_id' => $invoice->id,
            ]);

            if ($paidFraction > 0) {
                $this->pay($client, $project?->id, (int) round(($subtotal + $tax) * $paidFraction), $issued->copy()->addDays(10), PaymentType::Project, 'bank_transfer', $rate, $local);
            }
        }

        $this->pay($client, null, 20000, now()->subDays(3), PaymentType::Maintenance, 'card', $rate, $local);
    }

    private function pay(Client $client, ?int $projectId, int $cents, $on, PaymentType $type, string $method, string $rate, string $local): void
    {
        Payment::create([
            'client_id' => $client->id, 'project_id' => $projectId, 'amount_cents' => $cents, 'paid_on' => $on,
            'type' => $type, 'method' => $method, 'currency' => $client->currency, 'documents_visible_to_client' => true,
            'exchange_rate' => $rate, 'local_amount_cents' => Money::convert($cents, $rate), 'local_currency' => $local,
        ]);
    }
}
