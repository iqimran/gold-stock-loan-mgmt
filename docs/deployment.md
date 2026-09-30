# Deployment

Production runs from the images built by `Dockerfile` and started by `docker-compose.prod.yml`. A
non-Docker server works too; see [Without Docker](#without-docker).

## Services

| Service     | Image target | Purpose                                                                                     |
|-------------|--------------|---------------------------------------------------------------------------------------------|
| `app`       | `production` | PHP-FPM with the application, production Composer packages and built Vite assets baked in. |
| `web`       | `web`        | Nginx: serves `public/` (built assets) and proxies PHP to `app:9000`. Publishes `APP_PORT`. |
| `scheduler` | `production` | `php artisan schedule:work`, which runs the daily interest job (see [Scheduler](#scheduler)). |
| `queue`     | `production` | `php artisan queue:work`. **Off by default** (`--profile queue`): nothing is queued today.  |
| `pgsql`     | `postgres:16-alpine` | Database. Only reachable on the internal Docker network.                          |

Volumes: `pgsql-data` (database) and `storage` (`storage/`: uploaded customer images and the shop logo in
`storage/app/private`, log files, file sessions/cache if configured). **Back up both.**

## First deployment

```bash
cp .env.example .env
# Edit .env — at minimum:
#   APP_ENV=production  APP_DEBUG=false  APP_URL=https://loans.example.com  APP_TIMEZONE=Asia/Dhaka
#   SESSION_SECURE_COOKIE=true  LOG_LEVEL=info  TRUSTED_PROXIES=<reverse proxy IP/CIDR>
#   DB_DATABASE=gold_loan  DB_USERNAME=gold_loan  DB_PASSWORD=<strong password>
#   ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD (initial Admin; required outside local)
#   SHOP_* and CURRENCY_* defaults (can also be set later in Settings)

docker compose -f docker-compose.prod.yml build

# Generate the application key and paste it into .env as APP_KEY=...
docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show

docker compose -f docker-compose.prod.yml up -d pgsql
docker compose -f docker-compose.prod.yml run --rm app php artisan app:check-environment
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml run --rm app php artisan db:seed --force   # roles, permissions, Admin

docker compose -f docker-compose.prod.yml up -d
curl -fsS http://127.0.0.1:${APP_PORT:-8080}/up   # 200 when the app and database are healthy
```

Sign in as the Admin, change the password, then fill in **Settings** (shop details, currency, grace days,
missed-period alert threshold) and create staff accounts. Self-registration is disabled.

`APP_TIMEZONE` is the business-date policy: "today", due dates and the 00:05 interest run use it. Set it
before the first loan is created and do not change it afterwards.

## Updating

```bash
git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml run --rm app php artisan db:seed --class=RolesAndPermissionsSeeder --force
docker compose -f docker-compose.prod.yml up -d
```

Take a [backup](#backups) before migrating. The permissions seeder is idempotent: it only adds permissions
introduced by the release (existing role assignments are kept).

Alternatively set `RUN_MIGRATIONS=true` in `.env`: the `app` container then runs `migrate --force` on start
(`docker/php/entrypoint.sh`). Every production container runs `php artisan optimize` on start, so config,
routes, events and views are cached from the runtime environment. After editing `.env`, recreate the
containers (`up -d --force-recreate`) so they pick up the change.

Destructive commands (`migrate:fresh`, `db:wipe`, …) are refused when `APP_ENV=production`.

## TLS and reverse proxy

`web` speaks plain HTTP. Put a TLS-terminating proxy (Caddy, host Nginx, a load balancer) in front of it:

- publish `web` on loopback only when the proxy runs on the same host: `APP_PORT=127.0.0.1:8080`;
- set `TRUSTED_PROXIES` to the proxy's address so HTTPS URLs, redirects and client IPs (rate limiting,
  audit log, failed-login records) are correct;
- `APP_URL` must be the public `https://` URL and `SESSION_SECURE_COOKIE=true`.

`php artisan app:check-environment` reports an HTTP `APP_URL`, `APP_DEBUG=true` or a missing secure cookie in
production.

## Scheduler

`routes/console.php` schedules `loans:process-interest` daily at 00:05 (`APP_TIMEZONE`), without overlap. For
every open loan it generates due interest periods, refreshes due/overdue statuses, applies overdue detection
and raises or resolves missed-payment alerts (threshold from Settings). The run is idempotent and catches up
after downtime; one failing loan is logged and never blocks the others.

- Output: `storage/logs/scheduler.log`; a failed run also logs an error to the application log.
- Manual / catch-up run: `docker compose -f docker-compose.prod.yml exec app php artisan loans:process-interest`
  (optionally `--date=YYYY-MM-DD`).
- Check the schedule: `php artisan schedule:list`.

## Queue

There are no queued jobs: PDF/Excel exports are generated within the request and capped by
`EXPORT_MAX_ROWS` (users are asked to narrow the filters above it). `QUEUE_CONNECTION=database` is configured
and the `jobs`/`failed_jobs` tables exist, so if queued work is added later start a worker with
`docker compose -f docker-compose.prod.yml --profile queue up -d` and restart it on each deployment
(`php artisan queue:restart`).

## Logging and errors

- Containers log to `stack` with `LOG_STACK=daily,stderr` (override in `.env`): `docker compose -f
  docker-compose.prod.yml logs -f app scheduler`, plus daily files in `storage/logs` kept for
  `LOG_DAILY_DAYS` (14) days. PHP-FPM worker output and PHP errors also go to the container log.
- Use `LOG_LEVEL=info` (or `warning`) in production.
- With `APP_DEBUG=false`, browser users get the application's error page for 403/404/500/503 and API clients
  get JSON; stack traces are only written to the log.

## Health checks

- `GET /up` returns 200 when the application boots and the database answers, 500 otherwise. The `web`
  container's health check uses it; point an uptime monitor or load balancer at it too.
- `app` is checked through PHP-FPM's `/fpm-ping` (never routed through Nginx); `pgsql` with `pg_isready`.

## Backups

Customer, loan, payment and audit data live in PostgreSQL; uploaded images and the shop logo live in the
`storage` volume. Back up both, keep copies off the server, and test restores. Dumps contain personal and
financial data: store them encrypted with restricted access (`backups/` is git- and Docker-ignored).

```bash
mkdir -p backups
# Database (custom format, compressed)
docker compose -f docker-compose.prod.yml exec -T pgsql \
  sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > backups/gold_loan_$(date +%F_%H%M).dump
# Uploaded files
docker run --rm -v gold-loan-prod_storage:/data:ro -v "$PWD/backups":/backup alpine \
  tar czf /backup/storage_$(date +%F_%H%M).tgz -C /data app
```

Schedule these with cron on the host (for example daily, before business hours) and prune old copies.

Restore (stop writers first):

```bash
docker compose -f docker-compose.prod.yml stop web app scheduler
docker compose -f docker-compose.prod.yml exec -T pgsql \
  sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner' < backups/<file>.dump
docker run --rm -v gold-loan-prod_storage:/data -v "$PWD/backups":/backup alpine \
  tar xzf /backup/<file>.tgz -C /data
docker compose -f docker-compose.prod.yml up -d
```

The audit log is append-only in PostgreSQL (updates/deletes are rejected by a trigger); `--clean` drops and
recreates the table, so a full restore still works.

## Without Docker

Requirements: PHP 8.2+ with `bcmath`, `gd`, `intl`, `pdo_pgsql`, `zip`, `mbstring`, `xml`, `pcntl`; Composer;
Node 20+ (build only); PostgreSQL 16+; Nginx or Apache with `public/` as the document root.

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan app:check-environment
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

- Scheduler: cron `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
- Queue worker (only if queued jobs are added): run `php artisan queue:work --tries=3` under Supervisor/systemd.
- `storage/` and `bootstrap/cache/` must be writable by the PHP user; `storage/` holds uploads and must be
  backed up.
