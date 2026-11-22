# 1326 — Flow card progress in elapsed time from the matched job start date

## Context

Flow cards in production (#1308) and the invention view (#1317) fill their progress by finished runs because the front never receives the start date of a matched job. `industry_project_job_matches.start_date` already exists (migration `Version20261110010000`), is copied from the ESI job on every sync (`IndustryProjectJobMatch::copyJobState`) and is read by `ProjectJobGroup::startedAt()`. It is only missing from `ProjectJobMatchResource`, so the BOM payload (`jobMatches[]`) never carries it. No migration is needed (decision, see below).

## Rules

1. `ProjectJobMatchResource` exposes `startDate` (ISO 8601 `c` format, `null` when the match has no stored start date). It travels in `jobMatches[]` of the BOM line through `get_object_vars`. The front `JobMatch` type gains `startDate?: string | null` (absent = unknown).
2. Elapsed share of a job (`jobElapsedShareOf(job, now)` next to `jobDisplayOf`), a number from 0 to 1 or `null`:
   - display state `ready` or `delivered`: 1, whatever the start date;
   - display state `running` or `paused`: `(now - start) / (end - start)` clamped to 0..1; `null` when start or end is missing, unparsable, or `end <= start`;
   - any other state (`cancelled`, `reverted`, `unknown`): `null` (callers ignore these jobs, as today).
3. Flow card in production (`progressOf` in `projectFlow.ts`): progress = sum over the non-ignored matched jobs of `runs x share`, divided by the line `runs`, as a percent rounded to an integer.
   - A running or paused job whose share is `null` makes the card progress `null` (no bar), never 0.
   - Runs of the line with no matched job count as 0 of the progress.
   - The progress never shows 100 while a matched job still runs: running jobs cap the value at 99. 100 (and `ready`) only when finished runs cover the line runs.
   - Line with no runs or `runs = 0`: `null` (unchanged). `out` keeps 100, non-job lines and other states keep `null`.
   - `remainingMs` unchanged.
4. Invention view (`projectInventionView.ts`): for a running or paused job, the first `floor(share x runs)` dots are `busy` and the remaining ones `empty`; a running or paused job with a `null` share gives `unknown` dots; a `ready` job keeps all dots `busy`; delivered jobs unchanged.
5. Companion job row (`GroupCompanion.vue`, `companion-job-bar`): a running or paused job shows a bar filled at its share (the full green bar for `ready` stays); `null` share shows no bar.

## Examples (become the red tests)

| Case | Input | Output |
|---|---|---|
| Resource | match with start `2026-11-22T10:00:00Z` | `startDate = '2026-11-22T10:00:00+00:00'` |
| Resource | match without start | `startDate = null`, key present |
| Share | active, start 10:00, end 14:00, now 12:00 | 0.5 |
| Share | active, now before start | 0 |
| Share | active, end passed | 1 (ready) |
| Share | active, start missing | `null` |
| Share | active, end equal to start | `null` |
| Share | delivered, start missing | 1 |
| Share | cancelled | `null` |
| Card | one active job of 10 runs on a 10-run line, half elapsed | progress 50 |
| Card | same job at 99.7 % elapsed | progress 99, not ready |
| Card | delivered 4 runs + active 6 runs at 50 % on a 10-run line | 70 |
| Card | active 10 runs start unknown | `null` |
| Card | delivered 4 runs, nothing else, line of 10 | 40 |
| Card | active job just started (start = now) | 0, bar present |
| Invention | active 20-run job, 50 % elapsed | 10 busy + 10 empty |
| Invention | active job, start unknown | all `unknown` |
| Companion | running row half elapsed | bar at 50 %; unknown start: no bar |

## Edge cases

- `paused`: same formula as `running` (the existing remaining time already ignores the pause).
- `startDate` unparsable on the wire: treated as unknown.
- Matches created before 2026-11-10 may have no start date until the next sync refreshes them: shown as unknown, not 0.

## Tests existing and contradicted

- `projectFlowCardDetails.spec.ts` "shows an empty bar, not an absent one, while no run is finished": the active job carries no start date, so the bar becomes unknown; fixture gets a start date, assertion stays 0.
- `projectInventionView.spec.ts` "fills the squares of a running job..." and "gives no total while one of the jobs is still running": running jobs without start date now give `unknown` dots; fixtures get a start date, expectations follow rule 4.
- Other specs found by grep on `status: 'active'` job fixtures in `frontend/tests` are updated the same way, never weakened.

## Access and freshness

No new endpoint and no new access path: same provider, same viewer scoping as `endDate`, which is already exposed to accepted members (#1312).

## Out of scope

Migration or backfill of `start_date` (column exists, sync fills it), the other consumers of `ProjectRunningJob.startedAt`, ESI pause date, PI.

## Decisions

- The issue asks for a migration; the column already exists and the issue comment of 2026-11-22 asks to check first. Exposing it is enough, no migration, nothing to run in production.
- Card progress is run-weighted across jobs, because a line can be split in several jobs of different durations.
- Unknown start = no bar (`progress: null`), the representation already used for unknown runs.
- Glossary: entry "Carte de flux" updated (progress in elapsed time).
