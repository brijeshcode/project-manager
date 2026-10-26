# Project Tracker — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the single-tenant project-tracking core — clients, projects, modules, tasks/subtasks, a list and a kanban board, checklists with progress rollup, time logging, a working-day deadline estimator, and a scoped client portal.

**Architecture:** A standard Laravel app. Eloquent models carry the domain; Livewire components drive the interactive list/board/portal; two small domain services (`EstimateService`, `ProgressService`) hold the only non-trivial math so it lives once (DRY). Authorization goes through Laravel Policies + `spatie/laravel-permission` roles, so every access check has one home. All reads/writes go through Eloquent (no raw cross-model queries) to keep the Phase 5 multi-tenancy conversion a migration, not a rewrite.

**Tech Stack:** Laravel (latest stable, 11.x+), PHP 8.2+, Livewire 3 + Alpine.js, Tailwind, SortableJS, `spatie/laravel-permission`, Pest (backend), Cypress (frontend), MySQL or Postgres.

**Spec:** `docs/superpowers/specs/2026-10-26-project-tracker-design.md`

## Global Constraints

- Laravel latest stable (11.x+), PHP 8.2+. No multi-tenancy / landlord / feature-flag code in Phase 1 — single-tenant only, but all data access via Eloquent (no raw cross-model SQL) so Phase 5 stays a migration.
- Capacity for all deadline math is fixed: **8 working hours/day, Monday–Friday, weekends skipped.** Buffer is a **flat percentage** applied after the hours→days conversion.
- Roles: `super_admin | project_manager | developer | designer | tester | client`. Super admin may do anything; money fields (cost rate, prices) are never exposed to `client`.
- Money: amounts stored as `decimal(12,2)` with a currency code on the **client** (project inherits it). No cross-currency conversion in Phase 1.
- Every task is TDD: failing test first, then minimal code. Pest for backend, Cypress for the board/portal interactions. Frequent commits.
- DRY: the rollup math (`ProgressService`), the deadline math (`EstimateService`), and authorization (Policies) are each written once and reused.

## Review Focus

- **Cross-project / cross-client access.** A user (especially a `client`) requesting a project, task, module, or subtask they have no membership in must get 403/404, never data. Test in Task 4 (ProjectPolicy) and Task 14 (client scoping).
- **Draft visibility.** A `draft` task must be invisible to everyone except its creator — including super admin and PM in list/board queries. Test in Task 6.
- **Developer editing cost/time.** A `developer` (even when assigned) must be blocked from changing `estimate_hours`, prices, or cost fields; comment must still succeed. Test in Task 6 (TaskPolicy `update` vs `comment`).
- **Working-day math at boundaries.** Hours that cross weekends must land on the next working day (a task starting Friday with 16h finishes Tuesday, not Sunday); buffer applied after conversion. Test in Task 12 (EstimateService).
- **Empty / zero-weight rollups.** Progress for a task with no checklist items returns 0% (no divide-by-zero); hours-weighted module progress with a 0-hour task does not crash. Test in Task 10.

---

### Task 1: Project setup & tooling

**Files:**
- Create: fresh Laravel app at repo root; `composer.json`, `package.json`, `tests/Pest.php`, `cypress.config.js`, `tests/Feature/SmokeTest.php`, `cypress/e2e/smoke.cy.js`

**Interfaces:**
- Produces: a booting Laravel app with Livewire, Tailwind, `spatie/laravel-permission` installed; Pest and Cypress runnable.

- [ ] **Step 1: Scaffold** — `laravel new` at root; install Livewire 3, Tailwind, Alpine, `spatie/laravel-permission` (publish its migration), Pest (`pest --init`), Cypress. Add Laravel Breeze **Livewire stack** for auth scaffolding.
- [ ] **Step 2: Write failing smoke tests** — `SmokeTest`: `it('boots the home page', fn() => get('/')->assertSuccessful())`. Cypress `smoke.cy.js`: visits `/login`, asserts the page loads.
- [ ] **Step 3: Run both, verify fail** — `php artisan test` (fails: route/view missing) and `npx cypress run` (fails: server/page).
- [ ] **Step 4: Make them pass** — default welcome/login routes; configure Cypress `baseUrl`.
- [ ] **Step 5: Run both, verify pass.**
- [ ] **Step 6: Commit** — `chore: scaffold Laravel + Livewire + Pest + Cypress`.

