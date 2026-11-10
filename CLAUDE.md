# HRMD Leave Tally Sheet System

Read `docs/PROJECT_CONTEXT.md` before any work. It is the source of truth for this project's scope, business rules, data model, tech stack, phases and acceptance criteria.

## Key rules (summary — details in the doc)

- **Phase 1 scope only:** employees, leave encoding, yearly tally, PDF/Excel export, activity log. No leave credit computation yet.
- **Stack:** Laravel 12 + PostgreSQL backend; React + TypeScript (strict) + Tailwind via Inertia (Laravel React starter kit, shadcn/ui).
- **Architecture:** thin controllers, Form Requests for validation, Policies for authorization, Services for business logic, Resources/DTOs for data sent to React, PHP Enums for fixed values.
- **Tally text and totals** are built only in `LeaveTallyService`, so the page, PDF and Excel always match.
- **One `leave_records` row per leave day;** date ranges skip weekends and holidays; AM/PM = 0.5 day; block double-encoding.
- **Performance:** no N+1, date-range queries (never `YEAR(leave_date)`), always paginate, chunk exports, queue large exports.
- **Privacy:** employee data falls under RA 10173. Use fictional seed data only; restrict the salary rate column by role.
- **UI:** follow `docs/UI_SPEC.md` exactly (layout, design tokens, tally table, Add Leave panel, reusable components). Read it before building any page.

## How to work

- Build in the phases listed in section 10 of the doc, and stop after each phase for review.
- Each phase: Pest tests pass, Pint + Larastan + TypeScript checks are clean, then summarize what changed.
- Open questions in section 11 are unresolved: ask instead of guessing.
- Never drop tables, delete data or run destructive commands without asking first.

