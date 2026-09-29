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

Requirements: PHP 8.2+ (with `pdo_pgsql`, `bcmath`), Composer, Node 20+, PostgreSQL.

```bash
composer install
npm install
cp .env.example .env                # then set DB_* and ADMIN_* values
php artisan key:generate
createdb gold_loan
php artisan app:check-environment   # validates config + PostgreSQL connectivity
php artisan migrate --seed          # creates roles, permissions and the initial Admin
composer dev                        # serves app, queue, logs and Vite
```

The seeder creates the Admin from `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD`. In local
environments an empty `ADMIN_PASSWORD` falls back to `password`; elsewhere the admin is skipped
until a password is provided. Public self-registration is disabled — an Admin creates staff accounts.

## Docker development

```bash
docker compose up -d                # app (PHP-FPM), nginx, node (Vite HMR), pgsql
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

App: http://localhost:8080 · PostgreSQL: `127.0.0.1:55432` (loopback only). `DB_*` values come from
`docker-compose.yml`, so the same `.env` works inside and outside Docker. Production Docker
configuration is part of `docs/tasks/022-production-readiness.md`.

## Checks

```bash
npm run build           # feature tests render Inertia pages and need the Vite manifest
php artisan test        # PHPUnit (in-memory SQLite)
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
