# Local currency, exchange rates and payment documents — design

_Decided with the owner on 2026-11-02. Items marked **(my call)** were not decided by the owner — confirm or change._

## Intent
Clients are billed and pay in their own currency, but the owner runs the business in one **local currency**. The system should let the owner pick that local currency, record the exchange rate on every payment, and show money in local currency wherever the owner looks at the business as a whole (dashboard, invoice list, payments), so mixed-currency lines disappear from the owner's view.

## Concepts

### Local currency setting
- A **Settings** page (owner only, under the existing "Settings" group in the sidebar) with a **local currency** select (ISO-4217 3-letter code). One value for the whole system.
- **(my call)** The local currency can be changed only while no payment or invoice carries a local value; once money has been recorded in it, the setting is locked with a short explanation. Mixing two local currencies in the same totals would make every dashboard number wrong.
- If a client's currency equals the local currency, the rate is 1 and the UI shows a single number (no duplicate "₹100 / ₹100").

### Exchange rates (Settings page)
- Table `exchange_rates`: `currency` (3-letter code of the *client* currency), `rate` (units of local currency per 1 unit of that currency), `is_active`, `created_at` (timestamp), `created_by` (user). **Every rate change inserts a new row**; nothing is edited in place, so it is a full history.
- At most **one active row per currency**. Saving a new rate for a currency sets the previous active row to inactive and the new row active, in one transaction.
- The Settings page lists the active rates and lets the owner add a new rate (currency + rate) and view the history per currency (who, when, rate).
- Rate precision: **(my call)** up to 6 decimal places and a sane upper bound; stored as a decimal, converted with half-up integer math (never float), consistent with `App\Support\Money`.

### Pre-fill, always editable
- Wherever a rate is needed (record payment, create invoice) the form **pre-fills the active rate** for the client's currency and the field **stays editable**, so the owner never has to go to Settings first. If no active rate exists for that currency the field is empty and required.
- **(my call)** If the owner types a rate different from the active one on a form, that rate is used for that record only; it does **not** silently become the new active rate (changing the active rate is an explicit action on the Settings page).

### Payments
- Each payment stores: client-currency amount (as today), `exchange_rate` and `local_amount_cents`, plus the `local_currency` code in force at the time. The rate and local value are **frozen** — a later rate change never alters old payments.
- The payments list and the client's Payments tab show **both numbers** (client currency and local). Client-currency amounts keep driving client balances; local amounts drive owner-side totals.
- **(my call)** Payments recorded before this feature have no rate. They get `local_amount_cents = NULL`; if their client's currency already equals the local currency they are back-filled with rate 1. NULL rows are excluded from local totals and the dashboard shows a one-line notice ("N payments have no exchange rate") linking to the payments list, where the owner can set a rate on those rows only (the one narrow exception to "payments are not edited").

### Payment documents (added 2026-11-02 at the owner's request)
- Each payment can carry **one or more uploaded documents for the record** (bank slip, remittance advice, screenshot, receipt). **(my call)** Several per payment, table `payment_documents` (`payment_id`, `path`, `original_name`, `uploaded_by`, `created_at`).
- Upload on the record-payment form and later from the payment's row (add more any time). **(my call)** The owner may also remove a single document; removal is written to the log (payment id, file name, user — no file contents). Deleting a payment deletes its documents and files.
- Files go on the **private `local` disk** (never a public URL), served only through an owner-only, permission-checked download route (same pattern as the resource-item downloads: `whereNumber`, `nosniff`, 404 for anything not found). Allowed types **(my call)**: pdf, png, jpg, jpeg, webp, doc, docx, xls, xlsx; max 10 MB each; stored under a random hashed name, original name kept sanitised for display only.
- **Owner only.** Clients never see, count or download payment documents — not in the portal Payments page, not via a direct URL (404).

### Budgets and balances stay in the client's currency
- Module/project budgets, billable lines, invoice totals, the client balance and the soft balance are **always in the client's currency**. No exchange rate ever affects budget checking or balances.

