# Decisions & Open Questions

Status legend: **LOCKED** (implemented), **OPEN** (to discuss before iteration 2).

## Time & timezones (LOCKED)

- All instants are stored as UTC in `DATETIME(6)` columns (`DateTimeImmutable`, custom `utc_datetime_immutable` type).
- Every user has an **IANA timezone** (e.g. `Europe/Vilnius`), never a fixed offset, so DST is handled by the tz database.
- Auto-detection: the browser's `Intl.DateTimeFormat().resolvedOptions().timeZone` is POSTed (with CSRF token)
  to `/profile/timezone` by the Stimulus controller when it differs from the stored value.
  The user can override it on the profile page. An explicit manual override (`timezone_manual = true`) stops auto-updates.
- Fallback chain: user setting -> `tz` cookie -> `APP_DEFAULT_TIMEZONE` (default `UTC`). Invalid identifiers are rejected (400) and never persisted.
- **Week** starts Monday 00:00 *local* time. `week_key` is the ISO week of the user's local date, e.g. `2026-W45`.
  Each `task_user` stores `week_key` and a `user_timezone` snapshot at creation so history is stable if the user later moves.
- **Deadlines** are given as local wall-clock time and converted to UTC at creation.
  - Nonexistent local time (spring-forward gap): shifted forward by the gap length (PHP default behaviour, asserted in tests).
  - Ambiguous local time (fall-back overlap): the **first** (earlier, DST) occurrence is used.
- Expiry compares `deadline_at` (UTC) to `now` (UTC); the local deadline was already resolved at creation.

## Roles & audit (LOCKED)

- `ROLE_USER`, `ROLE_ADMIN` (admin inherits user). Admins create/modify tasks without approval.
- Every create/update/delete of `Task`, `TaskType`, `User` is recorded in `audit_log`
  (actor, entity, action, before/after diff, IP, request id). Audit rows are append-only; password hashes are never logged.

## Tasks & claims (LOCKED for iteration 1)

- `Task.recurrence_kind`: `one_off` (becomes inactive after first completion), `reusable` (may be claimed repeatedly),
  `recurring` (stored; scheduling mechanics deferred).
- A user may hold only one *open* (pending/in_progress) claim per task (enforced in service + DB via generated `active_key` unique column).
- `parent_task_user_id` must belong to the same user, must not be cyclic, and must not be itself.
- Status machine: `pending -> in_progress -> completed`; `pending|in_progress -> cancelled|expired`. Terminal states are final.
- Points: `reward_value x priority multiplier` (low 1, medium 1.5 -> stored in integer tenths, high 2, urgent 3). Bonuses deferred.

## Open questions (OPEN)

1. **Leaderboard weeks:** per-user local weeks or a single household timezone? (Currently per-user week_key; household
   comparison across timezones would need a household tz setting.)
2. **Recurring tasks:** regenerate per user or per household? Who gets assigned? What cadence format (RRULE vs simple presets)?
   `Task.recurrence_config` JSON is reserved.
3. **Rewards:** what are they, when are they granted, are they claimable? `WeeklyResult` is a placeholder.
4. **User-created tasks:** may regular users set any reward value? Currently capped at 1..100 and flagged by `created_by`.
5. **API auth:** Symfony access tokens vs LexikJWT; the `api` firewall stub is reserved (stateless).
6. **Approvals:** non-admin tasks are currently active immediately; do we want moderation later?
7. **Data retention:** soft-delete (`is_active=false`) is used; deleting users with history is blocked (RESTRICT).
