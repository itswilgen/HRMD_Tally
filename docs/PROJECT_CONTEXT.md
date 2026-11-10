# HRMD Leave Tally Sheet System — Project Context & Scope of Work

As of Oct 31, 2026 · Wilgen

## 1. Project overview

The HRMD Leave Tally Sheet System replaces the handwritten yearly leave tally sheet with a web system where HR staff encode each employee's leave once and the system builds the tally, totals, printout and Excel export automatically.

- **Client:** Human Resource Management Division (HRMD), city government office, Philippines
- **Developer:** Wilgen (BS Information Technology, NORSU Bais City Campus), built during OJT at HRMD

### Problem

- HR personnel write every employee's leave by hand on a paper tally sheet, one sheet per year.
- Month cells get crowded, handwriting is hard to read, and corrections are written over.
- Finding one employee's leave history means flipping through pages.
- Totals per leave type are counted by hand, which is slow and error-prone.
- There is no record of who wrote or changed an entry, and no backup if the paper is lost or damaged.

### Objectives

1. Digitize the yearly leave tally sheet so HR encodes leave once and never rewrites it.
2. Generate the tally text (e.g. "23–25 MFL") and yearly totals per leave type automatically.
3. Let HR find any employee's leave record in seconds by name, position or office.
4. Produce a printable PDF in the same layout as the paper sheet, plus an Excel export.
5. Keep an audit trail of every change and protect employee data under the Data Privacy Act.
6. Build a clean foundation that a leave credits module can be added to later.

## 2. Current manual process

HR keeps one paper tally sheet per year, with employees listed alphabetically by last name and a column for each month where leave days and codes are handwritten.

### Layout of the paper tally sheet

| Column | What it holds |
| --- | --- |
| No. | Row number |
| Last Name, First Name, Middle Name | Employee name in three columns (suffix such as "Jr." is written inside the first name) |
| Position Title | e.g. Bookbinder II, Driver II, Utility Worker I |
| SG | Salary grade |
| Rate | Monthly salary rate |
| JAN to DEC | Days of leave plus a leave code, e.g. "9 SL", "23, 24, 25 MFL", "13 PM CTO" |

### How it is used today

1. An employee's leave is approved.
2. HR finds the employee's row on that year's sheet.
3. HR writes the day(s) and leave code in the month column. A month can hold several leave types.
4. Half-days are written with AM or PM.
5. Totals and summaries are counted manually when needed.

Leave codes seen on the sheet include SL, MFL, CTO and Wellness. The full list must be confirmed with HR (see section 11).

## 3. Users and roles

The system has two roles in Phase 1. Employees do not log in.

| Permission | HR Admin | HR Staff |
| --- | --- | --- |
| View yearly tally | Yes | Yes |
| Add, edit, delete leave records | Yes | Yes |
| Add and edit employees | Yes | Yes |
| Deactivate employees | Yes | No |
| Delete an employee record created by mistake | Yes | No |
| Erase a deleted employee record permanently | Yes | No |
| View and set salary rate | Yes | No (answered) |
| Set employment status | Yes | No (read only) |
| Print PDF and export Excel | Yes | Yes |
| Manage leave types, offices, holidays | Yes | No |
| Read the office list | Yes | Yes |
| Manage user accounts | Yes | No |
| View activity log | Yes | No |

Notes on the two admin-only employee fields:

- **Salary rate** is omitted from the payload entirely for HR Staff, not sent as `null`, and the validation rule is not registered for them either — a forged POST is a no-op rather than a 403.
- **Employment status** is free text an HR Admin types from the appointment paper (`employees.set_employment_status`), blank until they do. There is no fixed list of statuses; values already in use are offered as suggestions. HR Staff read it.
- **The office list** is readable by both roles because the tally sheet is filtered by office; only an HR Admin may add, rename or remove one.

Users are office staff, not technical users, so every screen must be simple, forgiving and fast for repeated encoding.

## 4. Scope of work

Phase 1 covers recording leave and producing the yearly tally sheet; leave credit computation is deliberately excluded and planned for a later phase.

### In scope (Phase 1)