---

### Task 2: Users, roles & cost rate

**Files:**
- Modify: `app/Models/User.php`, users migration (add `client_id` nullable FK, `cost_rate_type` enum, `cost_rate_amount` decimal(12,2) nullable)
- Create: `database/seeders/RoleSeeder.php`, `tests/Feature/UserRoleTest.php`

**Interfaces:**
- Consumes: spatie permission tables (Task 1).
- Produces: `User` with `HasRoles`; roles seeded (`super_admin`, `project_manager`, `developer`, `designer`, `tester`, `client`); `User->cost_rate_type: 'flat'|'hourly'`, `User->cost_rate_amount: ?float`; `User->client_id: ?int`.

- [ ] **Step 1: Failing test** — `UserRoleTest`: a user given `developer` has role `developer` and lacks `super_admin`; `super_admin` passes a `->can('*')`-style gate; cost rate fields persist and cast to float/enum.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — migration columns + casts; `Gate::before` granting `super_admin` everything; `RoleSeeder` creating the six roles.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: users with roles and cost rate`.

---

### Task 3: Client

**Files:**
- Create: `app/Models/Client.php`, clients migration (`name`, `currency` char(3), timestamps), `app/Livewire/Clients/{Index,Form}.php`, `tests/Feature/ClientTest.php`, `cypress/e2e/client.cy.js`
- Modify: `app/Models/User.php` (add `client()` belongsTo)

**Interfaces:**
- Produces: `Client{ id, name, currency }`, `Client->users()` hasMany (users with `client_id`), `Client->projects()` hasMany.

- [ ] **Step 1: Failing test** — `ClientTest`: super admin creates a client with a currency; a client can have multiple client-users; `currency` required and 3 chars.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — model, migration, Livewire `Index`/`Form` CRUD, validation (`currency` `required|size:3`).
- [ ] **Step 4: Run backend + a Cypress create-client flow, verify pass.**
- [ ] **Step 5: Commit** — `feat: client CRUD with currency and client-users`.

---

### Task 4: Project (+ ProjectPolicy, membership)

**Files:**
- Create: `app/Models/Project.php`, `app/Models/ProjectMember.php`, projects migration (`client_id`, `name`, `buffer_percent` decimal(5,2) default 0, `start_date` date, `status`), project_members migration (`project_id`, `user_id`, `role_on_project`, `access_scope` enum `all|frontend|backend` default `all`, `active` bool, `removed_at` nullable), `app/Policies/ProjectPolicy.php`, `app/Livewire/Projects/{Index,Form,Show}.php`, `tests/Feature/ProjectTest.php`, `tests/Feature/ProjectPolicyTest.php`

**Interfaces:**
- Consumes: `Client` (Task 3), `User` (Task 2).
- Produces: `Project{ id, client_id, name, buffer_percent, start_date, status }`; `Project->members()`, `Project->currency()` (via client); `ProjectPolicy::view/update(User,Project): bool` — true for super_admin, PM/members of that project; false otherwise.

- [ ] **Step 1: Failing tests** — `ProjectTest`: create project under client; inherits client currency. `ProjectPolicyTest`: a member sees the project; a non-member (and a client of a *different* client) gets denied (**Review Focus: cross-project access**).
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — models, migrations, `ProjectPolicy`, register in `AuthServiceProvider`, Livewire CRUD gated by the policy.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: projects with membership and access policy`.

---

### Task 5: Module

**Files:**
- Create: `app/Models/Module.php`, modules migration (`project_id`, `name`, `position` int), `app/Livewire/Modules/Manager.php`, `tests/Feature/ModuleTest.php`

