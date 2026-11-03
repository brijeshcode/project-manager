# Modules overhaul — plan

Core of the product. Dev phase: edit EXISTING migrations in place (no new migration files), run `php artisan migrate:fresh --seed` then `db:seed --class=DemoSeeder` (RoleSeeder first). Reuse existing patterns; no new abstractions. Cypress can't run on this machine: verify via Pest (and a real browser check where a phase says so). Do not commit unless told.

## Decisions (made with the user)
- Money columns: no "cents" in names, `decimal(14,2)` major units, local twin `*_local` (match projects: `budget_amount_local`, `hourly_rate_local`). Use `FreezesLocalAmounts` with the `'major'` kind. Tasks already use `price`/`local_price` (leave). Subtasks have no money columns (ignore).
- Module status is DERIVED from tasks via board-column *stage type*, never typed by hand. Manual overrides only: `on_hold` and `completed` (a "Mark completed" button, confirm if tasks still open; stays until "Reopen"). Store as nullable `status_override` on modules.
- Board columns are per project, in a `board_columns` table: `project_id, name, stage (not_started|active|review|done), position`. Tasks reference `board_column_id` (FK) — the `BoardColumn` enum is removed. Deleting a column requires moving its tasks to another column. First column (not_started) and last column (done) are locked against deletion (rename OK).
- Board templates (a PHP list, easy to extend): `software` = Backlog(not_started), Sprint plan(not_started), In progress(active), Blocked(active), Testing(review), Review(review), Done(done), Delivered(done); `blank` = Backlog(not_started), Done(done). Template chosen on the New project form; columns editable afterwards on the Board tab.
- Derived module status (ignore ideas/drafts/out-of-scope tasks): Pending = no tasks or all in not_started; Working = any task in active; In review = all unfinished tasks in review; Completed = all tasks in done. Progress = done tasks / tasks (hours-weighted when estimates exist). Same data drives the status and the bar.
- Board cards are TASKS (subtasks are a count on the card, "2/5 done"). Cards show a module label; module filter dropdown; optional "group by module" toggle.
- Deadline-change reason: optional for modules/tasks/subtasks (log shows from→to). Budget changes keep required reason.
- Subtask detail lives inside the task page first (separate page later if it doesn't feel right).
- Roles: admin everything; users (team) via existing policies; clients via portal.
- Clients can comment on modules AND tasks (portal). A module shows in the portal when `client_visible` (new boolean on modules, like tasks). A client sees a comment if they wrote it, or it is a reply in a thread a client started. Staff comments that aren't replies to a client thread are internal. Activity log, attachments and the module timeline are hidden from clients. Comments get `parent_id` (replies).
- Markdown: `Str::markdown` (league/commonmark already installed) with `html_input => strip`, `allow_unsafe_links => false`. Editor = Write/Preview textarea + toolbar (bold, list, link, image), paste/drag upload via Livewire `WithFileUploads`, inserting `![name](url)` / `[name](url)`. Files on the private `local` disk behind an authorised download route (copy `ResourceItemDownloadController`), table `attachments` (morph `attachable`, user_id, path, name, mime, size).

## Phase A — renames + project tabs (points 1, 2, 3, 11)
1. Edit `2026_11_01_000001_add_billing_config.php`: modules columns → `hourly_rate`, `hourly_rate_local`, `budget_amount`, `budget_amount_local` (decimal 14,2, nullable), keep `budget_hours`, `exchange_rate`, `deadline`. `module_budget_changes` shape stays; the logged `field` names and old/new values use the new names and 2-decimal strings.
2. Module model: `localAmounts()` kind `'major'`, `BUDGET_FIELDS`, `fillable`, `budgetCents()` → rename to `budgetAmount()` (decimal string; do cent math internally through `Money` at the boundary), `updateBudget()` log formatting. Update every caller (grep `hourly_rate_cents|budget_amount_cents|budgetCents|local_hourly_rate_cents|local_budget_amount_cents` in app, resources, database, tests): `Projects/Show.php`, `BillableService`, `ClientAccount`, `ProjectEconomics`, DemoSeeder, ~25 test references.
3. Project show page → tabs: Details | Modules | Resources | Board | Economics. Active tab in the URL (`#[Url] public string $tab`), default `details`. Details = existing project info/stat cards/deadline/health; Modules = the existing modules/tasks workspace; Resources = `resources.panel`; Board = existing `Tasks\Board` component embedded; Economics = existing `Projects\Economics` component embedded, tab only shown with `can:manage-money`. Keep the old routes working. Make sure the Board drag script (now imported from `resources/js/app.js`) still works inside the tab.

## Phase A2 — configurable board columns (templates)
1. Migrations (edit existing): create `board_columns`; tasks get `board_column_id` (FK), drop string `board_column`. Add `template` to projects only if cheap (otherwise just copy columns on create).
2. `app/Support/BoardTemplates.php` (or config array) with `software` and `blank`. `Project::created` copies the chosen template's columns (default `software`). Form gets a template select (create only).
3. Replace `BoardColumn` enum usages everywhere (Board, Task form, ListView, portal, seeder, Task::moveTo, tests). Board tab: add/rename/reorder/delete column UI (delete asks for a target column, locked first/last). Stage select per column.
4. Board: module label on cards, module filter, group-by-module toggle, subtask count on card.
5. **Fix and VERIFY drag-and-drop in a real browser** (drag script was never loaded before: no `@stack('scripts')`; now imported from `resources/js/app.js`). Use the webapp-testing/Playwright skill; also persist the drop (Board::taskMoved by column id) and re-init after Livewire morph.
6. `Module::derivedStatus()`, `progress()` per the rules above (service or model methods, with Pest tests per stage combination and both templates).

## Phase B — module page, activity, markdown (points 4, 5, 6, 9, 10)
1. Migrations (edit existing): modules get `status_override` (nullable), `client_visible` (bool); `module_user` assignees pivot; `labelables` morph pivot (replace `label_task`: keep Task labels working); `activities` (id, subject morph, user_id nullable, event, field nullable, old/new text nullable, reason nullable, created_at); `attachments`; `comments.parent_id` nullable.
2. Traits: `LogsActivity` (logs changes of tracked attrs on `updated`: name, description, status_override, deadline, client_visible, + explicit `logSync()` for assigned/unassigned/label added/removed), `HasComments`, `HasLabels`, `HasAttachments`. Apply to Module (Phase C adds Task, Subtask). Keep `module_budget_changes` as is and merge it into the timeline.
3. Components: `<x-ui.markdown>` renderer, `<x-ui.markdown-editor>` (Write/Preview + toolbar + uploads), attachment download route + policy.
4. Module page `projects/{project}/modules/{module}`: header (title, derived status badge with override buttons Mark completed/Reopen/On hold), markdown description, progress bar, task list, sidebar (assignees, labels, deadline, budget fields with required reason), one timeline (comments + activity + budget changes merged by time) with comment composer and replies. Full edit mode for everything. Modules tab links here.
5. Portal: modules with `client_visible` listed; clients comment and reply on modules and tasks; visibility rule above; staff replies to a client thread appear to the client; activity/attachments hidden. Tests for every visibility case.

## Phase C — tasks, subtasks, Jira-style modules tab (points 7, 8)
1. Tasks: `deadline` column; apply `LogsActivity`, comments, labels, attachments; task page `tasks/{task}` with markdown body, comments/replies, labels, assignees, timeline, subtask list. Subtasks: `description` column + same traits; detail opens inside the task page.
2. Modules tab as a Jira-style list: per module row = name, derived status badge, deadline (red when overdue), assignee avatars, labels, progress bar, task count, expand to tasks (title, assignee, labels, deadline, board column/stage).
3. Demo seeder: modules with assignees/labels/comments/activity, both board templates.
4. Final: full Pest suite, browser smoke test of tabs, board drag-drop, module page, comment + upload, portal client reply.

## Definition of done per phase
`migrate:fresh --seed` + DemoSeeder clean, full Pest suite green (report real counts), no leftover references to removed names (grep), and a short report of files changed and deviations.
