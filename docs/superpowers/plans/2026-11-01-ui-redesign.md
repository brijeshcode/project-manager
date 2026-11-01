# UI Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the whole app to the owner's old-panel look (white sidebar on gray-50, blue accent, titled pages with filter + table cards, metric-card dashboard, dark mode) and rework the menus per role, with no behaviour or permission changes.

**Architecture:** The look lives in three central places so ~30 views change with few edits: (1) `tailwind.config.js` remaps the palette (`brand` → blue, `slate` → Tailwind gray values) and turns on class-based dark mode; (2) `resources/css/app.css` component classes (`.btn .card .input .badge .nav-item …`) get `dark:` variants plus one clearly marked **dark overrides block** that remaps the raw utilities views still use (`bg-white`, `bg-slate-50`, `text-slate-900`, `border-slate-200`…) under `.dark`; (3) shared Blade components in `resources/views/components/ui/`. Then the shell, dashboards, list screens and remaining forms adopt the shared pieces.

**Tech Stack:** Laravel 12, Livewire 3 (+ `WithPagination`), Alpine, Tailwind **3.x** (`tailwind.config.js`, `@tailwindcss/forms`), Vite, Pest.

**Spec:** [docs/superpowers/specs/2026-11-01-ui-redesign-design.md](../specs/2026-11-01-ui-redesign-design.md). Reference look: `docs/desgin-example/src` (Vue; read `views/layouts/Sidebar.vue`, `views/Dashboard.vue`, `views/clients/Index.vue` for markup/classes — do not copy its code or add Vue).

## Global Constraints

