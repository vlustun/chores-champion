# Build Plan

> Help me plan an application for tracking chores in house  It has to have an admin interface where you set the avaliable chores with their urgency  * I want to have a gamification system and earn rewards for the end of the week - we'll decide what those are later  * Build this in Symfony PHP. And Mysql DB  * docker for db for local development. * add correct and comprehensive error handling for all cases including edge cases  * add unit tests * and integration tests * add logging, * add performance metrics * add a REST API to be accesible via API but we add this later   data structure we need USER (auth, or API acces) - picks a task, or adds a task, sets a priority and a reward value  user chooses a task - creates a task_user object - task_id, user_id, started_at,  completed_at, status, parent_task- optional, points_gained tasks object must have - type (cleaning, cooking, pet, hygene, medication, errands, others) - you have to be able to add more types user object - name, email, phone_nr.   all admin users can add tasks without approval, but recod every change an admin user does.  some tasks are one off, some tasks are reusable, and recurrent - we will discuss about the mechanics later  week starts on monday - timezone - user's timezone - actually we should brain storm this a little  we will work on reward_points in later itteration.   * store data in UTC on the server, but display, calculate, and trigger deadlines using the User’s local timezone * Auto-detect the device timezone

**Stack:** PHP 8.3, Symfony 7.x (LTS-compatible), Doctrine ORM + Migrations, Symfony Security (form login now, API token/JWT later), Twig + Symfony UX/Stimulus (JS timezone auto-detect via Intl.DateTimeFormat().resolvedOptions().timeZone), EasyAdminBundle for admin UI, Symfony Validator, Monolog (JSON logs, separate channels), Symfony Stopwatch/Profiler + custom metrics service (Prometheus-format endpoint via promphp/prometheus_client_php, or simple DB/APCu counters), PHPUnit + Symfony test pack + DAMA DoctrineTestBundle + Foundry/fixtures for tests, PHPStan + PHP-CS-Fixer. MySQL 8 via docker-compose (db + separate test db + optional adminer); the PHP app runs locally via symfony serve or the PHP built-in server. Commands via Makefile.

## Files

