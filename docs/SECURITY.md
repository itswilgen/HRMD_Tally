# Security standard

The HRMD Leave Tally Sheet holds personal information about City of Tanjay employees:
names, positions, salary rates and leave history. Under the Data Privacy Act of 2012
(RA 10173) that makes the system a personal data processor, so security is part of every
change, not a phase at the end.

This file has four parts: what is already in place (and where), the rules every new
change follows, the open items, and what to do if something goes wrong. Business rules
live in `docs/PROJECT_CONTEXT.md`, the design in `docs/UI_SPEC.md`.

## 1. In place today

| Area | Control | Where |
| --- | --- | --- |
| Accounts | No self-registration. Only an HR Admin creates accounts. | `routes/auth.php` (no `register` route), `UserController` |
| First admin | Created at install with a typed password; the seeded test logins are never created in production. | `php artisan hrmd:install` (`InstallCommand`) |
| Sign-in | 5 failed attempts per email and IP, then a wait before the next try. | `app/Http/Requests/Auth/LoginRequest.php` |
| Deactivated accounts | Cannot sign in, and an open session is ended on the next request. | `LoginRequest`, `EnsureAccountIsActive` middleware |
| Passwords | Hashed with bcrypt (12 rounds). In production: at least 12 characters, letters, numbers, mixed case, and not found in known breaches. | `AppServiceProvider` (`Password::defaults`) |
| Permissions | Two roles, HR Admin and HR Staff, built from one list in code and synced on every deploy. Every controller checks a Policy; every write goes through a Form Request that checks it again. | `app/Enums/UserRole.php`, `app/Policies/`, `app/Http/Requests/` |
| Salary rate | Left out of the page data entirely for HR Staff, not hidden in the browser. | `EmployeeResource` (`viewSalary`) |
| Input | Every write is validated on the server. Totals and tally text are built on the server, never taken from the browser. | Form Requests, `LeaveTallyService` |
| Forms | Laravel's CSRF protection on every form; the JSON helpers send the XSRF token. | Laravel middleware, `resources/js/lib/json-request.ts` |
| Sessions | Stored in the database, HttpOnly cookie, `SameSite=lax`, 120-minute lifetime. | `config/session.php`, `.env` |
| Uploads | Only `.xlsx` and `.csv`, checked by file name and by the bytes; 2 MB; at most 5,000 rows; only the first sheet is read; nothing is kept on disk after the request. | `ImportEmployeesRequest`, `ImportCreditsRequest`, `app/Support/SheetReader.php` |
| Records | Employees and leave are soft-deleted and can be restored. Permanent erase is HR Admin only, and only for records with no leave on file. | `EmployeeService`, `EmployeePolicy` |
| Audit trail | Every create, change and delete is written to the activity log with who did it. | `spatie/laravel-activitylog` |
| Production | `APP_DEBUG=false` and `APP_ENV=production` are forced; the database has no open port; Apache sends `nosniff`, `SAMEORIGIN` and a strict referrer policy; a daily `pg_dump` backup. | `compose.yaml`, `docker/apache.conf`, `docker/backup.sh` |
| Tests | The test suite refuses to run against any database whose name does not end in `_testing`, so a cached config can never wipe real data. | `tests/TestCase.php` |
| Secrets | `.env` is never committed. `APP_KEY` and the database password live only in `.env` on the server, with a copy of the key kept off the server. | `.gitignore`, README "Install with Docker" |

## 2. Rules for every change

A change is not done until each of these holds. Reviewers check them; tests prove the
ones that can be tested.

1. **Every route has an owner.** It sits behind `auth`, checks a Policy, and has a feature
   test that an account without the permission gets `403` (or is sent to sign in).
2. **Validate on the server, always.** Use a Form Request. Never trust a value only because
   the page would not let someone type it.
3. **Send only what the person may see.** Use `$this->when(...)` in Resources for
   restricted fields. Hiding a column in React is not protection.
4. **No raw SQL with values glued in.** Use the query builder or bindings.
5. **Log every change to a record** in the activity log, with the old and new values.
6. **Uploads are checked by content and size**, never stored in `public/`, and never run.
7. **Exports are safe to open.** Any cell that starts with `=`, `+`, `-` or `@` is written
   as text, so a name typed as a formula cannot run in Excel (applies to Phase 2).
8. **No secrets in code, logs, screenshots or the vault.** Only in `.env`.
9. **No real employee names outside production.** Development, tests, screenshots and
   demos use the fictional seed data.
10. **Dependencies are audited before merge:** `composer audit` and `npm audit` report no
    known advisories, or the advisory is written down below with a reason.
11. **Never run `php artisan optimize` or `config:cache` in the development folder.** Only the
    deploy scripts run them, and only in a server copy.

## 3. Open items

| Item | Risk | Plan |
| --- | --- | --- |
| `composer audit` (2026-11-17): advisories in `laravel/framework` (low) and `symfony/http-foundation`, `http-kernel`, `routing`, `yaml` | Known issues in the framework | Update Laravel and Symfony and re-run every check (roadmap Phase 0) |
| `npm audit` cannot run: the configured registry does not support the audit endpoint | Unknown JavaScript advisories | Run the audit against the public npm registry (Phase 3) |
| No HTTPS on the office network; `SESSION_SECURE_COOKIE` unset | Passwords and cookies cross the LAN unencrypted | HTTPS on the server, then secure cookies on (Phase 3 / 4) |
| No sign-out after inactivity; sessions are not ended on other devices when a password changes | An unattended PC stays signed in | Idle sign-out after 30 minutes, sign out everywhere on password change (Phase 3) |
| Lockout is a short wait, not an account lock | Slow password guessing | Lock after repeated failures and log the attempts (Phase 3) |
| Not every route has a permission test yet | A missing check could go unnoticed | One test per route (Phase 3) |
| `SESSION_ENCRYPT=false` | Session data readable in the database | Turn on with HTTPS (Phase 3) |
| No written retention rule for deleted records | Personal data kept longer than needed | Agree the rule with HR and write it here (Phase 3) |

## 4. If something goes wrong

1. **Contain it.** Deactivate any account that may be misused and change the passwords that
   may be known. On the server, stop the stack if records are being changed.
2. **Keep the evidence.** Do not clear the logs or the activity log. Copy them off the server.
3. **Check the records.** Compare with the latest backup; restore it if records were changed
   or deleted (README, "Restore a backup").
4. **Tell the HRMD head the same day.** A breach of personal data may have to be reported to
   the National Privacy Commission; the deadline and the form must be confirmed with the
   city's Data Protection Officer before go-live.
5. **Write down what happened** and add the fix to section 2 or 3, so it does not happen twice.