### Invoices
- **(my call)** Each invoice stores a frozen `exchange_rate` (pre-filled with the active rate, editable on the create form) and the **invoice list shows the total in local currency** (`total × invoice rate`), next to or under the client-currency total. The invoice PDF and invoice email show **only the client's currency** — the existing optional INR/local display line on the printed invoice is removed.
- The existing `invoices.inr_rate` column is **(my call)** migrated into the new `exchange_rate` column (values kept, name generalised) and no longer printed.

### Where local currency shows (owner views only)
1. **Dashboard:** every money card and the revenue chart in local currency only — one number per card, one chart. *Received* and *This month* and the chart = sum of payments' stored local amounts. *Outstanding* = sum over clients of the (positive) amount owed in client currency × the **active rate** for that currency **(my call: it is an estimate, not realised money)**; if a currency has no active rate, that client's amount is left out and the card shows "N clients without a rate".
2. **Project page (owner):** every money figure there that is money received or invoiced shows two numbers — client currency and local. Budgets show client currency only. **(my call)** Received shown in local = sum of payments' stored local amounts for that project; invoiced in local = sum of its invoices' frozen local values.
3. **Payments:** list and form show both (see above).
4. **Invoice list:** local total (see above). **Client details page** (owner): its summary cards and Payments tab show both numbers; client-currency stays primary.
- Client portal (client login): **unchanged — client currency only**. Clients never see local amounts, exchange rates, the local-currency name, or the rates table, on any portal page (dashboard, project view, invoices, payments, balance and soft balance) or in the invoice PDF/email. Tests must render every portal page for a client user whose payments and invoices carry local values and assert none of those values or rates appear in the HTML.

## Goals
- One local-currency number everywhere the owner reads totals; client-currency numbers remain wherever they drive balances/budgets.
- A frozen, auditable rate on every payment and invoice; a full history of rate changes (who/when).
- Never sum across client currencies without converting; never use float math for money.
- Owner-only: settings, rates, local figures. Same visibility rule as today's money (`manage-money` = super admin).

## Non-goals
- Live/online exchange-rate lookup, scheduled rate refresh, or any external API.
- Converting budgets, balances or the client portal to local currency.
- Document previews, OCR, or editing a document after upload (remove and re-upload instead).
- Multiple local currencies, or revaluing old payments.
- Realised/unrealised FX gain-loss reporting.
- Changes to the invoice PDF layout beyond removing the local/INR line.

## Anti-goals (failure even if it works)
- A client (portal) or non-owner seeing a local amount, a rate, or the rates table.
- A payment document reachable by anyone but the owner (portal, guessed URL, public storage), or a hostile upload (wrong mime for its extension, oversize, `../` or HTML in the filename) being stored or rendered raw.
- A rate change altering any already-recorded payment or invoice.
- Two active rates for the same currency; a rate edited in place instead of a new history row.
- Local totals silently mixing payments recorded under a different local currency, or including NULL-rate payments as zero.
- Budget checking, client balance or invoice totals changing because a rate changed.
- Float arithmetic anywhere in conversion; overflow past PHP_INT_MAX on large amounts × rate.
- The invoice PDF/email showing the local currency.

## Constraints
- Stack as is (Laravel 12, Livewire 3, Pest, SQLite dev / MySQL prod). Money stays integer minor units; conversion uses string/integer half-up math in `App\Support\Money` (add a `convert(int $cents, string $rate): int` helper there).
- Settings storage: reuse the existing app settings mechanism if one exists (e.g. config table / `billing` config); otherwise one small `settings` key-value table. Builder's choice.
- Authorization: Settings page and rate writes require `manage-money`; authorize in `mount` and every action; ids `#[Locked]`; validate currency codes (`size:3`, uppercase) and rates (`numeric`, `bail`, bounded) before converting.
- All new strings and numbers must render in light and dark.

## Left to the builder
Table/column names beyond those given; settings storage; the Settings page layout (match the reference look: page header, cards); how the local figure is displayed next to the client figure (stacked small text under the main number is fine); migration of `inr_rate` and back-fill; query strategy for dashboard local sums (single grouped query); tests (Pest) for every anti-goal above; suggested task order: schema + `Money::convert` + settings/rates service → Settings page → payments (form, list, legacy-row rate setting) → payment documents (upload, owner-only download, remove) → invoices (rate on create, list, remove print line) → dashboard + chart → project/client pages → docs.
