# Chores Champion

Household chore tracker with gamification. Symfony 7 · PHP 8.3 · MySQL 8 (Docker) · EasyAdmin · Twig + Stimulus.

Admins define chores (type, priority, reward); users pick up chores, start and complete them and earn points per
local week (Monday 00:00 in the user's timezone). Every admin change is audited. Rewards and recurrence mechanics are deferred (see `docs/DECISIONS.md`).

## Requirements
PHP 8.3+ with `intl`, `pdo_mysql`, `ctype`, `iconv`; Composer; Docker.
No local PHP extensions? Prefix make targets with the bundled container: `make test RUN="docker compose run --rm php"`.

## Quick start
```bash
composer install
cp .env.local.example .env.local      # then set APP_SECRET
make up                               # MySQL on 127.0.0.1:3307 (waits for healthy)
make migrate                          # schema + seeded task types
make admin EMAIL=me@example.com NAME=Me PASSWORD=change-me-123
make fixtures                         # optional sample data (purges the dev DB!)
make serve                            # http://127.0.0.1:8000  (admin UI: /admin)
```

## Front page
`/` is a public landing page (hero, features, login button). Signed-in users are redirected to `/tasks`.

## Docker stack (nginx + php-fpm + MySQL)
```bash
cp .env.docker.example .env.docker    # set APP_SECRET (openssl rand -hex 32) and DB passwords
make stack-up                          # build + start, http://localhost:8080
make stack-migrate                     # run migrations in the app container
make admin RUN="docker compose exec app"  EMAIL=a@b.c NAME=Me PASSWORD=secret123
make stack-logs / make stack-down
```
- `web` (nginx) serves the compiled `/assets/` directly and forwards PHP to `app:9000` (php-fpm, `APP_ENV=prod`, non-root).
- `/metrics` is blocked at nginx; dotfiles and any `.php` other than the front controller return 404.
- Use `.env.docker` only locally/on the server; never commit it. Change the default DB passwords for any real deployment (existing DB volumes keep their old passwords).
- **TLS / public exposure:** nginx here speaks plain HTTP. Put a TLS-terminating reverse proxy (Caddy, Traefik, nginx + certbot, a cloud LB) in front, and set `SYMFONY_TRUSTED_PROXIES` to its address so Symfony sees the right client IP and scheme. Do not publish MySQL.

## Common commands
| Command | Purpose |
|---|---|
| `make test` / `test-unit` / `test-integration` / `test-functional` | PHPUnit per layer (integration and functional use the test DB) |
| `make coverage` | Coverage report (needs pcov/xdebug) |
| `make stan` | PHPStan level 6 |
| `make cs` / `make cs-fix` | PHP-CS-Fixer check / fix |
| `make check` | Style + static analysis + tests (what CI runs) |
| `php bin/console app:expire-overdue-tasks` | Expire claims past their deadline (idempotent; scheduled via Symfony Scheduler) |

CI: `.github/workflows/ci.yml` runs style, PHPStan and all suites against a MySQL service.

## Users and passwords

- **Adding users:** log in as an admin, open `/admin` → **Users** → **Add User** (name, email, timezone, password, optional Administrator role). The first admin comes from `make admin EMAIL=… NAME=… PASSWORD=…`.
- **Forgot password:** the login page links to `/forgot-password`. The user enters their email and gets a link valid for 1 hour (one active request per hour per user). The response is identical for unknown or disabled accounts, so it never reveals whether an account exists.
- **Admin-assisted reset:** in `/admin` → Users, use the **Send password reset** row action, or **Edit** the user and type a new password. If mail is not configured (`MAILER_DSN=null://null`), the reset link is shown once in a flash message instead.
- **Change own password:** Profile → *Change my password* (needs the current password).
- **Locked out completely:** `make reset-password EMAIL=me@example.com` (prompts for the password; or add `PASSWORD=…`). For Docker: `make reset-password RUN="docker compose exec app" EMAIL=…`.
- **Mail config:** `MAILER_DSN` and `MAILER_FROM` in `.env`. Dev default is Mailpit: `docker compose up -d mailpit`, then read the emails at http://localhost:8025. Production: set a real SMTP DSN (see `.env.docker.example`). Tests use `null://null`.
- Password events (`pw_reset_request`, `pw_reset_sent`, `pw_reset`, `pw_change`) appear in the audit log; hashes and tokens are never logged.

## Time handling
All instants are stored in UTC. Each user has an IANA timezone, auto-detected in the browser
(`Intl.DateTimeFormat().resolvedOptions().timeZone`) and overridable on the profile page. Deadlines are entered in local
time and converted to UTC at creation. Details and DST rules: `docs/DECISIONS.md`.

## Logging
Monolog JSON logs in `var/log/` with channels `app`, `audit`, `security`, `performance`; each record carries the request ID
(also returned as `X-Request-Id`) and user ID. No passwords, tokens or other PII are logged. Prod logs rotate.
Set the level with `APP_LOG_LEVEL`.

## Metrics
`GET /metrics` returns Prometheus text format (request latency, memory, DB query count, business counters). Allowed for
admins or networks in `APP_METRICS_ALLOWED_CIDRS`. Slow requests and slow queries are logged as warnings on the `performance` channel.
The Symfony profiler is available in dev.

## Errors
`ExceptionSubscriber` maps domain exceptions to HTTP status codes: friendly Twig pages for the web, RFC 7807
`application/problem+json` under `/api/`. Unexpected errors return a generic 500 and never leak internals; deadlocks are retried.

## REST API
Deferred; groundwork and plan in `docs/API.md`.

## Layout
`src/Service` business rules (controller-agnostic) · `src/Controller/Admin` EasyAdmin · `src/Controller` user web UI ·
`src/EventSubscriber` audit/errors/metrics/request context · `tests/{Unit,Integration,Functional}`.

## Xdebug

```bash
docker compose --env-file .env.docker -f docker-compose.yml -f compose.xdebug.yaml up -d --build app
docker compose exec app php -m | grep xdebug
```

- Uses the `fpm-dev` image target with the source bind-mounted at `/app`. Production (`fpm`) is unchanged.
- Switch modes with `XDEBUG_MODE` (e.g. `XDEBUG_MODE=debug,develop`, or `off`).
- IDE: listen on port **9003**, server name `chores-champion`, path mapping `/app` → project root.
- Debugging starts on trigger: send the `XDEBUG_TRIGGER` cookie or query parameter.
- The `php` tools container (and the `cli` image) include Xdebug with mode `off`; run with `XDEBUG_MODE=debug` to use it.

### Troubleshooting: `composer install` fails inside the `app` container

Symptom: `Failed to download composer/pcre ... /app/vendor/composer/pcre does not exist and could not be created`
and `Cannot create cache directory /var/www/.composer/cache`.

Cause: php-fpm runs as `www-data` (uid 33) but the bind-mounted `vendor/` and `var/` belong to your host user (uid 1000).

Fix: `compose.xdebug.yaml` runs `app` as `${UID}:${GID}` with `COMPOSER_HOME=/tmp/composer`. Bash does not export `UID`, so run `export UID GID=$(id -g)` or set them in `.env.docker`, then rebuild:

```bash
docker compose --env-file .env.docker -f docker-compose.yml -f compose.xdebug.yaml up -d --build app
make install-app     # composer install in the app container as your user
make install RUN="docker compose run --rm php"   # or use the tools container
sudo chown -R $USER:$USER vendor var   # only if earlier runs left root-owned files
```
