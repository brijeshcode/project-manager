# Project Tracker ("Helm") — Status & Next Steps

_Last updated: 2026-10-30. Branch: `phase-1` (not yet merged to `main`). Tests: **227 passing** (Pest). Cypress specs written but not run on this machine (Electron crashes — run on CI)._

This is the living "where are we / what's next" doc. The task-by-task build plan is
[docs/superpowers/plans/2026-10-26-project-tracker-phase-1.md](superpowers/plans/2026-10-26-project-tracker-phase-1.md);
the design spec is [docs/superpowers/specs/2026-10-26-project-tracker-design.md](superpowers/specs/2026-10-26-project-tracker-design.md).

---

## Where we are

### ✅ Phase 1 backend — complete & reviewed
All 14 planned tasks built test-first, each task-reviewed + fixed, then one whole-branch
review with a fix wave. Working, tested engine:

- Users + roles + cost rates (spatie), super-admin gate bypass.
- Clients (with contact details + **encrypted** bank details).
- Projects + membership + access policy (deny-by-default, cross-client isolation).
- Modules → Tasks (draft/published, scope pricing, labels, assignees, testing add-on,
  board column, section) → Subtasks.
- Checklists with **hours-weighted progress rollup** (ProgressService).
- Time logging with **derived cost** (TimeEntry).
- **Working-day deadline estimator** + dual progress bars (EstimateService).
- Per-user/per-project permission overrides + section access.
- Scoped **client portal** (create/comment/priority/labels/approve).

### 🎨 Frontend pass — in progress (started 2026-10-30)
Direction chosen: **Modern SaaS workspace** — slate + indigo, Inter / Inter Tight,
sidebar app shell. Signature element: the **health meter** (checklist % vs time-used %,
time bar turns amber→rose when over budget).

**Done:**
- Design tokens (fonts, palette, shadows) — `tailwind.config.js`, `resources/css/app.css`.
- Sidebar + topbar **app shell** — `resources/views/layouts/app.blade.php` (role-aware nav,
  user menu, working logout, mobile drawer). Fixes the earlier blank-page bug
  (`components/layouts/app.blade.php`).
- Reusable UI kit — `resources/views/components/ui/` (card, button, badge, stat,
  empty-state, page-header, **health-meter**).
- Reshaped screens: **Dashboard** (stats + recent + quick actions), **Projects list**
  (cards + health meter), **Project page = interactive workspace** (add module / task /
  subtask / checklist + toggle, inline, authorized & project-scoped), **Clients**,
  **Portal dashboard**.
- Supporting: `logout` route, `DemoSeeder` (demo client + projects + tasks), smoke tests
  (`LayoutSmokeTest`, `ProjectWorkspaceTest`).

---

## What's next (prioritized — none of this is in the original Phase 1 plan)

### 1. Descriptions ✅ DONE (2026-10-30)
- [x] Migration adding `description` to `projects` and `modules`; run on dev DB.
- [x] `description` added to `Project` and `Module` `$fillable`.
- [x] Surfaced: project description in the (now styled) project form; module description
      in the add-module form + inline edit in the module header; task description editable
      in the task's expanded panel; project description shown on the project page.
- [x] Covered by `ProjectWorkspaceTest` ("saves descriptions for the project form, modules and tasks").

### 2. Real create/edit forms (currently stubs)
- [ ] **Project form** (`livewire/projects/form.blade.php`, 15-line stub) — name, client
      picker, description, start date, buffer %, status. Style it.
- [ ] **Task form** (`livewire/tasks/form.blade.php`, 5-line stub) — full fields
      (priority, labels, assignees, scope + price, testing add-on, client-visible,
      board column). The component logic exists and is tested.
- [ ] **Client form** — works and is styled from Phase 1; light polish to match system.
- [ ] Module edit (name/description/reorder) — reuse `Modules\Manager` or workspace.

### 3. Wire the components that exist but aren't reachable
These were built & tested in isolation but no page/route renders them:
- [ ] **Kanban board** (`Tasks\Board`) — add a route + a "Board" tab on the project page;
      style the columns/cards. Drag works via SortableJS (`board-drag.js`, now built) but
      the drag UX is unverified (Cypress deferred).
- [ ] **Task list / ideas view** (`Tasks\ListView`) — add a route + filters UI.
- [ ] **Timeline** (`Projects\Timeline`) — surface the deadline + dual bars on the project page.
- [ ] **Time logger** (`Time\Logger`) — attach to a task in the workspace/task detail.

### 4. Client portal detail
- [ ] **Portal project view** (`livewire/portal/project-view.blade.php`) — restyle; this is
      the client-facing screen (create task / comment / approve a done task). High-visibility.

### 5. Design consistency
- [ ] Auth pages (login/register/forgot) — currently Breeze default styling; restyle to match.
- [ ] Profile page — restyle to the shell.
- [ ] Topbar breadcrumbs / page titles; per-user dashboard metrics for non-admins.
- [ ] Checklist drag-reorder in the UI (method + tests exist; no drag handle yet).

### 6. Then: later phases (from the spec)
- **Phase 2 — Resources**: documents / screenshots / credentials, one typed entity,
  item-level sharing, credentials encrypted at rest, client-uploadable.
- **Phase 3 — Money**: payments, invoices (replace manual PDFs), expenses, profit/loss,
  client-visible payment standing.
- **Phase 4 — Chat**: in-app chat per project + chat-to-task.
- **Phase 5 — Multi-tenancy**: spatie multi-DB (one DB/tenant) + landlord DB + feature flags.

---

## Phase 1 deferred items / known tech-debt (carried from the build ledger)

- **Access-scope section filter unions a user's scopes globally** (not per-project) —
  a user who is frontend on project A + backend on B sees both sections. Accepted ceiling;
  pinned by a test. Fix with a per-project correlated join when it matters.
- **Flat-rate time cost** `derivedCost()` returns the flat amount **per entry**; the
  "once per project" de-dup is Phase 3's aggregation job (documented; contract test exists).
- **Time Logger** shows all users' entries for a task (no per-user scope) — confirm intended.
- `client_approval` and task `section` are raw nullable strings (no enum cast) — fine, tidy later.
- Cypress deferred on this machine (Electron illegal-instruction crash) — run specs on CI.
- `approveTask()` silently no-ops on a non-Done task (button hidden in UI).

---

## How to resume

```bash
# deps already installed. If fresh:
composer install && npm install

php artisan migrate            # includes the new description migration
php artisan db:seed            # super admin (admin@example.com / password) + roles
php artisan db:seed --class=DemoSeeder   # demo client + projects + tasks (idempotent)

npm run build                  # or: npm run dev
php artisan serve              # http://127.0.0.1:8000
```

**Logins:** super admin `admin@example.com` / `password`; client (portal) `client@example.com` / `password`.

**Tooling notes for this machine:** run `php artisan test` via bash with a file redirect
(`php artisan test > out.txt 2>&1; echo $?`) — piping through PowerShell `Select-Object`
misreports the exit code. Don't run `npx`/node in Git Bash (crashes); use PowerShell for npm.

**Uncommitted work:** the entire frontend pass above is uncommitted on `phase-1`. Commit it
before closing out so it isn't lost.