- `docker-compose.yml` — MySQL 8 service (dev + test databases), volumes, healthcheck, optional Adminer
- `Makefile` — Shortcuts: up, down, migrate, test, test-unit, test-integration, stan, cs-fix, fixtures
- `.env / .env.test / .env.local.example` — DATABASE_URL, APP_ENV, default timezone fallback, log level; no secrets committed
- `composer.json` — Dependencies and scripts
- `config/packages/security.yaml` — User provider, form login firewall, role hierarchy (ROLE_USER, ROLE_ADMIN), reserved api firewall stub
- `config/packages/monolog.yaml` — JSON formatter, channels: app, audit, security, performance; rotating file handlers
- `config/packages/doctrine.yaml` — UTC connection config, custom UTC datetime types, MySQL strict mode
- `config/routes.yaml` — Web routes; api routes prefix /api/v1 reserved for later
- `src/Entity/User.php` — id, name, email (unique), phone_nr, password hash, roles, timezone (IANA string), created_at/updated_at UTC
- `src/Entity/Task.php` — Chore definition: title, description, type (FK), urgency/priority enum, reward_value (nullable, int), recurrence_kind enum (one_off/reusable/recurring) plus nullable recurrence config JSON placeholder, is_active, created_by, timestamps
- `src/Entity/TaskType.php` — Extensible lookup table: name, slug, is_active; seeded with cleaning, cooking, pet, hygiene, medication, errands, others
- `src/Entity/TaskUser.php` — Assignment/claim: task_id, user_id, started_at, completed_at, status enum, parent_task_user_id (nullable self-ref), points_gained, deadline_at (UTC), user_timezone snapshot, week_key; indexes on user/status/week
- `src/Entity/AuditLog.php` — Append-only record: actor_id, entity_type, entity_id, action (create/update/delete), changes diff JSON, ip, request_id, created_at UTC
- `src/Entity/WeeklyResult.php` — Placeholder for the weekly summary per user (week_start_utc, total_points, rank); rewards definition deferred
- `src/Enum/TaskStatus.php` — pending, in_progress, completed, cancelled, expired with allowed-transition map
- `src/Enum/TaskPriority.php` — low, medium, high, urgent with weights
- `src/Enum/RecurrenceKind.php` — one_off, reusable, recurring
- `src/Repository/` — Repositories for each entity with query methods (active tasks, user open tasks, weekly totals)
- `src/Service/Time/UserClock.php` — Converts between UTC and user timezone; computes week start (Monday 00:00 local) and week end in UTC; handles DST and invalid timezone fallback
- `src/Service/Time/TimezoneResolver.php` — Validates IANA tz from client, persists to user, fallback chain: user setting, cookie, app default
- `src/Service/TaskWorkflowService.php` — Claim task, start, complete, cancel; enforces state machine, concurrency (optimistic lock/transaction), duplicate-claim rules, parent-task rules
- `src/Service/PointsCalculator.php` — Interface plus simple implementation (reward_value x priority multiplier, on-time bonus placeholder) to be refined later
- `src/Service/WeeklySummaryService.php` — Aggregates points per user per local week; foundation for end-of-week rewards
- `src/Service/Audit/AuditLogger.php` — Writes AuditLog rows and the audit log channel
- `src/EventSubscriber/DoctrineAuditSubscriber.php` — Listens to Doctrine onFlush for admin-modified entities (Task, TaskType, User) and records diffs automatically
- `src/EventSubscriber/ExceptionSubscriber.php` — Central error handling: maps domain exceptions to HTTP codes, JSON for /api, friendly Twig pages for web, logs with context
- `src/EventSubscriber/RequestContextSubscriber.php` — Request ID generation, user timezone applied per request, logging context processor
- `src/EventSubscriber/MetricsSubscriber.php` — Measures request duration, memory, DB query count, per-route histogram
- `src/Exception/` — Domain exceptions: TaskNotFound, InvalidStatusTransition, TaskAlreadyClaimed, InvalidTimezone, ForbiddenAction, ValidationFailed
- `src/Metrics/MetricsCollector.php` — Counters/histograms (tasks claimed/completed, points awarded, request latency); /metrics endpoint
- `src/Controller/Admin/` — EasyAdmin CRUD controllers: Task, TaskType, User, AuditLog (read-only), dashboard
- `src/Controller/TaskController.php` — User web UI: browse available tasks, claim, start, complete, add own task with priority and reward value
- `src/Controller/ProfileController.php` — Timezone view/override, profile editing, plus endpoint that receives auto-detected timezone
- `src/Controller/Api/` — Empty/stubbed namespace for later REST API (versioned /api/v1); services stay controller-agnostic so API reuses them
- `src/Command/CreateAdminCommand.php` — Bootstrap the first admin user
- `src/Command/ExpireOverdueTasksCommand.php` — Cron-driven command; finds task_user rows past deadline (UTC compare, computed from the user's local deadline) and marks them expired
- `src/DataFixtures/` — Task types, sample tasks, users with differing timezones for dev and tests
- `migrations/` — Doctrine migrations including seed of default task types
- `templates/` — base layout, task list, my tasks, profile, error pages (403/404/500), timezone-detect Stimulus snippet
- `assets/controllers/timezone_controller.js` — Stimulus controller: detects Intl timezone, sends to server if it differs from the stored one; formats UTC timestamps into local time
- `tests/Unit/` — Unit tests: UserClock (Monday week boundaries, DST, tz edge cases such as UTC+14 and UTC-12), state machine, PointsCalculator, AuditLogger diff, TimezoneResolver
- `tests/Integration/` — Integration tests with a real test DB: repositories, workflow service transactions, concurrent claim, audit subscriber, expire command
- `tests/Functional/` — WebTestCase for controllers: auth and authorization, admin access, error pages, validation failures, CSRF
- `phpunit.xml.dist` — Test suites (unit, integration, functional), coverage config
- `phpstan.neon / .php-cs-fixer.dist.php` — Static analysis and code style
- `docs/DECISIONS.md` — Open questions log: recurrence mechanics, reward definition, week and timezone behavior, API auth
- `README.md` — Setup, run, test, logging and metrics instructions

## Tasks

- [x] Phase 0 – Brainstorm and lock decisions in docs/DECISIONS.md. Timezone: store all instants as UTC DATETIME(6); each user has an IANA timezone (not a fixed offset) so DST is handled; auto-detect via Intl in JS, store on first login and let the user override; fall back to the app default if invalid. The week starts Monday 00:00 in the user's local timezone, and each task_user stores week_key and a user_timezone snapshot so history stays stable if the user later moves. Deadlines are computed from the local wall-clock time and converted to UTC at creation, handling nonexistent and ambiguous DST times explicitly. Open questions: does a household-wide leaderboard use per-user weeks or a single household timezone? Do recurring tasks regenerate per user or per household?
- [x] Scaffold the Symfony project (skeleton + webapp pack), then add the orm, security, validator, monolog, twig, UX, EasyAdmin, and dev and test tools. Init git, .gitignore, and the Makefile.
- [x] Create docker-compose.yml with MySQL 8, a healthcheck, and dev and test databases. Configure DATABASE_URL, UTF8MB4, strict SQL mode, and the UTC connection timezone.
- [ ] Model entities, enums, and relations with Doctrine attributes. Add DB constraints: unique email, FKs with deliberate ON DELETE behavior (soft-delete or restrict for tasks and users with history), indexes on (user_id, status), (week_key), and (task_id). Add a partial-uniqueness strategy to prevent duplicate active claims where required. Generate migrations and seed the task types.
- [ ] Implement authentication: user registration or admin-created users, password hashing, form login, roles, a login throttler, and the CreateAdminCommand. Reserve an API firewall for later token or JWT auth.
- [ ] Implement the time services (UserClock, TimezoneResolver) test-first, then the timezone Stimulus controller and the endpoint that persists the detected zone with validation and a CSRF check.
- [ ] Implement the TaskWorkflowService with the status state machine (pending to in_progress to completed, plus cancelled and expired), transactional operations, and pessimistic or optimistic locking against double-claims and double-completes. Handle edge cases: completing a task not owned, starting a completed task, an inactive or deleted task, a parent_task that is cyclic or belongs to another user, a completion before the start time, and clock skew.
- [ ] Implement PointsCalculator as an interface with a simple default, and WeeklySummaryService to aggregate points by user-local week, leaving the reward definitions pluggable for later.
- [ ] Build the admin UI with EasyAdmin: CRUD for Task and TaskType (types addable at runtime), user management, a read-only AuditLog view with filters, and ROLE_ADMIN access control. Admins create tasks without approval; the DoctrineAuditSubscriber records every create, update, and delete, with a before/after diff, the actor, and the request ID.
- [ ] Build the user-facing web UI: the list of available tasks sorted by urgency, claim, start, and complete actions, my tasks, adding a custom task with priority and reward value (validated ranges), a weekly points view, and a profile page with timezone. Display all times in the user's local timezone.
- [ ] Implement central error handling: a domain exception hierarchy, an ExceptionSubscriber that maps exceptions to status codes, custom Twig error pages, JSON error format ready for the API (RFC 7807 problem+json), form and validation error rendering, CSRF failures, 403 and 404 for foreign resources, DB deadlock retry, and a safe generic 500 without leaking internals.
- [ ] Add logging: Monolog JSON output with channels (app, audit, security, performance), a request-ID and user-ID processor, a consistent log level policy, no PII or secrets in logs, and log rotation in prod.
- [ ] Add performance metrics: MetricsSubscriber (request time, memory, query count), business counters, a slow-query and slow-request warning threshold, a /metrics endpoint restricted to admins or an internal network, and the Symfony profiler in dev.
- [ ] Implement ExpireOverdueTasksCommand plus a cron or Symfony Scheduler entry. It must be idempotent and safe to run concurrently, and it logs a summary.
- [ ] Write the tests. Unit: clock and week boundaries (Monday cutoffs across timezones, DST transitions, year-end ISO weeks), state transitions, points, and the audit diff. Integration: against the MySQL test DB with transaction rollback, repository queries, workflow with concurrency, the audit subscriber, and the expire command. Functional: auth and authorization matrix, admin CRUD with audit rows, validation errors, and error pages. Run phpunit --testsuite per layer and track coverage.
- [ ] Add PHPStan (level 6+) and PHP-CS-Fixer, and wire up the Makefile targets. Optionally add a GitHub Actions workflow with a MySQL service that runs the static analysis and all test suites.
- [ ] Prepare the REST API (deferred): keep the controllers thin and have all logic in services, with DTOs and a serializer groups strategy. Pick the auth approach later (LexikJWT or Symfony access tokens), and add API Platform or hand-written /api/v1 controllers, OpenAPI docs, and rate limiting.
- [ ] Write the README and finalize docs/DECISIONS.md, then do a review pass on the open questions (recurrence mechanics, reward definitions) before iteration 2.
