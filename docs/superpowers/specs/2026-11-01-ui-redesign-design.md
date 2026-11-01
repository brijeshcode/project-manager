# UI redesign — design

_Decided with the owner on 2026-11-01. The owner's reference is the old invoice panel in `docs/desgin-example` (Vue). Items marked **(my call)** were not decided by the owner — confirm or change._

## Intent
The current look (slate + indigo, compact sidebar) is "not near what I expected". The owner wants the whole tracker to look and feel like the old panel they know: plain blue-and-gray admin, big readable pages, familiar sidebar. This is a restyle and menu rework; no behaviour changes.

## What "the look" is (read from the reference)
- **Shell:** fixed white sidebar, 16rem wide, over a `gray-50` page. Brand name at the top. Nav items have an icon and a label; the active item is a **blue pill** (`blue-100` bg, `blue-700` text). A small uppercase **"Settings"** group sits under the main items. User block pinned at the bottom (avatar with initials, name, email); clicking opens a menu with **Your profile, Dark mode toggle, Sign out**. On mobile the sidebar slides in over a dark overlay, with a top bar holding a menu button.
- **Page pattern:** bold `text-2xl` title, gray one-line subtitle, primary blue button on the right. Under it a white **filter card** (search, status select), then a white **table card** with shadow and rounded corners, a pagination footer, and an empty state (large gray icon, short heading, one line, call-to-action button).
- **Dashboard:** period select + Refresh button in the header; four **metric cards** (icon, label, big value, small gray/green sub-line); recent activity below.
- **Dark mode:** class-based toggle, remembered per browser, `gray-800/900` surfaces.
- **Palette:** Tailwind gray + blue (blue-600 primary buttons, green/yellow/purple icon accents on metric cards). Status chips in soft tinted pills.
- **Feedback:** toast notifications for success/error (reference uses a toast library; any small toast is fine).

## Menus (my call — the owner's list was cut off after "3. projects")
Super admin: **1 Dashboard · 2 Clients · 3 Projects · 4 Invoices · 5 Payments · 6 Recurring · 7 Bank accounts**, then a **Settings** group with **Profile**.
Staff (developer etc.): **Dashboard · Projects**, Settings → Profile.
Client: **Dashboard (my projects) · Invoices · Payments**, Settings → Profile.
Notes: the reference also had "Services" and "Reports". "Services" is what the tracker calls **Recurring charges**, so it is the Recurring item. **Reports** has no feature behind it yet, so it is not in the menu; per-project economics stays on the project page.

## Goals
- Every existing screen (auth pages, dashboard, clients, projects + project workspace + board, task form, invoices, payments, recurring, bank accounts, resources panel, portal pages, profile) uses the page pattern, palette and shell above.
- One shared set of Blade components carries the look (page header with subtitle + action, filter card, table card, empty state, metric card, status badge, form fields, buttons, toast), so restyling later is one place.
- Dark mode works on every screen and survives reload.
- Usable at phone width (sidebar becomes the slide-in drawer; tables scroll inside their card, no page-level horizontal scroll).
- List screens gain the reference's search and status filter **only where the data supports it** (clients, projects, invoices, payments); pagination footers on the same lists **(my call)**.

## Non-goals
- No rewrite in Vue or any new front-end framework: stays Laravel + Livewire + Alpine + Tailwind.
- No new features, new data, permissions or route changes beyond the menu entries above. Reports, a Services catalogue and notification centre are not built here.
- Invoice PDF and invoice email templates are untouched (they have their own layout and already match the owner's invoices).
- No Cypress work (cannot run on this machine).

## Anti-goals (failure even if it looks right)
- Any screen visible to a role that could not see it before, or a menu entry shown to a role that cannot open it.
- A single failing test; the full Pest suite must stay green. New tests only for what is new (menu per role, dark-mode toggle markup, shared components render).
- Light mode and dark mode with unreadable text/contrast on any screen.
- The health meter (checklist % vs time %) disappearing — it is the product's signature and stays, restyled to the blue/gray palette.

## Constraints
- Stack as is (Tailwind 4 via Vite, Blade components in `resources/views/components/ui/`, Livewire 3). Icons: inline SVG sets already used; a small set shared by the sidebar and metric cards **(my call: Heroicons-style outline paths, no icon package)**.
- Tailwind `dark:` variant must be class-driven (`@custom-variant dark` in the CSS), set before first paint to avoid a flash.
- Existing tests that assert on text/markup may need their selectors updated; assertions about access and data stay as they are.

## Left to the builder
Exact spacing/typography values (follow the reference), the order and wording of metric cards on the dashboard (revenue/outstanding/clients/this month for the owner; staff and client dashboards keep their current data), toast implementation, icon choice per menu item, how to split the work into tasks (suggested order: tokens + dark mode + shell → shared components → auth + dashboards → clients/projects lists → project workspace/board/forms → money screens → portal → profile), and responsive details.
