# REST API (deferred, prepared)

Status: **groundwork only**. `GET /api/v1/ping` exists; everything under `/api/` returns RFC 7807
`application/problem+json` (401 when anonymous, 404 for unknown routes).

## Design rules
- Controllers in `src/Controller/Api/` stay thin: parse input, call a service, return a DTO.
- All business rules live in `App\Service\*` (`TaskWorkflowService`, `WeeklySummaryService`); the web
  controllers and the API share them, so behaviour and error mapping are identical.
- Output uses read DTOs in `src/Dto/` (e.g. `TaskView`), never Doctrine entities. Keys are snake_case, instants are ISO-8601 UTC.
- Errors: `ExceptionSubscriber` already renders problem+json for `/api/*`.

## Planned endpoints (v1)
| Method | Path | Service call |
|---|---|---|
| GET | /api/v1/tasks | `TaskRepository::findAvailable` |
| POST | /api/v1/tasks/{id}/claim | `TaskWorkflowService::claim` |
| POST | /api/v1/my/{id}/start, /complete, /cancel | `start`, `complete`, `cancel` |
| GET | /api/v1/my | `TaskUserRepository::findOpenForUser` |
| GET | /api/v1/week | `WeeklySummaryService::currentWeek` |

## Open choices (decide in iteration 2)
- Auth: Symfony `access_token` (opaque per-user tokens, simplest) vs LexikJWT. The `api` firewall is stateless and ready; enable the commented `access_token` block in `security.yaml`.
- Docs: NelmioApiDoc/OpenAPI vs API Platform (hand-written controllers preferred so services stay central).
- Rate limiting: `symfony/rate-limiter` per token, mirroring the login throttler.
