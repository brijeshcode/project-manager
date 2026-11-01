# Local Currency, Exchange Rates and Payment Documents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the owner pick one local currency, keep a history of exchange rates, freeze a rate and local value on every payment (and a rate on every invoice), show local figures to the owner everywhere totals are read, attach documents to payments — and show clients nothing local.

**Architecture:** Two tiny services own the rules: `ExchangeRates` (active-rate lookup, versioned writes, history) and `LocalCurrency` (the setting, its lock, legacy back-fill). `App\Support\Money::convert()` is the only conversion code (string/integer half-up, never float). Payments get `exchange_rate`, `local_amount_cents` (BIGINT), `local_currency`; invoices get a frozen `exchange_rate` (renamed from `inr_rate`). Budgets, balances and invoice totals stay in client currency untouched. Owner views (dashboard, payments, invoice list, project/client pages) read the stored local values; the client portal, invoice PDF and email never do.

**Tech Stack:** Laravel 12, Livewire 3 (+ `WithFileUploads`), Pest, SQLite dev / MySQL prod, `local` private disk.

**Spec:** [docs/superpowers/specs/2026-11-02-local-currency-design.md](../specs/2026-11-02-local-currency-design.md)

## Global Constraints

- Work on branch `local-currency` off `main` (already created). Commit per task. **No `Co-Authored-By` trailer** (owner rule). Never stage `.gitignore` or `database/seeders/DemoDataSeeder.php` (owner's uncommitted changes) — `git add` explicit paths only.
- Money is integer minor units; conversion only via `Money::convert(int $cents, string $rate): int` (half-up, string/integer math). **Local amounts and rates need 64-bit storage**: existing `payments.amount_cents` is a 32-bit `integer` with line/invoice caps sized to fit; local values are up to ~1e5× larger → `local_amount_cents` is `bigInteger`. Rate: decimal(18,6), validated `^\d{1,6}(\.\d{1,6})?$`, `> 0`, `<= 999999.999999`; validate with `bail` **before** converting. Result must stay < `PHP_INT_MAX`.
- Local currency: 3-letter uppercase ISO code, one system-wide setting; locked once any payment or invoice carries a local value.
- `exchange_rates`: `currency` (client currency code), `rate`, `is_active`, `created_at`, `created_by`; **insert-only history**, at most one active row per currency (writes in a transaction that deactivates the previous active row; a DB unique index on `(currency)` where `is_active` is not possible portably — enforce in the service under `lockForUpdate`/transaction and test it).
- Rate prefill is always editable; a typed rate that differs from the active one applies to that record only and never becomes the active rate.
- Budgets, balances, soft balance, billable lines, invoice totals stay in the client's currency. The portal (client login), invoice PDF (`invoices/pdf`, `_body`) and invoice email show **client currency only** — no local amount, rate, local-currency name or rates table anywhere there.
- Payment documents: private `local` disk under `payment-documents/`, random hashed names, owner-only download route (`whereNumber`, `nosniff`, 404 otherwise), types pdf/png/jpg/jpeg/webp/doc/docx/xls/xlsx, max 10240 KB, original name sanitised (basename, control chars stripped, max 255) for display only.
- Authorization: everything money/rates/settings/documents requires `manage-money` (super admin); `authorize` in `mount` AND every action; ids `#[Locked]`; no authorization state in public Livewire props; escape all output.
- When **no local currency is configured** the system behaves exactly as today (no rate captured, dashboard per-currency) plus a prompt to set it — never an error.
- Run tests via bash with a redirect: `php artisan test > /tmp/out.txt 2>&1; echo $?`; PowerShell for `npm run build` (verify when new Tailwind classes are added; use literal class names only). Full suite must stay green (521 at start). Look stays per the UI redesign (shared components, `.card .btn .input .label …`, dark-aware, no `bg-white` on non-surfaces). `session()->flash('toast', …)` on redirects, `$this->dispatch('toast', type:, message:)` in Livewire actions.

## Review Focus

1. **Overflow / int32:** local amount column must be BIGINT; a max-size payment (9,999,999.99) at the max rate must not overflow or lose digits (Tasks 1, 3).
2. **Bad rate input:** `0`, negative, `1e5`, `.5`, 7+ decimals, 10-digit integers, non-numeric, empty when required → validation error, never a 500 or a stored garbage rate (Tasks 1–3, 5).
3. **Two active rates:** concurrent/repeat saves for one currency leave exactly one active row; history rows never edited (Task 1).
4. **Portal/PDF/email leakage:** a client user whose payments/invoices carry local values sees none of them, the rate, the local currency name, documents, or the rates table (Task 7 sweep + each task's tests).
5. **No local currency set / currency == local:** forms and dashboard still work; single number, rate 1, no divide-by-nothing (Tasks 3, 6).

---

### Task 1: Money::convert, settings, exchange-rate history, local-currency service

**Files:**
- Create: `database/migrations/2026_11_03_000001_create_settings_and_exchange_rates.php`, `app/Models/ExchangeRate.php`, `app/Support/Settings.php`, `app/Services/ExchangeRates.php`, `app/Services/LocalCurrency.php`
- Modify: `app/Support/Money.php`
- Test: `tests/Unit/MoneyConvertTest.php`, `tests/Feature/ExchangeRatesTest.php`, `tests/Feature/LocalCurrencyTest.php`

**Interfaces:**
- Produces: `Money::convert(int $cents, string $rate): int` (half-up; throws `InvalidArgumentException` on a rate failing `^\d{1,6}(\.\d{1,6})?$` or `<= 0`; guards overflow); `Money::isValidRate(string $rate): bool`.
- `Settings::get(string $key, ?string $default = null): ?string` / `Settings::set(string $key, string $value): void` backed by a `settings` key-value table (`key` unique, `value`, timestamps).
- `ExchangeRate` model (`currency, rate, is_active, created_by`; `creator()`), `rate` cast `decimal:6`.
- `ExchangeRates::active(string $currency): ?string` (the active rate as a decimal string or null); `ExchangeRates::setRate(string $currency, string $rate, User $by): ExchangeRate` (validates, uppercases the code, transaction: deactivate previous active row for that currency, insert new active row; returns it); `ExchangeRates::activeAll(): Collection`; `ExchangeRates::history(string $currency): Collection` (newest first, with creator).
- `LocalCurrency::code(): ?string`; `LocalCurrency::set(string $code, User $by): void` (throws `DomainException` when locked; when set for the first time or changed while unlocked, back-fills legacy payments whose client currency equals the code with rate `1`, `local_amount_cents = amount_cents`, `local_currency = code`); `LocalCurrency::isLocked(): bool` (true when any payment has `local_amount_cents` not null **or** any invoice has a non-null `exchange_rate` — the invoice column arrives in Task 5; until then guard with `Schema::hasColumn`); `LocalCurrency::rateFor(string $clientCurrency): ?string` (`'1'` when equal to local, else `ExchangeRates::active`).

- [ ] **Step 1: Write failing tests.** `MoneyConvertTest` (Unit): `convert(10000,'83')===830000`; half-up `convert(1, '0.5')===1`, `convert(1,'0.4')===0`, `convert(3,'0.5')===2`; `convert(100,'0.012345')===1`; `convert(999999999,'999999.999999')` equals the exact half-up product computed independently as a string and is < PHP_INT_MAX; invalid rates throw (`'0'`, `'-1'`, `'1e5'`, `'.5'`, `'1.1234567'`, `'1234567'`, `''`, `'abc'`); `isValidRate` mirrors that. `ExchangeRatesTest`: `setRate('usd','83.5',$u)` stores `USD`, active; second `setRate('USD','84',$u)` leaves exactly one active row (84) and the old row inactive with its original rate and timestamp unchanged (history count 2, newest first, creator set); other currencies unaffected; `active('EUR')` null; invalid rate → `ValidationException`/exception and no row written; two sequential saves never produce two active rows. `LocalCurrencyTest`: `code()` null initially; `set('inr',…)` stores `INR`; `rateFor('INR')==='1'` when local is INR; `rateFor('USD')` = active or null; a payment with `local_amount_cents` makes `isLocked()` true and `set('USD')` throw `DomainException` (no change); first `set('INR')` back-fills an existing INR-client payment with rate 1 / local = amount and leaves a USD-client payment NULL.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** migration (`settings`: id, `key` string unique, `value` text nullable, timestamps; `exchange_rates`: id, `currency` string(3) indexed, `rate` decimal(18,6), `is_active` boolean default true, `created_by` FK users nullOnDelete, timestamps (`created_at` is the history timestamp); **also add to `payments`**: `exchange_rate` decimal(18,6) null, `local_amount_cents` bigInteger null, `local_currency` string(3) null — nullable so nothing existing breaks), models, services, `Money::convert` using string math (split rate into integer/fraction, scale to micro-units, `intdiv`/`bcmath` is NOT assumed available — use integer math guarded against overflow, or `bcmul` only if the extension check in this repo passes; builder's choice, must be exact).
- [ ] **Step 4: Run** → PASS; full suite green.
- [ ] **Step 5: Commit** `feat: money conversion, settings, exchange-rate history and local-currency service`

### Task 2: Settings page

**Files:**
- Create: `app/Livewire/Settings/Index.php`, `resources/views/livewire/settings/index.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/app.blade.php` (menu array: add "Settings" item, super admin only, before Profile in the Settings group; icon `cog` exists)
- Test: `tests/Feature/SettingsPageTest.php`

**Interfaces:**
- Consumes: `LocalCurrency`, `ExchangeRates`.
- Route `settings.index` = `GET settings` (`auth`, `can:manage-money`). Component `Settings\Index`: `mount` authorizes `manage-money`. Local-currency card: select of ISO codes **(builder: a short fixed list of common codes + the currently stored one, e.g. USD EUR GBP INR AED AUD CAD SGD JPY)**; `saveLocalCurrency()` authorizes, validates `size:3|alpha`, calls `LocalCurrency::set`; when locked, the select is disabled with the explanation text "Locked — money has already been recorded in <CODE>" and the action refuses (`DomainException` → error toast, no change). Rates card: table of active rates (currency, rate, since, by) and an "Add rate" form (`currency` 3 letters, `rate`) → `addRate()` authorizes, validates (`bail`, `Money::isValidRate`, `> 0`), calls `ExchangeRates::setRate`, success toast; per-currency history (click to expand, `x-data`) listing every row (rate, active badge, created_at, creator). Currency list in the form excludes the local currency itself (rate 1 implied).

- [ ] **Step 1: Write failing tests** (assert 200 first): owner sees Settings in the menu and page; developer and client → 403 on the route and on the component; set local currency INR → stored, toast dispatched; when a payment already has a local value → select disabled text shown and `saveLocalCurrency('USD')` leaves the setting unchanged with an error toast; `addRate` USD 83.5 shows in the table with the owner's name; a second rate for USD shows two history rows (one active); invalid inputs (`0`, `-1`, `abc`, `1.1234567`, empty, 2-letter code) → validation errors and no rows; adding a rate for the local currency itself is rejected.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** component, view (shared cards, `.input/.select/.label`, `<x-ui.table-card>`, dark-aware), route, menu entry.
- [ ] **Step 4: Run** → PASS; full suite green (menu tests: the owner's order becomes … Bank accounts, Settings group: Settings, Profile — update `NavigationMenuTest` ordering assertion only).
- [ ] **Step 5: Commit** `feat: settings page with local currency and exchange-rate history`

### Task 3: Payments — rate, local amount, both numbers, legacy rows

**Files:**
- Modify: `app/Livewire/Payments/Index.php`, `resources/views/livewire/payments/index.blade.php`, `app/Models/Payment.php`
- Test: `tests/Feature/PaymentLocalCurrencyTest.php` (+ adjust `PaymentTest.php` only if markup assertions break)

**Interfaces:**
- Consumes: `LocalCurrency::code()/rateFor()`, `Money::convert/isValidRate`.
- `Payment` casts: `exchange_rate` `decimal:6`; helper `Payment::hasLocal(): bool` (`local_amount_cents !== null`).
- `Payments\Index`: new public props `string $exchange_rate = ''`; when `client_id` changes (`updatedClientId`) prefill `exchange_rate` from `LocalCurrency::rateFor($client->currency)` (empty string when none/unset). `save()`: if a local currency is configured and the client's currency differs → rate required & validated (`bail`, regex, `> 0`, `<= 999999.999999`), else rate `1` when equal; if **no local currency is configured** the three local columns stay NULL (today's behaviour). Stored: `exchange_rate`, `local_amount_cents = Money::convert(amount_cents, rate)`, `local_currency = LocalCurrency::code()`. Form shows a live preview "≈ ₹8,350.00" (computed from the typed amount × rate, hidden when equal currency). **Typing a different rate never changes the active rate.**
- List: every row shows the client-currency amount and, when `hasLocal()`, the local amount underneath (`text-xs text-gray-500`) and the rate (`@ 83.5`); same-currency rows show one number. Legacy row with `local_amount_cents` null while a local currency is configured: a "Set rate" inline control (`setRate(int $paymentId)` with `rowRate.<id>` input): authorizes, loads the payment, **refuses when it already has a local value**, validates the rate, stores the three local fields (the one narrow edit exception; log line without notes). Delete stays as is.
- When a local currency is configured the page header shows a small hint "Local currency: INR".

- [ ] **Step 1: Write failing tests** (owner mounts OK first): local INR + USD client + active rate 83.5 → `client_id` selection prefills `exchange_rate` `83.5`; save 100.00 with rate 83.5 → payment stores `exchange_rate` 83.500000, `local_amount_cents` 835000, `local_currency` INR, and the active rate is unchanged; save with a typed rate 90 → payment uses 90, active rate still 83.5; INR client with local INR → rate 1, local = amount, form shows no rate field/preview; **max-size** 9,999,999.99 at 999999.999999 stores the exact BIGINT product (assert against string math) without error; invalid rates (`0`, `-5`, `1e5`, `.5`, `1.1234567`, empty when required) → errors and no payment; no local currency configured → payment saves with NULL local fields and no rate required; list renders both numbers for a local row and one number for a same-currency row; legacy NULL row: `setRate` stores the three fields, a second call on a row that already has a local value is refused unchanged, non-owner → 403, other validation as above; deleting still works.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS; full suite green.
- [ ] **Step 5: Commit** `feat: payments store exchange rate and local amount, show both`

### Task 4: Payment documents

**Files:**
- Create: `database/migrations/2026_11_03_000002_create_payment_documents.php`, `app/Models/PaymentDocument.php`, `app/Http/Controllers/PaymentDocumentDownloadController.php`
- Modify: `app/Models/Payment.php` (`documents(): HasMany`, `deleting` hook removes files), `app/Livewire/Payments/Index.php` (+ `WithFileUploads`), `resources/views/livewire/payments/index.blade.php`, `routes/web.php`
- Test: `tests/Feature/PaymentDocumentTest.php`

**Interfaces:**
- `PaymentDocument` (`payment_id` cascade FK, `path`, `original_name`, `uploaded_by` FK users nullOnDelete, timestamps); `Payment::documents()`.
- Route `payment-documents.download` = `GET payment-documents/{document}/download` (`auth`, `can:manage-money`, `whereNumber`), controller `__invoke(int $document)`: `PaymentDocument::findOrFail`, 404 if file missing, stream from `Storage::disk('local')` via `download()` with `X-Content-Type-Options: nosniff`.
- `Payments\Index`: `array $newDocs = []` (record form, multiple files, max 5 at once) saved with `save()`; per-row `addDocument(int $paymentId)` with `rowDocs.<id>` and `removeDocument(int $documentId)` (authorize `manage-money`; document must belong to an existing payment; deletes row + file; `Log::info('payment document removed', [payment_id, name, user_id])`). Rules: `file|mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx|max:10240`; store with `store('payment-documents', 'local')`; `original_name` = sanitised basename. List row shows a document count and links (name, download); delete payment removes rows (cascade) **and files** (model event).

- [ ] **Step 1: Write failing tests** (`Storage::fake('local')`, `UploadedFile::fake()`): record a payment with two documents → two rows, files on `local` under `payment-documents/`, not on `public`; add one later from the row; owner downloads → 200 with content and `nosniff`; developer → 403, client user → 403, guest → login redirect, non-numeric id → 404; **hostile uploads**: `.zip`/`.php` renamed as pdf mime mismatch rejected (`mimes` rule), 11 MB rejected, original name `../../evil<script>.pdf` → stored under random name, `original_name` has no slashes and the rendered list contains no raw `<script>`; remove a document → row and file gone, log written, other docs untouched; removing a document id that doesn't exist → not found; deleting a payment deletes its files (assert missing on disk); a **client portal Payments page** for a client whose payment has documents shows no document name/link/count and a direct download URL as that client 403s.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS; full suite green.
- [ ] **Step 5: Commit** `feat: payment documents with owner-only private downloads`

### Task 5: Invoices — frozen rate, local total on the list, nothing local in print

**Files:**
- Create: `database/migrations/2026_11_03_000003_rename_inr_rate_to_exchange_rate.php`
- Modify: `app/Models/Invoice.php`, `app/Services/InvoiceService.php`, `app/Livewire/Invoices/Create.php`, `resources/views/livewire/invoices/create.blade.php`, `resources/views/livewire/invoices/index.blade.php`, `resources/views/invoices/_body.blade.php`, existing tests that mention `inr_rate`/"Approx. INR"
- Test: `tests/Feature/InvoiceLocalCurrencyTest.php`

**Interfaces:**
- Migration renames `invoices.inr_rate` → `exchange_rate` and widens to decimal(18,6) (values kept; use `Schema::table` + `renameColumn`; SQLite-safe).
- `InvoiceService::create(..., ?string $exchangeRate = null)` — the last parameter changes from `?float $inrRate` to a decimal string (validated by the caller); stored as `exchange_rate`. `Invoice::localTotalCents(): ?int` = `Money::convert(total_cents, exchange_rate)` when the rate is not null, else null.
- `Invoices\Create`: replace the "INR rate (optional)" field with **"Exchange rate to <local currency>"**: prefilled via `LocalCurrency::rateFor(client currency)` when a client is chosen, editable, required when a local currency is configured and differs from the client's currency, hidden when equal or when no local currency is set; validated like payments. The old INR display line is **deleted** from `invoices/_body.blade.php` (PDF and email) along with its integer-math block.
- `Invoices\Index`: shows the total in client currency and, when `localTotalCents()` is not null and the client's currency differs from the local currency, the local total underneath. Legacy invoices with the old `inr_rate` keep their rate (now `exchange_rate`) and therefore show a local total only if a local currency is configured and it is INR **(builder: gate on `LocalCurrency::code()`; never label an old rate with the wrong currency — show the local line only when `local currency` is set)**.
- Invoice show page (owner) may show the rate and local total; PDF/email never.

- [ ] **Step 1: Write failing tests:** create an invoice for a USD client with local INR and rate 84 → stored `exchange_rate` 84.000000; list shows client total and local total (`₹…`); same-currency client → one number and no rate required; rate omitted when required → validation error and no invoice; invalid rates as in Task 3; **PDF HTML and the emailed body (render `invoices/_body` and the `InvoiceMail` view, and `InvoicePdfController` output text) contain neither the local amount, the rate, nor the local currency symbol/code**; rename migration keeps old `inr_rate` values (seed a row via DB with the old column name through the migration test pattern the repo already uses, or assert column exists/values via model after `migrate:fresh` + factory — builder's choice); existing InvoiceBuilder/InvoiceService/InvoiceDelivery tests updated for the signature/label change only.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS; full suite green.
- [ ] **Step 5: Commit** `feat: invoices freeze an exchange rate; local total on the list, never in print`

### Task 6: Dashboard, chart, project and client pages in local currency

**Files:**
- Modify: `app/Support/DashboardMoney.php`, `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`, `resources/views/components/ui/bar-chart.blade.php` (single series), `app/Services/ProjectEconomics.php`, `resources/views/livewire/projects/economics.blade.php`, `resources/views/livewire/projects/show.blade.php` (small owner-only money summary), `resources/views/livewire/clients/show.blade.php`, `app/Livewire/Clients/Show.php`
- Test: extend `tests/Unit/DashboardMoneyTest.php`, `tests/Feature/DashboardTest.php`, `tests/Feature/ProjectEconomicsTest.php`, `tests/Feature/ClientPagesTest.php`; new `tests/Feature/LocalFiguresTest.php`

**Interfaces:**
- Consumes: payments' stored local columns, `Invoice::localTotalCents`, `LocalCurrency`, `ExchangeRates::active`.
- `DashboardMoney::forPeriodLocal(CarbonInterface $from, CarbonInterface $to): array` → `['currency' => 'INR', 'received' => int, 'this_month' => int, 'outstanding' => int, 'outstanding_missing_rate' => int (clients left out), 'payments_missing_rate' => int (payments in range with NULL local)]` — received/this_month sum `local_amount_cents` of payments whose `local_currency` equals the current local currency (NULL rows excluded, counted); outstanding = Σ over clients with a negative `ClientAccount::balanceCents` of `Money::convert(abs(balance), rateFor(client currency))`, clients whose currency has no active rate (and ≠ local) counted in `outstanding_missing_rate` and skipped. `DashboardMoney::revenueSeriesLocal(from, to, granularity): array` → `[['label','cents'], …]` single zero-filled series from the same stored local amounts. **When no local currency is configured the dashboard keeps today's per-currency cards/chart** and shows a one-line "Set a local currency in Settings to see one total" hint.
- Dashboard view with a local currency: Received / Outstanding / This month cards are a single number (`x-ui.stat :value`), one chart (existing `<x-ui.bar-chart>` fed the local series with the local currency code), notices "N payments have no exchange rate" (link to payments list) and "N clients without a rate" when non-zero.
- `ProjectEconomics::for()` additionally returns `invoiced_local` (Σ per invoice of `Money::convert(invoice's lines on this project, invoice.exchange_rate)`, null-rate invoices skipped and counted) and `received_local` (Σ stored `local_amount_cents` for the project's payments in the current local currency); `economics.blade` shows **client number + local number** for Invoiced and Received (profit/cost stay client currency, unchanged). `projects/show` gains an owner-only (`@can('manage-money')`) summary card "Money" with Invoiced and Received (both numbers) linking to Economics; budgets remain client currency only.
- `clients/show`: summary cards Total Billed / Total Received / Balance keep client currency as the main figure; Billed and Received add a local line (billed local = Σ invoices' frozen local totals; received local = Σ stored local amounts); the Payments tab shows the local amount and rate under each amount (same rule as the payments list) and the document count/links (owner view).
- Every local line is hidden when the client's currency equals the local currency or no local currency is configured.

- [ ] **Step 1: Write failing tests:** unit: two USD/EUR clients' payments in range with stored local amounts → `received` is the plain sum of local amounts (INR), a payment with NULL local counted in `payments_missing_rate` and excluded, payments stored under a different `local_currency` excluded, `this_month` and the series use the same rule; outstanding converts each owing client at the active rate, skips + counts a currency without a rate; series zero-fills, last day inclusive. Feature: owner dashboard with local INR shows single-number cards (assert the INR figures, `assertDontSee` a second currency line), one chart `aria-label="Revenue in INR"`, notices appear only when counts > 0; without a local currency → today's per-currency cards plus the hint; developer dashboard shows no money (unchanged); project economics page shows both numbers for received and invoiced and the owner "Money" card on the project page, hidden from developers (assert 200 first); client details shows local lines and hides them for a same-currency client; a client whose currency ≠ local with a NULL-rate payment shows no local line for it (not ₹0).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** (reuse `Money::convert`; no float math; one grouped query per aggregate).
- [ ] **Step 4: Run** → PASS; full suite green; `npm run build` if new classes were added.
- [ ] **Step 5: Commit** `feat: dashboard, project and client pages show local currency alongside client currency`

### Task 7: Portal privacy sweep + docs

**Files:**
- Test: `tests/Feature/PortalLocalCurrencyPrivacyTest.php`
- Modify: `docs/STATUS.md`

- [ ] **Step 1: Write the sweep test.** Fixture: local currency INR, USD client with a project, an invoice (frozen rate 83.5 → distinctive local total e.g. `₹83,500.00`), a payment with documents, rate 83.5, local amount, an active exchange rate (distinctive value 84.123456), a client user. Render **every client-portal page** as that client — dashboard, project view, invoices, payments, plus any balance/soft-balance output — asserting `assertOk()` first, then `assertDontSee` each of: the INR symbol `₹`, the string `INR`, the local amount, the stored rate, the active rate, the document file name, "Local currency", "exchange". Also: direct hits as the client to `settings` (403), `payment-documents/{id}/download` (403), `invoices/{id}/pdf` of **another** client (existing 403/404) and the own invoice PDF text contains no `₹`/rate/INR (render via the controller and inspect the HTML that feeds dompdf, or the `_body` partial). The test must also assert the positive control (the client sees their own USD amounts) so it is not vacuous.
- [ ] **Step 2: Run** → expect PASS if Tasks 1–6 are right; any failure is a leak to fix in the owning file.
- [ ] **Step 3: Update `docs/STATUS.md`:** Local currency done; how rates/prefill/lock work; legacy payments; owner-only; documents; deferred items (no live rate lookup, no FX gain/loss, rates not backfilled for old invoices without a rate, `restrictOnDelete` client migration still deferred).
- [ ] **Step 4:** Full suite green; **Commit** `test: portal never shows local currency; docs: local currency complete`

---

## Self-review

- **Spec coverage:** setting + lock (T1,T2); rates table with history, one active, prefill editable (T1,T2,T3,T5); payments store rate/local and show both (T3); legacy payments (T1 backfill, T3 set-rate); budgets/balances untouched (Global Constraints); invoices frozen rate, local on list, nothing in print (T5); dashboard single number + chart (T6); project page both numbers (T6); client page both numbers (T6); client portal/PDF/email never local (T5, T7); payment documents (T4); anti-goals each have a test (portal sweep T7, overflow T1/T3, rates T1, documents T4).
- **Types/names:** `Money::convert/isValidRate`, `ExchangeRates::{active,setRate,activeAll,history}`, `LocalCurrency::{code,set,isLocked,rateFor}`, `Payment::hasLocal`, `Invoice::localTotalCents`, `DashboardMoney::{forPeriodLocal,revenueSeriesLocal}` are each defined once (T1/T3/T5/T6) and reused unchanged.
- **Proportion:** seven tasks; most UI work reuses the shared components from the redesign.
- **Known ceilings (state in STATUS):** the local currency cannot change once money is recorded; old invoices with no rate show no local total; no automatic rate updates.
