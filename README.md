# Gold Stock & Loan Management

This repository contains the implementation blueprint, architecture, database model, API contract, UI plan, business rules, testing strategy, and atomic task backlog for a Jewellery Shop Gold Stock & Loan Management application.

## Recommended implementation workflow

1. Read `docs/00-project.md`.
2. Read the relevant architecture/database/API document.
3. Read exactly one task file.
4. Implement only that task.
5. Run the task's acceptance checks.
6. Do not modify unrelated code.
7. Update the task status only after verification.

## Suggested Claude prompt

Read:
- `docs/00-project.md`
- `docs/02-architecture.md`
- `docs/03-database.md`
- `docs/04-api.md`
- `docs/05-auth.md`
- `docs/06-frontend.md`
- `docs/07-backend.md`
- `docs/tasks/<TASK>.md`

Implement only `<TASK>`.

Rules:
- Follow the existing project conventions.
- Do not modify unrelated modules.
- Do not invent business rules.
- Keep financial calculations server-authoritative.
- Preserve auditability of loan, collateral, payment and reversal records.
- Add/update tests required by the task.
- Do not refactor unrelated code.
- Report changed files, tests run, and any blockers when finished.

## Stack

Mirrors the existing Inventory POS (`mobile_shop_inventory_pos`): Laravel 12 (PHP 8.2+), Inertia 2 + React 19 +
TypeScript, Tailwind CSS 4 with shadcn/ui components, Laravel Sanctum, Spatie Laravel Permission, PHPUnit 11.
The one deliberate difference is the database: **PostgreSQL** (16+ recommended), per `docs/00-tech-stack.md`.

## Local development

Requirements: PHP 8.2+ (with `pdo_pgsql`, `bcmath`, `gd`, `intl`, `zip`), Composer, Node 20+, PostgreSQL 16+.

```bash
composer install
npm install
cp .env.example .env                # then set DB_* and ADMIN_* values
php artisan key:generate
createdb gold_loan
php artisan app:check-environment   # validates config + PostgreSQL connectivity
php artisan migrate --seed          # creates roles, permissions and the initial Admin
composer dev                        # serves app, queue listener, logs and Vite
php artisan schedule:work           # optional, separate terminal: runs the daily interest job
```

The seeder creates the Admin from `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD`. In local
environments an empty `ADMIN_PASSWORD` falls back to `password`; elsewhere the admin is skipped
until a password is provided. Public self-registration is disabled — an Admin creates staff accounts.
There is no demo-data seeder; model factories (`database/factories`) are for tests.

## Environment variables

`.env.example` lists every variable with comments. The ones that matter most:

| Variable | Purpose |
|----------|---------|
| `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` | Production: `production`, `false`, generated key, public `https://` URL. |
| `APP_TIMEZONE` | Business-date policy: "today", due dates and the daily interest run. Set once, before the first loan. |
| `TRUSTED_PROXIES` | Reverse proxy IPs/CIDRs whose `X-Forwarded-*` headers are trusted. |
| `DB_*` | PostgreSQL connection (`DB_CONNECTION=pgsql` is required outside tests). |
| `SESSION_SECURE_COOKIE` | `true` in production (HTTPS). |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DAILY_DAYS` | Logging; production: `LOG_LEVEL=info`. |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Initial Admin created by `db:seed` (password required outside local). |
| `SHOP_*`, `CURRENCY_*`, `GRACE_DAYS`, `ALERT_MISSED_PERIOD_THRESHOLD` | Defaults until values are saved in Settings. |
| `INTEREST_BASE`, `INTEREST_DUE`, `INTEREST_YEARLY_CONVERSION` | Interest method for new loans (`config/loans.php`). |
| `EXPORT_MAX_ROWS` | Largest PDF/Excel export (default 5000 rows). |
| `SANCTUM_TOKEN_EXPIRATION` | API token lifetime in minutes. |
| `RUN_MIGRATIONS` | Docker production: the `app` container migrates on start when `true`. |

`php artisan app:check-environment` validates the configuration (key, URL, debug, secure cookie, PostgreSQL
driver/version and connectivity) and exits non-zero on errors.

## Migrations

```bash
php artisan migrate            # development
php artisan migrate --force    # production (take a backup first)
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # after upgrades: registers new permissions
```

Destructive commands (`migrate:fresh`, `db:wipe`, …) are refused when `APP_ENV=production`.

## Scheduler and queue

- **Scheduler** — `loans:process-interest` runs daily at 00:05 (`APP_TIMEZONE`): generates due interest
  periods, refreshes due/overdue statuses and raises/resolves missed-payment alerts. Idempotent; run it by hand
  with `php artisan loans:process-interest [--date=YYYY-MM-DD]`. Needs `php artisan schedule:work` (Docker
  `scheduler` service) or a cron entry `* * * * * php artisan schedule:run`.
- **Queue** — nothing is queued: PDF/Excel exports run in the request, capped by `EXPORT_MAX_ROWS`.
  `QUEUE_CONNECTION=database` is configured; start a worker (`php artisan queue:work`, Docker `--profile
  queue`) only if queued jobs are added.

## Docker development

```bash
docker compose up -d                # app (PHP-FPM), nginx, node (Vite HMR), pgsql
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
docker compose exec app php artisan schedule:work   # optional: daily interest job
```

App: http://localhost:8080 · PostgreSQL: `127.0.0.1:55432` (loopback only). `DB_*` values come from
`docker-compose.yml`, so the same `.env` works inside and outside Docker.

## Production (Docker)

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d pgsql
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml run --rm app php artisan db:seed --force
docker compose -f docker-compose.prod.yml up -d    # app, web (Nginx), scheduler, pgsql
```

Images: `production` (PHP-FPM with code, production dependencies and built assets) and `web` (Nginx with
`public/`). Health check: `GET /up` (application + database). Full guide — `.env` values, TLS/reverse proxy,
updates, logging, backups and restores, non-Docker servers: [`docs/deployment.md`](docs/deployment.md).

## Checks

```bash
npm run build           # feature tests render Inertia pages and need the Vite manifest
php artisan test        # PHPUnit (in-memory SQLite)
php artisan test --configuration=phpunit.pgsql.xml   # same suite on PostgreSQL (database gold_loan_testing)
vendor/bin/pint --test  # PHP code style
npx eslint . && npx tsc --noEmit && npm run format:check
```

## Conventions

- `app/Actions` — transaction workflows (one class per use case), called from thin controllers.
- `app/Domain/<Module>` — domain services per module (`Customer`, `Loan`, `Interest`, `Collateral`, `Payment`,
  `CustomerLedger`, `Alert`, `Expense`, `Reporting`); see `docs/02-architecture.md` and `docs/07-backend.md`.
- `app/Enums/Permission.php` — the single catalogue of permission names; `SystemRole` holds the built-in roles.
  Loan-module permissions are registered by `docs/tasks/003-auth.md`.
- Audit columns: use `$table->userstamps()` in migrations and the `HasUserstamps` model trait.
- Frontend: pages in `resources/js/pages`, module UI in `resources/js/features/<module>`,
  sidebar entries in `resources/js/config/navigation.ts` (each with its required `permission`).