**Interfaces:**
- Consumes: `Project` (Task 4).
- Produces: `Module{ id, project_id, name, position }`, `Module->tasks()` hasMany, `Module->reorder(array $orderedIds): void`.

- [ ] **Step 1: Failing test** — `ModuleTest`: create modules under a project; new module gets next `position`; `reorder()` persists a new order.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — model, migration, `reorder`, Livewire manager gated by `ProjectPolicy::update`.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: ordered modules under a project`.

---

### Task 6: Task model, policy & CRUD

**Files:**
- Create: `app/Models/Task.php`, `app/Models/Label.php`, tasks migration, task_user pivot, labels + label_task migrations, `app/Policies/TaskPolicy.php`, `app/Enums/TaskStatus.php` (`draft|published`), `app/Enums/TaskScope.php` (`in|out`), `app/Livewire/Tasks/Form.php`, `tests/Feature/TaskTest.php`, `tests/Feature/TaskPolicyTest.php`
- Modify: `app/Models/Module.php` (tasks relation)

**Interfaces:**
- Consumes: `Module` (Task 5), `User` (Task 2).
- Produces: `Task{ id, module_id, creator_id, title, description, status: TaskStatus, is_idea: bool, priority: int, estimate_hours: float, scope: TaskScope, price: ?float, client_visible: bool, testing_enabled: bool, testing_add_type: ?'percent'|'fixed', testing_add_value: ?float, board_column: string, position: int }`; `Task->assignees()` belongsToMany, `Task->labels()` belongsToMany, `Task->effectiveHours(): float` (estimate + testing add-on when enabled). `TaskPolicy::view` (hides `draft` from non-creators), `::update` (super_admin/PM, or an assignee — but assignees with role `developer` may not change cost/time fields), `::comment` (any assignee).

- [ ] **Step 1: Failing tests** — `TaskTest`: `effectiveHours()` adds testing % or fixed when enabled, equals estimate when off; idea flag; price only meaningful when `scope=out`. `TaskPolicyTest`: **draft task not visible to a non-creator incl. super_admin** (Review Focus); a `developer` assignee is denied updating `estimate_hours`/`price` but allowed `comment` (Review Focus).
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — model (casts, enums, relations, `effectiveHours`), migrations, `TaskPolicy` (a local scope `visibleTo(User)` that excludes others' drafts — reused by list/board), Livewire `Form`. Field-level block: `update` authorizes the write but a dedicated `updateCostTime` ability is denied for `developer`.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: tasks with draft/published, scope pricing, labels, assignees, policy`.

---

### Task 7: Subtask

**Files:**
- Create: `app/Models/Subtask.php`, subtasks migration (`task_id`, `title`, `estimate_hours` decimal, `position`), subtask_user pivot, `tests/Feature/SubtaskTest.php`
- Modify: `app/Models/Task.php` (subtasks relation, roll `effectiveHours` to include subtasks)

**Interfaces:**
- Consumes: `Task` (Task 6).
- Produces: `Subtask{ id, task_id, title, estimate_hours, position }`, `Subtask->assignees()`; `Task->totalEstimateHours(): float` = own effective hours + sum of subtasks' hours.

- [ ] **Step 1: Failing test** — `SubtaskTest`: subtasks created under a task with their own hours; `Task->totalEstimateHours()` sums them; assignees attach.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — model, migration, relations, `totalEstimateHours`.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: subtasks with hours and assignees`.

---

### Task 8: Task list view

**Files:**
- Create: `app/Livewire/Tasks/ListView.php`, `tests/Feature/TaskListTest.php`

**Interfaces:**
- Consumes: `Task::visibleTo()` scope (Task 6).
- Produces: a Livewire list filtered by `stage (idea|task)`, `status`, `client_visible`, `label`, `assignee`, `module` — all filters over the one task query, respecting `visibleTo(auth user)`.

- [ ] **Step 1: Failing test** — `TaskListTest`: filtering by `is_idea` returns only ideas; another user's `draft` never appears (reuses Task 6 scope).
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — Livewire component with query-string filters on `visibleTo()`.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: filterable task/idea list`.

---

