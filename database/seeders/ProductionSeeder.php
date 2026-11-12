<?php

namespace Database\Seeders;

use App\Enums\PaymentType;
use App\Enums\RecurringFrequency;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Real clients, projects, subscriptions and billing history for production. Safe to run more than once: nothing
 * already there is duplicated or changed, and no past subscription periods are billed by the scheduler.
 * History (subscriptions, sent invoices, payments) comes from data/production-history.json when present.
 * Run: php artisan db:seed --class=ProductionSeeder
 */
class ProductionSeeder extends Seeder
{
    /** Override in tests; defaults to database/seeders/data/production-history.json. */
    public ?string $historyPath = null;

    private const CLIENTS = [
        [
            'name' => 'David Zouein', 'country' => 'Morocco', 'phone' => '+212 661-234565',
            'company' => ['company_name' => 'Zouein Prestige', 'contact_email' => 'davidzouein@hotmail.com'],
            'projects' => [
                ['name' => 'Accounts System', 'budget' => 1500],
                ['name' => 'Luigi Darbur App', 'budget' => 2000],
            ],
            // [description, monthly amount, project name or null, started (Y-m), ended (Y-m) or null]
            'subscriptions' => [], // from production-history.json
        ],
        [
            'name' => 'Mark Zouein', 'country' => 'Lebanon', 'phone' => '+961 3 737 655',
            'projects' => [
                ['name' => 'Currency Trading System', 'budget' => null],
            ],
            'subscriptions' => [
                ['Maintaining currency trading system', 80, 'Currency Trading System', '2024-01', null],
            ],
        ],
        [
            'name' => 'Nick Zouein', 'country' => 'Lebanon', 'phone' => '+961 3 715 515',
            // invoices are made out to his company
            'company' => ['company_name' => 'Global-Z Est.', 'tax_number' => '3318008-601', 'contact_email' => 'Nickzouein@hotmail.com',
                'address' => 'St. Georges St., Zouein bldg, Louwayzeh, Baabda', 'city' => 'Baabda'],
            'projects' => [
                ['name' => 'Wholesale Trading System', 'budget' => 2000],
            ],
            'subscriptions' => [
                ['Wholesale trading system maintenance', 120, 'Wholesale Trading System', '2025-09', '2026-09'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CLIENTS as $c) {
            $client = Client::firstOrCreate(['name' => $c['name']], [
                'currency' => 'USD',
                'contact_name' => $c['name'],
                'contact_phone' => $c['phone'],
                'country' => $c['country'],
                'notes' => 'Contact via WhatsApp or Skype.',
            ] + ($c['company'] ?? []));

            $projects = [];
            foreach ($c['projects'] as $p) {
                $projects[$p['name']] = Project::firstOrCreate(
                    ['client_id' => $client->id, 'name' => $p['name']],
                    ['status' => 'active', 'initial_budget' => $p['budget'] ?? 0],
                );
            }

            foreach ($c['subscriptions'] as [$desc, $amount, $projectName, $from, $to]) {
                if (RecurringCharge::where('client_id', $client->id)->where('description', $desc)->exists()) {
                    continue;
                }
                $start = $from ? Carbon::createFromFormat('!Y-m', $from) : today()->addMonthNoOverflow()->startOfMonth();
                $charge = RecurringCharge::create([
                    'client_id' => $client->id,
                    'project_id' => $projectName ? $projects[$projectName]->id : null,
                    'description' => $desc,
                    'amount_cents' => $amount * 100,
                    'frequency' => RecurringFrequency::Monthly,
                    'next_due_on' => $start,
                    'active' => false,
                ]);

                if ($to && Carbon::createFromFormat('!Y-m', $to)->endOfMonth()->isPast()) {
                    // already finished: kept for the record, never billed
                    $charge->forceFill(['client_state' => RecurringCharge::ENDED, 'client_approved_at' => $start, 'ended_at' => Carbon::createFromFormat('!Y-m', $to)->endOfMonth()])->save();
                } else {
                    // running and already agreed: billing starts from the next due date, past months are not billed
                    $charge->startApproved(false, today());
                }
            }
        }

        $path = $this->historyPath ?? database_path('seeders/data/production-history.json');
        if (is_file($path)) {
            $this->seedHistory(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    /** Subscriptions, sent invoices with their lines, and payments. Records whose client is missing are skipped. */
    private function seedHistory(array $data): void
    {
        $n = ['subscriptions' => 0, 'invoices' => 0, 'payments' => 0, 'skipped' => 0];
        $clients = Client::pluck('id', 'name');
        // the client's own project first; else one of another client's (e.g. Nick's project paid by David)
        $project = fn (int $clientId, ?string $name) => $name ? Project::where('name', $name)->orderByRaw('client_id = ? desc', [$clientId])->value('id') : null;
        $month = fn (string $ym) => Carbon::createFromFormat('!Y-m', $ym);
        // an invoice takes the rate of the client's payment in its month (closest to the issue date); none that month -> current rate
        $paidRates = collect($data['payments'] ?? [])->filter(fn ($p) => isset($p['exchange_rate']));
        $rateFor = fn (string $client, string $date) => $paidRates
            ->filter(fn ($p) => $p['client'] === $client && substr($p['paid_on'], 0, 7) === substr($date, 0, 7))
            ->sortBy(fn ($p) => abs(strtotime($p['paid_on']) - strtotime($date)))
            ->first()['exchange_rate'] ?? null;

        foreach ($data['subscriptions'] ?? [] as $s) {
            $cid = $clients[$s['client']] ?? null;
            if (! $cid || RecurringCharge::where('client_id', $cid)->where('description', $s['description'])->exists()) {
                $n['skipped']++;

                continue;
            }
            $start = $month($s['first_period']);
            $charge = RecurringCharge::create([
                'client_id' => $cid, 'project_id' => $project($cid, $s['project'] ?? null), 'description' => $s['description'],
                'amount_cents' => Money::toCents((string) $s['amount']), 'frequency' => RecurringFrequency::Monthly, 'due_day' => 1,
                'next_due_on' => $start, 'active' => false, 'pass_through' => (bool) ($s['pass_through'] ?? false),
            ]);
            if ($s['active']) {
                $charge->startApproved(false, today());
            } else {
                $end = $month($s['last_period'])->endOfMonth();
                $charge->forceFill(['client_state' => RecurringCharge::ENDED, 'client_approved_at' => $start, 'ended_at' => $end, 'next_due_on' => $end->copy()->addDay()->startOfDay()])->save();
            }
            $n['subscriptions']++;
        }

        foreach ($data['invoices'] ?? [] as $inv) {
            $cid = $clients[$inv['client']] ?? null;
            if (! $cid || Invoice::where('number', $inv['number'])->exists()) {
                $n['skipped']++;

                continue;
            }
            DB::transaction(function () use ($inv, $cid, $project, $rateFor) {
                $rate = $rateFor($inv['client'], $inv['issued_on']);
                $rate = $rate === null ? null : number_format((float) $rate, 6, '.', '');
                $lines = array_map(fn ($l) => $l + [
                    'cents' => Money::toCents((string) $l['amount']),
                    'unit_cents' => Money::toCents((string) $l['unit_price']),
                    'project_id' => $project($cid, $l['project'] ?? null),
                    'charge_id' => ($l['subscription'] ?? null) ? RecurringCharge::where('client_id', $cid)->where('description', $l['subscription'])->value('id') : null,
                ], $inv['lines']);
                $subtotal = array_sum(array_column($lines, 'cents'));
                $invoice = Invoice::create([
                    'number' => $inv['number'], 'client_id' => $cid, 'status' => 'sent', 'issued_on' => $inv['issued_on'], 'due_on' => null,
                    'currency' => 'USD', 'subtotal_cents' => $subtotal, 'tax_percent' => '0.00', 'tax_cents' => 0, 'total_cents' => $subtotal,
                    'terms' => $inv['terms'] ?? null, 'emailed_at' => $inv['issued_on'], 'exchange_rate' => $rate,
                    'show_quantity' => collect($lines)->contains(fn ($l) => (float) $l['qty'] != 1.0),
                    'lines_snapshot' => array_map(fn ($l) => ['description' => $l['description'], 'quantity' => number_format((float) $l['qty'], 2, '.', ''), 'unit_price_cents' => $l['unit_cents'], 'amount_cents' => $l['cents'], 'project_id' => $l['project_id']], $lines),
                ]);
                foreach ($lines as $l) {
                    $period = $l['period'] ?? $inv['period'];
                    // one line per subscription and period (DB unique): a second one for the same month stays unlinked
                    $link = $l['charge_id'] && ! BillableLine::where('recurring_charge_id', $l['charge_id'])->where('period', $period)->exists();
                    BillableLine::create([
                        'client_id' => $cid, 'project_id' => $l['project_id'], 'invoice_id' => $invoice->id, 'description' => $l['description'],
                        'recurring_charge_id' => $link ? $l['charge_id'] : null, 'period' => $link ? $period : null,
                        'quantity' => $l['qty'], 'unit_price_cents' => $l['unit_cents'], 'amount_cents' => $l['cents'],
                        'pass_through' => (bool) ($l['pass_through'] ?? false), 'exchange_rate' => $rate,
                    ]);
                }
            });
            $n['invoices']++;
        }

        $seen = []; // same-day transfers of the same amount are real (e.g. 408 + 408): the Nth one is new only if fewer than N exist
        foreach ($data['payments'] ?? [] as $p) {
            $cid = $clients[$p['client']] ?? null;
            $cents = Money::toCents((string) $p['amount']);
            $key = "$cid|{$p['paid_on']}|$cents";
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if (! $cid || Payment::where('client_id', $cid)->whereDate('paid_on', $p['paid_on'])->where('amount_cents', $cents)->count() >= $seen[$key]) {
                $n['skipped']++;

                continue;
            }
            Payment::create([
                'client_id' => $cid, 'amount_cents' => $cents, 'paid_on' => $p['paid_on'], 'type' => PaymentType::Maintenance,
                'method' => $p['method'] ?? null, 'notes' => $p['notes'] ?? null, 'currency' => 'USD', 'documents_visible_to_client' => false,
                // the rate and INR actually received, as printed on the transfer receipt
                'exchange_rate' => $p['exchange_rate'] ?? null,
                'local_amount_cents' => isset($p['local_amount']) ? Money::toCents((string) $p['local_amount']) : null,
                'local_currency' => $p['local_currency'] ?? null,
            ]);
            $n['payments']++;
        }

        $this->command?->info("History: {$n['subscriptions']} subscriptions, {$n['invoices']} invoices, {$n['payments']} payments created; {$n['skipped']} skipped.");
    }
}
