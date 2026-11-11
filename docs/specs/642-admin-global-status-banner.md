# #642 Admin global status banner

## Contexte
The admin page shows about 25 tiles; answering "is something broken now?" means scanning them all. A banner at the top gives one level (`ok`, `degraded`, `down`) and one line per fired rule, each linking to its section.

## Règles
1. `GET /admin/status` (admin only, same access check as `/admin/stats`) returns `level` and `rules`. The backend evaluates the rules (`AdminStatusEvaluator`); Vue only renders.
2. A rule is returned only when it fires. Each rule has `key`, `severity` (`degraded` or `down`), `value`, `threshold` (nullable) and `section` (`scheduler-health`, `maintenance` or `kpis`).
3. `level` is `down` if any fired rule is `down`, else `degraded` if any fired, else `ok`. Rules come in a fixed order: scheduler, queues, tokens.
4. `scheduler_stale`: scheduled entries (not on-demand) whose health is `stale`; `value` is the count; degraded.
5. `scheduler_error`: scheduled entries whose status is `error`; `value` is the count; degraded. On-demand entries never fire a scheduler rule.
6. `failed_messages`: failed depth above `FAILED_MESSAGES_THRESHOLD` (0); degraded; section `maintenance`.
7. `async_queue_backlog`: async depth above `ASYNC_QUEUE_DEGRADED_DEPTH` (1000) is degraded, above `ASYNC_QUEUE_DOWN_DEPTH` (10000) is down; `threshold` is the one crossed; section `maintenance`. The boundary value itself does not fire.
8. `async_queue_unreachable` (depth unknown, down) and `failed_queue_unreachable` (depth unknown, degraded): an unknown depth is never read as zero.
9. `tokens_invalid`: users with at least one character in invalid auth status (`users.invalid`), above 0; degraded; section `kpis`.
10. Frontend: `AdminStatusBanner` at the top of the dashboard, level label, one clickable line per rule that scrolls to `admin-section-<section>`, refreshed with the other admin data (`fetchAll`) and after a sync.

## Décisions
- Expired access tokens are not a rule: they expire every 20 minutes by design and would fire permanently. The tokens to repair count comes with #648.
- Thresholds are named constants on `AdminStatusEvaluator`.

## Hors périmètre (prérequis absents de la base)
- No consumer on the async queue (#643 absent).
- ESI error budget low (#645, PR #685 not merged).
- Mercure cookie near limit (#649, PR not merged).
- External alerting.