### Task 9: Board view (kanban)

**Files:**
- Create: `app/Livewire/Tasks/Board.php`, `resources/js/board-drag.js` (SortableJS wiring), `tests/Feature/BoardTest.php`, `cypress/e2e/board.cy.js`
- Modify: `app/Models/Task.php` (`moveTo(string $column, int $position)`)

**Interfaces:**
- Consumes: `Task` (Task 6).
- Produces: fixed columns `Backlog | In Progress | Testing | Done`; `Task->moveTo($column,$position)` persists column + order; Livewire `Board` renders columns and handles a `taskMoved` event from SortableJS.

- [ ] **Step 1: Failing tests** — `BoardTest`: `moveTo()` updates column and position; drafts excluded. `board.cy.js`: drag a card between columns, assert it persists after reload.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — `moveTo`, Livewire `Board`, SortableJS emitting `taskMoved` → Livewire updates.
- [ ] **Step 4: Run backend + Cypress, verify pass.**
- [ ] **Step 5: Commit** — `feat: kanban board with drag-and-drop`.

---

### Task 10: Checklists & progress rollup

**Files:**
- Create: `app/Models/ChecklistItem.php` (polymorphic `checklistable`), checklist_items migration (`checklistable_type`, `checklistable_id`, `title`, `is_done` bool, `position`), `app/Services/ProgressService.php`, `app/Livewire/Checklist/Panel.php`, `tests/Unit/ProgressServiceTest.php`, `tests/Feature/ChecklistTest.php`
- Modify: `Task.php`, `Subtask.php` (morphMany `checklistItems`)

**Interfaces:**
- Consumes: `Task` (6), `Subtask` (7).
- Produces: `ProgressService::forTask(Task): float` (0–100), `::forModule(Module): float` (**hours-weighted** across tasks), `::forProject(Project): float`; checklist items reorderable (`position`).

- [ ] **Step 1: Failing tests** — `ProgressServiceTest`: task with 2 of 4 items done = 50%; **task with no items = 0%, no error** (Review Focus); module progress is hours-weighted (a 40h task at 100% + a 10h task at 0% ≈ 80%); **a 0-hour task does not crash the weighting** (Review Focus). `ChecklistTest`: items reorder and persist.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — model, migration, `ProgressService` (guard empty/zero-weight), Livewire panel with reorder.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: checklists with hours-weighted progress rollup`.

---

### Task 11: Time logging

**Files:**
- Create: `app/Models/TimeEntry.php` (polymorphic `timeable` → task/subtask), time_entries migration (`timeable_type/id`, `user_id`, `hours` decimal, `started_at`, `completed_at`, `worked_on` date), `app/Livewire/Time/Logger.php`, `tests/Feature/TimeEntryTest.php`
- Modify: `Task.php`, `Subtask.php` (morphMany `timeEntries`), `User.php` (`costFor(float $hours): float`)

**Interfaces:**
- Consumes: `User.cost_rate_*` (Task 2).
- Produces: `TimeEntry{ hours, started_at, completed_at, worked_on, user_id }`; `TimeEntry->derivedCost(): float` (hourly: `hours * rate`; flat: the user's flat amount, counted once per project); `User->costFor($hours)`; query helpers `loggedBetween($from,$to)` for today/week/month totals.

- [ ] **Step 1: Failing tests** — `TimeEntryTest`: hourly entry cost = hours × rate; flat-rate user cost is the fixed amount; `loggedBetween` sums a date range; a `developer` can log time but still cannot edit `estimate_hours` (reuses Task 6 policy).
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — model, migration, `derivedCost`, `costFor`, `loggedBetween`, Livewire logger.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: time logging with derived cost and date-range totals`.

---

### Task 12: Deadline estimator, two progress bars, estimate-vs-actual

**Files:**
- Create: `app/Services/EstimateService.php`, `tests/Unit/EstimateServiceTest.php`, `app/Livewire/Projects/Timeline.php`
- Modify: `Project.php` (`deadline(): CarbonInterface`), `Module.php` (`timelineHours()`)