1. **Authentication and roles:** login, logout, password reset, HR Admin and HR Staff roles.
2. **Employee records:** add, edit, search, deactivate employees; offices list.
3. **Settings:** leave types (code, name, color), holidays calendar, user accounts.
4. **Leave encoding:** single date or date range, whole day or AM/PM, remarks, edit and delete.
5. **Yearly tally sheet:** employees × JAN–DEC grid with auto-generated cell text and yearly totals per leave type.
6. **Search and filters:** year, office, name or position, A–Z letter tabs.
7. **Print and export:** landscape PDF in the paper sheet's layout, Excel export.
8. **Activity log:** who created, changed or deleted which record and when.
9. **Data migration support:** importing the employee masterlist (Excel/CSV).
10. **Documentation:** README, short user manual, backup and install guide.

### Out of scope (future phases)

- Leave credit earning, balances and the CSC leave card.
- Tardiness and undertime deductions.
- Online leave application and approval by employees (CS Form No. 6).
- Employee self-service login.
- Monetization and terminal leave computation.
- Payroll, attendance (DTR) or biometrics integration.
- SMS or email notifications.
- Mobile app.

The Phase 1 database is designed so these can be added without rebuilding existing tables.

## 5. Functional requirements

Each module below lists what the user must be able to do; the Yearly Tally page is the main screen and follows the approved mockup.

### 5.1 Authentication

- Login with email and password; login rate limiting after repeated failures.
- Password reset and change password.
- Session timeout after inactivity.

### 5.2 Employees

- Fields: employee no., last name, first name, middle name, suffix, position title, salary grade, monthly rate, office, employment status, active/inactive.
- **Employment status is blank by default and typed in by an HR Admin** (answered 2026-10-31). HR did not want a fixed list, so no value is assumed: the field is nullable, HR Admin can enter whatever the appointment paper says, and HR Staff see it without being able to change it. Previously entered values are offered as suggestions so spelling stays consistent.
- List with search, filter by office and status, sorting and pagination.
- Add, edit and deactivate. Employees are never permanently deleted.
- **Deleting a record created by mistake** (added 2026-11-04). The masterlist has
  tick-boxes and a bulk delete for the case deactivating does not cover: a duplicate,
  a typo, a row imported twice. It is a soft delete, so the record is recoverable and
  stays in the activity log, and it is HR Admin only (`employees.deactivate`). An
  employee who **already has leave on file is refused, not deleted** — their days are
  printed on tally sheets that must keep reading correctly — and the screen names who
  was kept so HR can deactivate them instead.
- **Restoring a deleted record** (added 2026-11-09). The Status filter has a
  **Deleted records** option, HR Admin only, listing what has been deleted with the
  date it went. Restore puts a record back exactly as it was, active or deactivated;
  deleting is only safe to offer because restoring exists.
