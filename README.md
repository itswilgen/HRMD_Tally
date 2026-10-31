# HRMD Leave Tally Sheet System

Replaces the handwritten yearly leave tally sheet used by the Human Resource
Management Division with a searchable, printable web system.

`docs/PROJECT_CONTEXT.md` is the source of truth for scope, business rules, the
data model and the phase plan. Read it before changing anything.

## Requirements

- PHP 8.3 or newer, Composer
- PostgreSQL 17 (with the `pdo_pgsql` extension enabled)
- Node 20 or newer, npm

## Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create the two databases — the test suite uses its own, and wipes it on every
run:

```bash
createdb hrmd_tallysheet
createdb hrmd_tallysheet_testing
```

Set `DB_USERNAME` and `DB_PASSWORD` in `.env` to match your PostgreSQL user,
then:

```bash
php artisan migrate --seed
npm run build
```

### Running it

```bash
composer run dev
```

That starts the PHP server, the queue worker, the log viewer and Vite together
at http://localhost:8000.

## Seeded accounts

`php artisan migrate --seed` creates two development logins, both with the
password `password`:

| Email | Role |
| --- | --- |
| `admin@hrmd.test` | HR Admin |
| `staff@hrmd.test` | HR Staff |

These are for development only. **Never seed them on the office server** — the
real first administrator is created interactively there (see *Deployment*).

All seeded employees, offices and leave records are fictional. Employee records
are personal information under the Data Privacy Act of 2012 (RA 10173), so real
names never go into development, screenshots or demos.

### Performance data

The 1,000-employee / 100,000-record dataset used to check the tally stays under
two seconds is not part of the normal seed. Load it on demand:

```bash
php artisan db:seed --class=PerformanceSeeder
```

## Quality gates

Every phase has to pass all of these before it is considered done:

```bash
composer run check     # Pint (style), Larastan level 8, Pest
npm run types          # tsc --noEmit
npm run lint           # ESLint
npm run format:check   # Prettier
```

Individually: `composer run lint` (Pint, writes), `composer run analyse`
(Larastan), `composer run test` (Pest), `npm run lint:fix`, `npm run format`.

## Deployment

To be written in Phase 8, together with the backup and restore guide and the
HR user manual. It will cover creating the first administrator interactively so
that no known password ever ships to the office server.