**Interfaces:**
- Consumes: `Task->totalEstimateHours` (7), `buffer_percent` (4), `ProgressService` (10), `TimeEntry` (11).
- Produces: `EstimateService::workingDaysFrom(CarbonInterface $start, float $hours): CarbonInterface` (8h/day, Mon–Fri, skip weekends); `::projectDeadline(Project): CarbonInterface` (sum module hours → working days → +buffer%); `Project->timeBar()` = logged ÷ estimated hours; `Project->estimateVsActual()` = estimated vs logged hours.

- [ ] **Step 1: Failing tests** — `EstimateServiceTest`: 8h from a Monday 09:00 start finishes same day; **16h from a Friday lands Tuesday, skipping the weekend** (Review Focus); buffer applied after conversion (e.g. +20% on 5 days → 6 days). Timeline: time bar = logged÷estimate; estimate-vs-actual reports both numbers.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — `EstimateService` (iterate working days, carry remainder hours; testing add-on already folded into task hours via Task 6); `Project->deadline`, `timeBar`, `estimateVsActual`; Livewire `Timeline` showing both progress bars.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: working-day deadline estimator, dual progress bars, estimate vs actual`.

---

### Task 13: Per-user/per-project permission overrides & section access

**Files:**
- Create: `app/Models/PermissionOverride.php`, permission_overrides migration (`user_id`, `project_id`, `ability`, `allowed` bool), `tests/Feature/PermissionOverrideTest.php`
- Modify: `ProjectPolicy.php`, `TaskPolicy.php` (consult overrides + `ProjectMember.access_scope` before role default)

**Interfaces:**
- Consumes: `ProjectMember.access_scope` (Task 4), policies (4, 6).
- Produces: a central `resolvesAbility(User,Project,string $ability): bool` that checks super_admin → explicit override → role default; `access_scope` (`frontend|backend|all`) filters which tasks (by a `section` label/field) a member sees.

- [ ] **Step 1: Failing tests** — a developer granted an extra ability via override gains it; one revoked loses it; a member with `access_scope=frontend` sees only frontend-sectioned tasks (and super_admin sees all).
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — model, migration, fold `resolvesAbility` into the two policies (written once, reused).
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat: per-user/per-project permission overrides and section access`.

---

### Task 14: Client portal

**Files:**
- Create: `app/Livewire/Portal/{Dashboard,ProjectView}.php`, `app/Policies/filters` reuse, `tests/Feature/ClientPortalTest.php`, `cypress/e2e/portal.cy.js`
- Modify: routes (`/portal/*` group gated to role `client`)

**Interfaces:**
- Consumes: `ProjectPolicy`/`TaskPolicy` (4, 6, 13), `Client` (3).
- Produces: a client sees only their own client's projects and only `client_visible` published tasks; a client can **create a task** (lands `client_visible`, `scope=in`, published-pending per super admin flow), **comment**, **change priority/labels**, and **approve a completed task** (`client_approval = approved`). A client can never see cost/price internals or another client's data.

- [ ] **Step 1: Failing tests** — `ClientPortalTest`: client A cannot load client B's project (**Review Focus: cross-client access**, 403/404); a client sees only `client_visible` tasks, not internal ones; client create/comment/priority/label/approve all succeed; client cannot read `price`/cost fields. `portal.cy.js`: client logs in, sees their board, approves a done task.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — portal routes gated to `client`, Livewire components reusing the existing policies and `client_visible` scope, a `client_approval` column on tasks.
- [ ] **Step 4: Run backend + Cypress, verify pass.**
- [ ] **Step 5: Commit** — `feat: scoped client portal with create/comment/approve`.

---

## Notes for the executor

- Comments on tasks (author incl. client) are a small `Comment` morph model; fold it into Task 6 or Task 14 wherever first needed rather than a separate task.
- `ponytail:` the working-day calendar counts only weekends, not public holidays — add a `holidays` table if real deadlines drift (design §7).
- `ponytail:` board columns are fixed in Phase 1; per-project columns are a later ask.