- **Erasing a record permanently** (added 2026-11-09, at HR's request). The one
  exception to "nothing is ever erased", and the only irreversible action in the
  system, so it is hedged four ways:
  1. It is **its own permission** (`employees.erase`, HR Admin only), not bundled
     into `employees.deactivate` — being trusted to take a row off the masterlist is
     not being trusted to destroy it, and HR can withhold this one on its own.
  2. It reaches **only records that are already deleted**. There is no route from the
     live masterlist; an id that is not soft-deleted fails validation. Erasing is
     therefore always a second decision, taken after the first one, about a row
     someone has already deleted.
  3. An employee with **leave on file is refused**, checked again at erase time, for
     the same reason deleting refuses them: their days are printed on tally sheets
     that must keep reading correctly.
  4. The **activity log keeps the names**, employee numbers and who erased them. That
     entry is all that is left of the record, which is also why erasing is capped at
     50 rows per request and asks for the word `ERASE` to be typed.

  Erasing frees the employee number for reissue, which is safe only because of (3):
  a number is dangerous to reuse when a leave history hangs off it, and an erased
  record has none.
- Import the masterlist from Excel/CSV with a preview and error report before saving.

### 5.3 Settings (HR Admin)

- **Leave types:** code, name, chip color, active flag, display order.
- **Offices:** name list used for filtering and printing.
- **Holidays:** three kinds, because Philippine holidays do not all behave the same way (answered 2026-10-31):
  - *Regular, fixed date* — recurs every year, entered once: Jan 1, Apr 9, May 1, Jun 12, Nov 30, Dec 25, Dec 30.
  - *Regular, movable* — Holy Week (Maundy Thursday, Good Friday), Eid'l Fitr, Eid'l Adha and National Heroes Day fall on different dates each year, so they are entered per year.
  - *Special non-working* — set yearly by presidential proclamation, entered per year.
- **Signatories:** the names and positions printed on the PDF footer, maintained by HR Admin (see 5.7).
- **Users:** create accounts, assign role, deactivate.

### 5.4 Leave encoding

- Open the Add Leave panel from the toolbar or from the "+" in a month cell (employee preselected).
- Choose employee, leave type, date or date range, whole day or AM/PM, and optional remarks.
- Preview the actual dates that will be saved before confirming.
- Click an existing chip to view, edit or delete that entry, with a confirmation before deleting.
- Toast message after every save or delete.

### 5.5 Yearly tally sheet

- Rows: employees sorted by last name. Columns: No., Employee, Position, SG, Rate, JAN–DEC, totals per leave type, grand total.
- Cell text generated automatically and color-coded per leave type: "9 SL", "23–25 MFL", "13 PM CTO".
- Filters: year, office, search, A–Z letter tabs. All filtering done on the server.
- Current month highlighted, future months muted.
- Sticky header row and sticky employee column.
- Rate column shown only to roles allowed to see it.

### 5.6 Employee leave history

- Per-employee page listing every leave record for a selected year, with totals per type.

### 5.7 Print and export

- PDF: landscape, same columns as the paper sheet (separate Last, First, Middle Name), office, year, page numbers, signatory block.
- **Signatories are maintained by HR Admin, not hardcoded** (answered 2026-10-31). An HR Admin adds the people who sign the sheet — name, position and the line they sign under (e.g. Prepared by, Certified correct by, Approved by) — and the PDF prints them. When the City HR Officer changes, HR edits the list instead of asking for a code change.
- Excel: same columns and data as the PDF.
- Large exports run in the background and notify the user when the file is ready.

### 5.8 Activity log

- Records create, update and delete of employees, leave records and settings: user, action, old and new values, date and time.
- Filter by user, date range and record type.

## 6. Business rules

These rules are enforced on the server and covered by automated tests.

1. **One record per leave day.** A date range is saved as one row per working day.
2. **Skip non-working days.** Ranges skip Saturdays, Sundays, dates in the holidays table, and fixed-date regular holidays, which match on month and day in every year rather than needing a row per year.
3. **Half-days.** AM or PM counts as 0.5 day; a whole day counts as 1.
4. **No double-encoding.** An employee cannot have a whole-day entry plus any other entry on the same date, or two AM (or two PM) entries on the same date. The error message names the conflicting date.
5. **Tally text.** Consecutive days of the same leave type are compressed: 23, 24, 25 → "23–25". Separate days are comma-separated: "4, 9". Half-days show AM or PM: "13 PM".
6. **One source of truth.** Tally text and totals are built only by the backend tally service, so the screen, PDF and Excel always match.
7. **Inactive employees** stay in past years' tallies and are hidden from encoding and from the current year by default.
8. **Leave types** are managed in Settings, not hardcoded. A leave type that has records can be deactivated but not deleted.
9. **Soft deletes.** Deleted leave records and employees are recoverable and recorded in the activity log. The one exception is the permanent erase in section 5.2, which is a separate HR Admin permission, reaches only records that are already deleted and never a record with leave on file.
10. **Year boundaries.** A range crossing December 31 is split into its proper years automatically.

### CTO and COCs (answered 2026-10-31)

Overtime is earned as Compensatory Overtime Credits (COCs) in **hours**, and spent as Compensatory Time Off (CTO):

11. **Earning.** 1 COC hour per hour of overtime on a workday; 1.5 COC hours per hour on a weekend or holiday.
12. **Spending.** CTO is taken in blocks of 4 or 8 hours only — never 1 or 2 hours.
13. **Caps.** At most 40 COC hours may be earned in a month, and a balance may not exceed 120 hours.

What this means for Phase 1: a 4-hour CTO block is a half day (AM or PM) and an 8-hour block is a whole day, so **CTO needs no new column** — the existing `day_part` already records it, and the tally prints "13 PM CTO" exactly as the paper sheet does. Earning, the two caps and the running balance belong to the credits module in a later phase; nothing in Phase 1 computes them.

The figures above came from secondary sources (assistance.ph, TeacherPH). Before the credits module is built they must be checked against CSC MC No. 2, s. 2004 and whatever the city's own HR issuance says, since an office can be stricter than the national rule.

## 7. Data model (PostgreSQL)

Five core tables hold the data; `leave_records` stores one row per leave day, which keeps counting simple and becomes the "leave taken" input for a future credits module.

| Table | Key columns | Notes |
| --- | --- | --- |
| `offices` | id, name | Offices/departments, e.g. Office of the City Mayor |
| `employees` | id, employee_no (unique), item_no (nullable), last_name, first_name, middle_name, suffix, position_title, salary_grade, monthly_rate, office_id, employment_status (nullable), is_active, timestamps, deleted_at | Never hard-deleted. `employment_status` is free text an HR Admin types, blank until then. `item_no` is the plantilla item from HR's own sheet; it is not unique and is not an identifier, because an item is handed on to whoever fills the position next while `employee_no` stays with one person for as long as their leave history does |
| `leave_types` | id, code (unique), name, color, is_active, sort_order | Managed by HR Admin |
| `leave_records` | id, employee_id, leave_type_id, leave_date, day_part (WHOLE/AM/PM), remarks, encoded_by, timestamps, deleted_at | One row per leave day |
| `holidays` | id, date (unique), name, kind, recurs_yearly | Skipped in date ranges. `recurs_yearly` marks the seven fixed-date regular holidays, matched on month and day so they are entered once instead of every year; `kind` separates regular from special non-working |

Supporting tables: `users`, roles and permissions (spatie/laravel-permission), `activity_log` (spatie/laravel-activitylog), `jobs` and `notifications` for background exports.

### Indexes

- `leave_records (employee_id, leave_date)` for the tally and history queries.
- `leave_records (leave_date)` for year and month reports.
- `employees (lower(last_name), lower(first_name))` for alphabetical listing.
- `employees (lower(last_name) text_pattern_ops)` for the A–Z letter tabs.
- `employees (office_id, is_active)` for office filters.
- Foreign keys on every `_id` column, each with its own index.

### PostgreSQL specifics

The engine was changed from MariaDB to PostgreSQL 17 during Phase 1. Three
differences shape the schema:

- **Case-sensitive text.** Postgres compares and sorts text case-sensitively, so
  the name indexes and the queries that use them are built on `lower(...)`.
  Without this, "de la Cruz" would sort after "Zamora" and a letter tab would
  miss a name typed in another case.
- **No automatic FK indexes.** Unlike MySQL/MariaDB, Postgres does not index a
  referencing column for you; `leave_type_id` and `encoded_by` are indexed
  explicitly.
- **Partial unique index.** The no-double-encoding rule is enforced by
  `UNIQUE (employee_id, leave_date, day_part) WHERE deleted_at IS NULL`, which
  lets a soft-deleted row free its date slot for re-encoding. `day_part` is
  additionally constrained by a `CHECK` that mirrors the `DayPart` enum.

`leave_type_id` is deliberately **not** part of the unique key: AM of one type
plus PM of another is legal; two AM rows never are.

Tests run against PostgreSQL (`hrmd_tallysheet_testing`), not SQLite, because
SQLite cannot express the DDL above.

## 8. Tech stack and architecture

The system is a single Laravel application with a React + TypeScript frontend connected through Inertia.js, following MVC with a service layer.

### Request flow

```
React page (TS + Tailwind)
   │  Inertia request
   ▼
Controller (thin, calls one service)
   ▼
Form Request + Policy (validate, authorize)
   ▼
Service layer (LeaveEncodingService, LeaveTallyService)
   ▼
Eloquent models (SoftDeletes, enums)  ◄──►  PostgreSQL (indexed tables)
   │  results
   ▼
API Resource / DTO (only needed fields)
   │  Inertia props
   ▼
React page
```

The same service layer feeds the tally page, the PDF and the Excel export, so all three always show the same numbers.

| Layer | Technology |
| --- | --- |
| Frontend | React, TypeScript (strict), Tailwind CSS, shadcn/ui, lucide-react, TanStack Table |
| Bridge | Inertia.js (Laravel React starter kit) |
| Backend | Laravel 12 (the version the React starter kit targets), PHP 8.3+ |
| Database | PostgreSQL 17 |
| Roles and audit | spatie/laravel-permission, spatie/laravel-activitylog |
| PDF and Excel | barryvdh/laravel-dompdf, maatwebsite/excel |
| Quality | Pest, Larastan (PHPStan), Laravel Pint, ESLint, Prettier |

### Code structure rules

- **Controllers** are thin: receive the request, call a service, return an Inertia page.
- **Form Requests** do all validation. **Policies** authorize every action.
- **Services** hold business logic: `LeaveEncodingService` (ranges, conflicts), `LeaveTallyService` (cell text, totals).
- **API Resources / DTOs** shape data sent to React; whole models are never sent.
- **PHP Enums** for fixed values such as `DayPart`.
- Strict types in PHP and TypeScript; shared TS types in `resources/js/types/`; reusable React components for tables, filters, chips, empty states.
- Database transactions for multi-row writes such as date ranges.

## 9. Non-functional requirements

The system must stay fast, secure and easy to use as leave records accumulate year after year.

### Performance and scalability

- Designed for long-term growth: thousands of employees and millions of leave records over the years.
- No N+1 queries; eager loading everywhere and `preventLazyLoading()` in development.
- Tally query uses a date range (`leave_date BETWEEN` start and end of year), never `YEAR(leave_date)`, so indexes are used.
- Always paginated (letter tabs + pagination); never load all employees or all records at once.
- `chunk()` / `lazy()` for exports and bulk operations; large exports run as queued jobs.
- Lookup data (offices, leave types, holidays) cached and cleared when updated.
- Target: tally page loads in under 2 seconds with 1,000+ employees and 100,000+ leave records seeded.

### Security

- Starter-kit authentication, login rate limiting, strong password rules.
- Every route and action checked by a Policy and role permission.
- All input validated; `$fillable` on models; no raw SQL with user input.
- CSRF protection, escaped output, no stack traces in production, HTTPS when served over a network.
- Secrets only in `.env`, never committed; `.env.example` provided.

### Data privacy (RA 10173)

- Employee data is personal information under the Data Privacy Act of 2012.
- Each role sees only what it needs; salary rate restricted.
- Every change logged with user and timestamp.
- Development, testing, screenshots and the OJT defense use fictional seed data only.

### Reliability

- Automated daily database backup, with a documented restore procedure.
- Soft deletes so mistakes can be undone.

### UI/UX

- Follows the approved mockup: dark green sidebar, light neutral background, white cards, green primary accent, one consistent color per leave code, defined as Tailwind theme tokens.
- Clean tables: zebra rows, hover highlight, right-aligned `tabular-nums` numbers, consistent row height.
- Loading skeletons, friendly empty states, toasts, confirmation before delete.
- Keyboard friendly (Tab, Enter, Esc); visible focus; contrast at least 4.5:1; targets at least 44 px.
- Optimized for desktop office use, usable on tablets and phones.

## 10. Deliverables and phases

The work is built in eight phases, each reviewed before the next starts; target dates are to be set with the OJT supervisor.

| # | Phase | Output |
| --- | --- | --- |
| 1 | Project setup | Laravel React starter kit, migrations, models, enums, fictional seeders |
| 2 | Roles and users | HR Admin / HR Staff roles, policies, user management |
| 3 | Employees | Employees CRUD, offices, Excel/CSV masterlist import on HR's plantilla layout (see below) |
| 4 | Settings | Leave types and holidays pages |
| 5 | Leave encoding | Add/edit/delete leave, date ranges, half-days, conflict validation |
| 6 | Yearly tally | Tally page matching the mockup, filters, letter tabs, employee history |
| 7 | Print and export | Landscape PDF in paper-sheet layout, Excel export, background jobs |
| 8 | Activity log and handover | Activity log page, README, user manual, backup guide, deployment on office LAN |

**Phases 5 and 6 were built before Phase 4** (2026-10-31). The tally sheet is the screen the
system exists for, and encoding leave is what HR does on it all day; leave types and holidays
can already be maintained through the seeders and the database, so building the settings pages
first would have delayed the only screen HR can actually judge. The holiday rules needed by the
encoder — fixed-date regular holidays recurring every year, movable ones entered per year — are
already in the schema and in `HolidayCalendar`, so Phase 4 only has to put a page in front of
them. Phase 4 is next.

The masterlist import under Phase 3 was built on 2026-11-04 on the system's own template, became a formatted `.xlsx` on 2026-11-09, and later that same day was **rebuilt on HR's real plantilla layout**, which HR supplied as a screenshot of their working sheet. The headings are now HR's own, in HR's own order:

`NO.` · `ITEM NO.` · `LAST NAME` · `FIRST NAME` · `MIDDLE NAME` · `POSITION TITLE` · `SG` · `RATE` · `OFFICE`

The first eight are the official headings, taken from the sheet HR works in; the system's template matches them exactly rather than the other way round. `OFFICE` is the one column the system adds, and it goes last so the official part of a sheet HR already keeps can be pasted straight in. Only `LAST NAME`, `FIRST NAME`, `POSITION TITLE` and `OFFICE` are required. `NO.` is read and ignored — it numbers the printed page and means nothing to the system. `RATE` is ignored for HR Staff, who never see a rate, and is left off the template they download.

Columns are matched by heading **name, not position**, which is what lets HR's own file be imported untouched: their working sheet has a hidden column between `SG` and `RATE` (so the rate sits in column I), leaves `ITEM NO.` blank on every row, and types rates as text — `13,846.00`, which is what the green "number stored as text" flags in it are. All three are handled, and the whole shape is pinned by the test *"it reads HR's own sheet as HR keeps it"*.

HR's sheet is missing three things the system needs, and each is filled from somewhere other than the official columns rather than guessed at:

- **Office** is a column HR adds to the sheet — `OFFICE`, after the official headings — so the whole workflow is choose a file and nothing else; there is no office control in the import dialog. One file may therefore span several offices, and each row is filed under the office its own row names. The name is matched against the offices on record ignoring case, spacing and punctuation, and on nothing else: a near miss is never taken as a match, because filing somebody under the wrong office quietly is worse than making HR correct a cell. An unknown name is reported as that row's problem, and the downloadable template offers the offices as an Excel dropdown (read from a hidden `Offices` sheet, since a list typed into the validation rule itself is capped at 255 characters) so the typo is hard to make in the first place.
- **Employee number** is issued by the system, in sequence after the highest `YYYY-NNNNN` already used this year. It is deliberately *not* taken from `ITEM NO.`: an item belongs to the position and is refilled when its holder leaves, so using it as the employee number would eventually file one person's leave under another's name. The item is stored as `item_no` in its own right.
- **Suffix** and **employment status** are not in HR's sheet at all and are left blank, to be filled in on the employee's page from the appointment paper.

Because employee numbers no longer come from the file, "this sheet has already been imported" is detected by identity instead: a row whose last, first and middle name *and* position title match someone already on the masterlist **in the office their row names** is flagged. Name alone would flag the two Dela Cruzes every city payroll has; name and position together is what a sheet imported twice looks like and little else does. Rows are also checked against each other, and duplicate `ITEM NO.` values within the file are flagged separately.

The rest of the mechanics are unchanged. The server checks every row against the same rules as the Add employee form, shows a preview with each row's problems, and saves all rows in one transaction or none. The heading row is found by scanning the first ten non-blank rows, so a printed plantilla's title block ("CITY GOVERNMENT OF …", "PLANTILLA OF PERSONNEL") does not have to be deleted first; blank rows are skipped without renumbering, so "row 7 has a problem" points at row 7 as HR sees it in Excel. The template is a formatted `.xlsx` with the heading row bold and frozen and the `ITEM NO.` column formatted as text, because an item is written `1` in one office and `OSEC-DOH-ADAS2-5-2014` in another. The format is decided by the file's first four bytes, not its name, so a workbook saved as `.csv` still reads; only the lifting of rows out of the file differs between the two formats — `EmployeeImportService::readWorkbook()` and `readCsv()` both return rows of text keyed by the row number HR sees in Excel, and every check past that point is shared. Only the first sheet of a workbook is ever read, so neither the template's hidden `Offices` sheet nor the sheet-per-office HR's own workbooks carry can be imported by accident.

### Each phase is done when

- Pest feature tests for its rules pass.
- Pint, Larastan and TypeScript checks report no errors.
- A short summary lists what was built, files changed and how to test it.

### Final deliverables

- Source code in a GitHub repository.
- Working system installed on the office's server or PC.
- README (install, first admin user, backup and restore, running on the LAN).
- User manual for HR staff.
- Before/after measurement of encoding time for the OJT report.

## 11. Assumptions, open questions and risks

The open questions below must be answered by HR before the tally sheet is trusted as the record. Answers are recorded with the date they were given, and the section they changed.

### Assumptions

- HR staff encode leave only after it is approved; the system does not handle approval.
- The system runs on the office's local network (LAN) on one server or PC.
- One shared tally covers all offices, filtered by office.

### Answered by HR

- [x] **Which roles may see the salary rate column?** HR Admin only. HR Staff never see monthly rate. *(2026-10-31 → sections 3, 5.5)*
- [x] **Are there employee types with different leave rules?** No fixed list. Employment status starts blank and an HR Admin types it in; HR Staff can read but not change it. *(2026-10-31 → section 5.2)*
- [x] **Is CTO recorded as whole/half days, or in hours?** Earned in hours as COCs, spent in 4- or 8-hour blocks — which map onto the existing half day / whole day. *(2026-10-31 → section 6)*
- [x] **Which holidays recur, and which are set yearly?** Seven fixed-date regular holidays recur; movable regular holidays and special non-working days are entered per year. *(2026-10-31 → section 5.3)*
- [x] **What should the PDF footer show?** A signatory block an HR Admin maintains — name, position and the line each person signs under. *(2026-10-31 → section 5.7)*
- [x] **Is there an existing employee masterlist in Excel to import, and can its layout be mapped?** Yes — a plantilla sheet headed NO. · ITEM NO. · LAST NAME · FIRST NAME · MIDDLE NAME · POSITION TITLE · SG · RATE. It maps directly; the import was rebuilt on it. HR adds an `OFFICE` column after those headings (a dropdown in the template), the employee number is issued by the system, and suffix and employment status are filled in afterwards. *(2026-11-09 → section 10)*

### Still open

- [ ] What is the complete list of leave codes and their meanings (VL, SL, MFL, SPL, CTO, Wellness, others)? Provisional codes are seeded; HR must confirm before the tally is trusted.
- [ ] Is there one tally sheet for all offices, or one per office?
- [ ] Should SG and rate be kept per year (history) or only the current value?
- [ ] Where will the system be hosted, and is internet available?
- [ ] Do the COC/CTO figures in section 6 match the city's own HR issuance, not just the national rule?

### Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Wrong or missing leave rules cause rework | Confirm the open questions above; test the tally against real paper records |
| Migrating old paper records is slow | Start with the current year; older years only if HR requests it |
| Nobody maintains the system after the OJT | README, user manual, clean code, backups automated |
| Data loss from PC failure | Daily automated backup to a second location |
| Personal data exposure | Roles, audit log, fictional data outside production |
| HR staff resist the change | Run alongside the paper sheet for 1–2 months; match the paper layout |

## 12. Acceptance criteria

Phase 1 is accepted when HR can replace the paper tally sheet with the system and every item below is checked.

- [ ] HR staff can add an employee and encode a single-day, multi-day and half-day leave in under one minute.
- [ ] Date ranges skip weekends and holidays, and the date preview is correct.
- [ ] Double-encoding the same date is blocked with a clear message.
- [ ] The yearly tally shows the same days and codes as the paper sheet for at least 5 sample employees, checked against the paper records.
- [ ] Yearly totals per leave type are correct.
- [ ] The PDF matches the paper sheet layout and prints legibly on long bond paper in landscape.
- [ ] Excel export matches the screen and the PDF.
- [ ] HR Staff cannot open Admin-only pages or see restricted columns.
- [ ] Every create, edit and delete appears in the activity log.
- [ ] The tally page loads in under 2 seconds with 1,000+ employees and 100,000+ leave records seeded.
- [ ] All automated tests pass; no Larastan or TypeScript errors.
- [ ] Backup and restore tested once successfully.
- [ ] HR supervisor signs off after the parallel run with the paper sheet.
