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

### Clearing the sample employees

Before HR enters its first real record, empty the masterlist:

```bash
php artisan hrmd:clear-employees
```

It removes every employee and every leave record — including soft-deleted ones —
and keeps the offices, leave types, holidays and user accounts. It prints the
counts and asks you to type `DELETE` first; `--force` skips the prompt for
scripts.

This is a console command because the screens deliberately cannot do it:
deleting and erasing both refuse an employee who has leave on file, so the
seeded employees cannot be cleared through the interface, and that refusal is
the rule protecting real records rather than a gap to work around.

It clears the **whole** masterlist. Nothing marks a seeded employee as seeded,
and the import issues real employees the same `YYYY-NNNNN` number shape the
factory invents, so there is no filter that could reliably tell them apart. Run
it before real records exist, not after.

> **Do not seed again afterwards.** `EmployeeSeeder` skips only when the table is
> not empty, so `php artisan db:seed` or `migrate --seed` after a clear puts all
> 76 fictional employees straight back.

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

The system is installed **once**, on one office server. HR PCs only need a
browser: they open `http://<server-ip>:8080`. Never install it on each HR PC —
every copy would have its own database, and no two PCs would show the same
tally.

### Install with Docker (recommended)

Best on a dedicated PC running Ubuntu Server (Docker Engine is free there). On
Windows, Docker Desktop works too, but it needs WSL 2, about 4 GB of spare RAM,
and only runs after someone signs in; check that its license covers the office
(organisations with 250 or more employees need a paid plan).

The stack in `compose.yaml` runs four containers: `app` (Apache + PHP), `queue`
(background jobs), `db` (PostgreSQL 17, not reachable from the network) and
`backup` (a daily `pg_dump` into `./backups`). All restart by themselves after a
crash or a reboot.

1. **Install Docker.** Ubuntu: follow *Install Docker Engine on Ubuntu* at
   docs.docker.com. Check with `docker compose version`.
2. **Get the code** into a folder of its own:
   ```bash
   git clone <repository-url> hrmd && cd hrmd
   ```
3. **Make the settings file** and change the lines marked `CHANGE`:
   ```bash
   cp .env.docker.example .env
   openssl rand -base64 24        # use this as DB_PASSWORD
   ```
   `APP_URL` is the server's **fixed** IP — reserve it on the router first.
4. **Build the image and make the app key:**
   ```bash
   docker compose build
   docker compose run --rm --no-deps app php artisan key:generate --show
   ```
   Paste the printed `base64:...` value after `APP_KEY=` in `.env`. Keep a copy
   of the key outside the server and outside Git.
5. **Start it:**
   ```bash
   docker compose up -d
   docker compose ps               # app and db should say "healthy"
   ```
   Every start runs the migrations and syncs the roles by itself.
6. **Set it up and create the first HR Admin:**
   ```bash
   docker compose exec app php artisan hrmd:install
   ```
   It seeds the offices, leave types and holidays, then asks for the first HR
   Admin's name, email and password. The password is typed, never stored in a
   file, so no known password ever ships to the server. The development logins
   and sample employees are **not** created. Running it again is safe: it keeps
   what HR has changed and skips the admin once one exists.
7. **Open it from another PC** at `http://<server-ip>:8080`. If it does not
   load, allow the port in the server's firewall (Ubuntu:
   `sudo ufw allow from 192.168.0.0/16 to any port 8080`).

#### Everyday commands

| Task | Command |
| --- | --- |
| See status | `docker compose ps` |
| Read the logs | `docker compose logs -f app` |
| Restart | `docker compose restart app queue` |
| Stop / start | `docker compose stop` / `docker compose up -d` |
| Update to a new version | `git pull && docker compose up -d --build` |
| Back up now | `docker compose exec backup pg_dump --format=custom --file=/backups/manual.dump` |

Back up before every update. `docker compose down -v` **deletes the database**
— never use `-v` on the real server.

#### Backups

The `backup` container saves `backups/hrmd-YYYY-MM-DD-HHMM.dump` when it starts
and every 24 hours, keeping `BACKUP_KEEP_DAYS` days (30 by default). Copy the
`backups` folder to an external drive or another PC every week: a backup on the
same disk is lost with the disk.

#### Restore a backup

```bash
docker compose stop app queue
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner' < backups/hrmd-2026-11-10-0900.dump
docker compose start app queue
```

Test a restore at least once before the system goes live. Restoring onto a new
server also needs the same `APP_KEY` as the old one.

#### Server without internet

Build on a PC that has internet, then carry the image over on a USB drive. Use
`--platform linux/amd64` when building on an Apple Silicon Mac for an Intel/AMD
server:

```bash
docker build --platform linux/amd64 -t hrmd-tallysheet:latest .
docker save hrmd-tallysheet:latest postgres:17-alpine | gzip > hrmd-images.tar.gz
# on the server, in the project folder:
gunzip -c hrmd-images.tar.gz | docker load
docker compose up -d
```

### Install without Docker

Follow *Setup* above on the server, but with `APP_ENV=production` and
`APP_DEBUG=false` in `.env`, `composer install --no-dev --optimize-autoloader`,
`php artisan migrate --force`, then `php artisan hrmd:install` instead of
`--seed`, and `php artisan optimize`. The server process must be started by the
operating system (systemd, a Windows service, or launchd) so it survives a
reboot.