- New branch `ui-redesign` off `main`. Commit per task. **No `Co-Authored-By` trailer** (owner's rule).
- Brand name stays **Helm** (AuthenticationTest asserts it). Keep `wire:navigate` links.
- **No change to routes, policies, gates, queries, or what data a role can see.** Menu entries are shown only to roles that can already open them (same conditions as today: `hasRole('client')`, `hasRole('super_admin')`, `can('manage-money')`).
- Menus — super admin: Dashboard, Clients, Projects, Invoices, Payments, Recurring (`billing.recurring`), Bank accounts (`billing.bank-accounts`); group **Settings**: Profile. Staff: Dashboard, Projects; Settings: Profile. Client: Dashboard (`portal.dashboard`, label "Dashboard"), Invoices, Payments; Settings: Profile. Main nav label is "Workspace"; group label "Settings".
- Palette: primary blue-600; surfaces white/`gray-50`; dark: `gray-800` surfaces on `gray-900`. Health meter stays (restyled, not removed).
- Invoice PDF (`resources/views/invoices/*`) and email templates (`emails/*`) are **not touched**.
- Dark mode: Tailwind `darkMode: 'class'`; choice stored in `localStorage` key `theme` (`light|dark`, default follows `prefers-color-scheme`); applied by a tiny inline script in `<head>` of both layouts **before** CSS paint; wrap storage access in try/catch.
- Verify visuals by building (`npm run build` in PowerShell) and, if Playwright is usable (see `webapp-testing` skill), screenshotting key pages in light and dark. Tests: Pest via bash with a redirect: `php artisan test > out.txt 2>&1; echo $?`. Suite must stay green (449 at start). Livewire tests that need a layout use `$this->withoutVite()` when no built manifest is present.
- Money values never appear to a role that couldn't see them before (dashboard money cards are owner-only).

## Review Focus

1. **Dark mode contrast on every screen** — any text/background pair unreadable in dark (Tasks 1, 7 sweep).
2. **Role menus** — a client or staff user must not see owner links; owner sees all seven (Task 2).
3. **Mobile** — sidebar drawer opens/closes; no page-level horizontal scroll; tables scroll inside their card (Tasks 2, 3).
4. **Filters/pagination** — search with SQL wildcard characters (`%`, `_`), empty results, page > last page (Task 5).
5. **Dashboard money with several client currencies** — never sum across currencies (Task 4).

---

### Task 1: Foundation — palette, dark mode, component classes

**Files:**
- Modify: `tailwind.config.js`, `resources/css/app.css`, `resources/js/app.js`
- Create: `resources/js/theme.js`
- Test: `tests/Feature/ThemeFoundationTest.php`

**Interfaces:**
- Produces: Alpine store `theme` (`window.Alpine.store('theme')` with `dark: bool`, `toggle()`); inline `<head>` snippet (used verbatim by Tasks 2 and 6) — define it once as the Blade partial `resources/views/layouts/_theme-script.blade.php` containing the no-flash script; CSS classes `.btn .btn-primary .btn-secondary .btn-ghost .btn-danger .card .label .input .select .textarea .field-error .badge .badge-{slate,brand,green,amber,rose} .nav-item .nav-item-active` all dark-aware; new `.table-th`, `.table-td`, `.link` (blue text link).

- [ ] **Step 1: Write failing test** `ThemeFoundationTest`: `it('layouts include the no-flash theme script')` — render `/login` (guest layout) and `/dashboard` as admin; both responses `assertSee("localStorage.getItem('theme')", false)` and `assertSee('prefers-color-scheme', false)`. `it('tailwind config enables class dark mode and blue brand')` — read `tailwind.config.js` contents; assert it contains `darkMode: 'class'` and `#2563eb` (blue-600). (Wire the partial into the layouts in Task 2; for now include it in `guest.blade.php` and `app.blade.php` heads so this test can pass.)
- [ ] **Step 2: Run** `--filter=ThemeFoundationTest` → FAIL.
- [ ] **Step 3: Implement.** `tailwind.config.js`: `darkMode: 'class'`; `brand` = Tailwind **blue** 50–900 (`#eff6ff … #1e3a8a`, 600 = `#2563eb`); `colors.slate` overridden with Tailwind **gray** values (`#f9fafb #f3f4f6 #e5e7eb #d1d5db #9ca3af #6b7280 #4b5563 #374151 #1f2937 #111827` for 50…900 with 400/500 mapped correctly) so every existing `slate-*` becomes gray; keep fonts and shadows. `app.css`: body `bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200`; give each component class its `dark:` pair (card `dark:bg-gray-800 dark:border-gray-700`, inputs `dark:bg-gray-700 dark:border-gray-600 dark:text-white`, badges tinted `dark:bg-*-900/30` etc., btn-secondary, nav-item/nav-item-active `dark:bg-blue-900/20 dark:text-blue-300`); add `.table-th/.table-td/.link`; then a block commented `/* DARK OVERRIDES — remaps raw utilities used in views; prefer component classes in new code */` mapping under `.dark` at least: `.bg-white`, `.bg-slate-50/100`, `.border-slate-100/200/300`, `.divide-slate-100/200 > * + *`, `.text-slate-400…900`, `.hover\:bg-slate-50:hover`, `.hover\:bg-slate-100:hover`, `.ring-slate-200`, `.bg-brand-50`, `.text-brand-600/700` (lighter blue). `theme.js`: Alpine store initialised from the snippet's `document.documentElement.classList.contains('dark')`, `toggle()` flips the class and persists. Import it in `app.js`; register the store on `alpine:init`. Check how Alpine is loaded (Livewire 3 ships Alpine — use `document.addEventListener('alpine:init', …)`).
- [ ] **Step 4: Run** the new test → PASS; run `npm run build` (PowerShell) → builds; then full suite → green.
- [ ] **Step 5: Commit** `feat(ui): blue/gray palette, class dark mode, dark-aware component classes`

### Task 2: App shell + menus + user menu

**Files:**
- Modify: `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php`, `tests/Feature/Auth/AuthenticationTest.php` (only if its assertions break)
- Create: `resources/views/components/nav/item.blade.php`, `resources/views/components/nav/icon.blade.php`
- Test: `tests/Feature/NavigationMenuTest.php`

**Interfaces:**
- Consumes: Task 1 classes, `_theme-script` partial, Alpine `theme` store.
- Produces: `<x-nav.item :route :pattern :icon>label</x-nav.item>`; `<x-nav.icon name="home|users|folder|document|credit-card|refresh|bank|cog|sun|moon|logout|user|menu|x"/>` (outline 24px paths, `class` passthrough) — reused by Task 3 metric cards and empty states.
- Layout: the reference shell — brand "Helm" in a 4rem header bar of the sidebar; nav group "Workspace" then group "Settings" (Profile); bottom user block (initials avatar, name, **email**); user menu = **Your profile · Dark mode (toggle switch) · Sign out**; mobile: top bar with menu button + brand, sidebar slide-in over `bg-gray-600/75` overlay, closes on link click/backdrop. Page content wrapper `p-6` (keep `max-w-7xl`). Existing `$header` slot remains optional/rendered in the mobile top bar only if provided.

- [ ] **Step 1: Write failing tests** (`NavigationMenuTest`, seed roles): super admin `GET /dashboard` → 200 and `assertSeeInOrder(['Dashboard','Clients','Projects','Invoices','Payments','Recurring','Bank accounts','Settings','Profile'])`; developer → sees Dashboard and Projects and `assertDontSee('Clients')`, `assertDontSee('Invoices')`, `assertDontSee('Bank accounts')`; client user `GET /portal` → sees Dashboard, Invoices, Payments, `assertDontSee('Clients')`, `assertDontSee('Recurring')`, `assertDontSee('Bank accounts')`. All three: `assertSee('Sign out')`, `assertSee('Dark mode')`, and the user's email. Mark each with an explicit `assertOk()` first.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the shell and components per Interfaces; menu built from one array keyed by role condition (same conditions as the current `$nav`), active state via `request()->routeIs`. Replace the three duplicated "building" icons with the right icon per item. Update `AuthenticationTest` only if it asserted "Log out".
- [ ] **Step 4: Run** `--filter=NavigationMenuTest`, then full suite → green.
- [ ] **Step 5: Commit** `feat(ui): reference-style sidebar shell with per-role menus and user menu`

### Task 3: Shared page components

**Files:**
- Modify: `resources/views/components/ui/{page-header,stat,empty-state,badge,card}.blade.php`
- Create: `resources/views/components/ui/{filter-card,table-card,toast,pagination-footer?}.blade.php` (pagination: use Laravel's `{{ $paginator->links() }}` styled via a published/tailwind view — see Step 3)
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Produces: `<x-ui.page-header title subtitle>` + `actions` slot (exists; adjust to `text-2xl font-bold text-gray-900 dark:text-white`, subtitle gray); `<x-ui.stat label value hint icon color>` — metric card: icon (slot or `icon` name via `x-nav.icon`) tinted by `color` (`green|yellow|blue|purple`), label, big value, optional sub-line; **accepts a `lines` array** for multi-currency (each line `['label'=>…, 'value'=>…]`) — when present, renders those instead of the single value (used by Task 4); `<x-ui.filter-card>` (white card, `grid` slot for inputs); `<x-ui.table-card>` (`overflow-x-auto` inside the card, optional `footer` slot for pagination, optional `empty` handling left to callers); `<x-ui.empty-state>` (restyled: large gray icon, heading, line, action); `<x-ui.toast />` — Alpine component placed once in the app layout, listens to `window` event `toast` (`detail: {type:'success|error', message}`) and shows `session('status')`/`session('toast')` flash on load; auto-hides after 4s; `aria-live="polite"`. Livewire usage: `$this->dispatch('toast', type: 'success', message: '…')`.
- Pagination views: configure `Paginator::defaultView('pagination::tailwind')` so Livewire/Laravel links render Tailwind (vendor path already in tailwind `content`); verify it reads in dark mode (override block from Task 1 covers raw utilities).

- [ ] **Step 1: Write failing tests** (`UiComponentsTest`, using `Blade::render`/`view()->component`): stat with `lines` renders every line label and value and not a duplicate big value; stat with single value renders value and hint; table-card renders slot inside an element with `overflow-x-auto`; toast renders `role="status"` and, with `session()->flash('toast','Saved')`, contains `Saved`; empty-state renders title/message/action.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** components per Interfaces; pagination default view in `AppServiceProvider::boot`; add `<x-ui.toast />` to `layouts/app.blade.php` (one line).
- [ ] **Step 4: Run** → PASS; full suite green.
- [ ] **Step 5: Commit** `feat(ui): metric card, filter/table cards, toast and restyled shared components`

### Task 4: Dashboards (owner, staff, client)

**Files:**
- Modify: `resources/views/dashboard.blade.php`, `resources/views/livewire/portal/dashboard.blade.php`
- Create: `app/Support/DashboardMoney.php`, `app/Livewire/Dashboard.php` + `resources/views/livewire/dashboard.blade.php` (turn the owner dashboard into a Livewire component for the period select + refresh; keep `Route::view('dashboard','dashboard')` rendering it via `<livewire:dashboard />` so the route stays)
- Test: `tests/Feature/DashboardTest.php`, `tests/Unit/DashboardMoneyTest.php`

**Interfaces:**
- `DashboardMoney::forPeriod(CarbonInterface $from, CarbonInterface $to): array` → `['received' => [CUR => cents], 'outstanding' => [CUR => cents], 'this_month' => [CUR => cents]]`, keyed by **client currency**, never mixed. `received` = sum of `payments.amount_cents` with `paid_on` in period; `this_month` = same for the current calendar month; `outstanding` = for each client with `ClientAccount::balanceCents < 0`, `abs(balance)`, summed per currency. Reuse `ClientAccount`; integer cents; format with `App\Support\Money::format`.
- `Dashboard` (owner only: `abort_unless(can manage-money)` for the money data; role-based view otherwise): `public int $period = 30` (allowed 7|30|90|365, validated; anything else falls back to 30); `refresh()` re-renders. Cards: **Received** (green), **Outstanding** (yellow, sub-line "N clients owe"), **Active clients** (blue, sub-line "X total"), **This month** (purple); second row keeps today's Projects / Open tasks / Hours this week. Recent projects list (5) + Quick actions retained, restyled with `.card`/`.link`.
- Staff dashboard: cards for "Your projects" (count) and "Open tasks assigned to you" + link list of their projects. Client portal dashboard: restyle to page header + cards (balance/soft-balance already there — keep data, restyle only).

- [ ] **Step 1: Write failing tests.** Unit: two clients (USD, INR) with payments → `received` has both keys with separate sums, no combined key; payments outside period excluded; client with positive balance excluded from `outstanding`. Feature: owner sees "Received", "Outstanding", "Active clients", "This month" and currency amounts (assert 200 first); `period` set to 5 → falls back to 30 (no error); developer `/dashboard` → 200, `assertDontSee('Outstanding')`, `assertDontSee('Received')`; client user portal dashboard unchanged data (`assertSee` their balance, `assertDontSee` another client's name).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS; full suite green.
- [ ] **Step 5: Commit** `feat(ui): metric-card dashboards per role with per-currency money cards`

### Task 5: List screens — clients, projects, invoices, payments

**Files:**
- Modify: `app/Livewire/Clients/Index.php`, `app/Livewire/Projects/Index.php`, `app/Livewire/Invoices/Index.php`, `app/Livewire/Payments/Index.php` and their views (`resources/views/livewire/{clients,projects,invoices,payments}/index.blade.php`); the Payments view keeps its record form above the list
- Test: `tests/Feature/ListFiltersTest.php`

**Interfaces:**
- Each component: `use WithPagination`; `#[Url] public string $search = ''` (+ `public string $status = ''` where a status exists: projects `status`, invoices `sent|void` and derived paid/unpaid if cheap else only `sent|void`); `updatingSearch()/updatingStatus()` → `resetPage()`; page size 15. Search escapes `%`/`_` (`str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $term)` with `LIKE … ESCAPE '\'`) and trims; max length 100. Clients: search by name; projects: by name or client name (keeps the **role-scoped base queries exactly as today**); invoices: by number or client name; payments: by client name or note-free fields only (never notes).
- Views: page header → `<x-ui.filter-card>` (search + status select) → `<x-ui.table-card>` with footer `{{ $rows->links() }}`; empty states distinguish "nothing yet" from "no matches" (the latter offers "Clear filters"). Projects keeps its **card grid with health meter** inside the filter-card layout (paginated 12).

- [ ] **Step 1: Write failing tests** per list: search narrows results (assert both the included and the excluded names, after a 200 mount); status filter; search `%` returns only literal-percent names (create one name with `%`, one without); 16+ rows → page 2 reachable (`set('page',2)` / `call('gotoPage', 2)`) and page-1-only item absent; page beyond last → empty-state "no matches"/last page without error; access unchanged: developer sees only member projects in search results; client-role cannot reach invoices/payments lists (existing route tests stay).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS; existing list tests may need `->assertSee` updates only for markup; full suite green.
- [ ] **Step 5: Commit** `feat(ui): searchable, filterable, paginated list screens`

### Task 6: Auth pages, profile, forms and money screens

**Files:**
- Modify: `resources/views/layouts/guest.blade.php`, `resources/views/livewire/pages/auth/*.blade.php`, `resources/views/livewire/profile/*.blade.php`, `resources/views/profile.blade.php`, `resources/views/livewire/clients/form.blade.php`, `resources/views/livewire/projects/form.blade.php`, `resources/views/livewire/payments/index.blade.php` (form part), `resources/views/livewire/invoices/{create,show}.blade.php`, `resources/views/livewire/billing/{recurring,bank-accounts}.blade.php`, `resources/views/livewire/portal/{invoices,payments}.blade.php`, `resources/views/components/{text-input,input-label,primary-button,secondary-button,danger-button,modal,dropdown,dropdown-link,nav-link,responsive-nav-link}.blade.php` as needed
- Test: existing suites + `tests/Feature/FormsStyleTest.php` (small)

**Interfaces:** Replace hard-coded `rounded-md border-slate-300 text-sm` / `text-red-600` fields with `.label`, `.input`, `.select`, `.textarea`, `.field-error`; buttons with `.btn …`; tables with `<x-ui.table-card>` + `.table-th/.table-td`; page titles with `<x-ui.page-header>`. Guest layout: centered card on gray-50 (dark-aware), brand "Helm" above, same fields. Breeze component shims (`primary-button` etc.) keep their names but render `.btn` classes so untouched pages follow.

- [ ] **Step 1: Write failing test** `FormsStyleTest`: render the clients create form, payments form and login page as the right users (assert 200 first); each contains `class="input` or `class="select` fields and **does not** contain `border-slate-300` (hard-coded field style) — pin that forms use the shared classes.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the replacements across the listed files. Do not change `wire:model`, validation, actions, or any text a test asserts on.
- [ ] **Step 4: Run** → PASS; full suite green.
- [ ] **Step 5: Commit** `feat(ui): auth, profile, forms and money screens use the shared look`

### Task 7: Project workspace, board, task form, resources panel + dark sweep + docs

**Files:**
- Modify: `resources/views/livewire/projects/{show,economics,timeline}.blade.php`, `resources/views/livewire/tasks/{board,form,list-view}.blade.php`, `resources/views/livewire/{resources/panel,checklist/panel,modules/manager,time/logger}.blade.php`, `resources/views/components/ui/health-meter.blade.php`, `docs/STATUS.md`
- Test: existing suites; add `tests/Feature/ProjectPageRendersTest.php` only for gaps found

**Interfaces:** Restyle only: cards → `.card`, links → `.link`, badges → `<x-ui.badge>`, form fields → shared classes, health meter bars blue (`bg-blue-500`) with the amber/rose time warning kept; remove leftover indigo/`brand`-specific hard-coded colours that don't map. Board columns readable in dark (`bg-gray-100 dark:bg-gray-800/60`). **Dark sweep:** build, then for each of Dashboard, Clients, Projects list, Project page, Board, Task form, Invoices list/create/show, Payments, Recurring, Bank accounts, Portal dashboard/invoices/payments/project, Profile, Login — view in dark and fix unreadable pairs (prefer a component class; add to the overrides block only if raw utilities recur). If Playwright works (`webapp-testing` skill; dev server `php artisan serve` + `npm run dev` or built assets; demo logins admin@example.com / password and client@example.com / password), save light+dark screenshots of these pages to the scratchpad directory and inspect them; if it cannot run, say so in the report and do a manual class audit instead (`grep` for `bg-white` without a dark remap, `text-slate-*` on dark surfaces).

- [ ] **Step 1:** `npm run build` (PowerShell) and run the full suite → record baseline (must be green).
- [ ] **Step 2:** Restyle the listed files; keep every `wire:` binding and every asserted string.
- [ ] **Step 3:** Dark/light sweep as described; fix findings.
- [ ] **Step 4:** Update `docs/STATUS.md` (UI redesign done, how dark mode works, where the look lives: tailwind config + `app.css` + `components/ui`, ceiling: the dark-overrides block).
- [ ] **Step 5:** Full suite → green; **Commit** `feat(ui): project workspace restyle, dark sweep, status doc`

---

## Self-review

- **Spec coverage:** shell + menus (T2), palette/dark mode (T1), page pattern + filter/table/empty/metric/toast (T3), dashboard (T4), list search/status/pagination (T5, spec says "only where data supports it"), every screen restyled (T6, T7), mobile (T2/T3 tests + T7 sweep), health meter kept (T7), PDF/email untouched (Global Constraints), no behaviour change (Global Constraints + T5 access tests).
- **Types/names:** `x-nav.icon`, `x-ui.stat` with `lines`, `x-ui.table-card/filter-card/toast`, `DashboardMoney::forPeriod`, `theme` Alpine store, `_theme-script` partial are each defined once (T1/T2/T3/T4) and reused as-is.
- **Proportion:** seven tasks; most view work is mechanical class swaps driven by central CSS.
- **Known ceiling (state in STATUS):** dark mode for raw utilities is a CSS override block, not per-view `dark:` classes.
