# #1247 Link a job automatically only when one project clearly wins

Circuit M. Spec validated in autonomy (night mode). Builds on #1246 (scored pass). Code: `src/Industry/Domain/JobMatching/JobMatchPlanner.php`, `src/Service/Industry/IndustryJobMatcher.php`. Same rule as `AutomaticPurchaseLinker` (#750, #1100): link only when nothing is ambiguous, otherwise a suggestion.

## Problem

Since #1246 a job that fits several projects always goes to the best pair, and a tie is broken by project age. The player is never told that the choice was a guess.

## Rules

1. The planner decides per job when the job is taken by the greedy loop of #1246 (rule 6), on the pairs that still fit the current remainders. For each project holding at least one fitting pair, its best pair is kept (score, then line id).
2. Decisive score of a pair: its score without `exact_fit` (weights `intent` 8, `place` 4, `structure` 2).
3. A job is linked automatically when it has fitting pairs in one project only, or when the highest decisive score among projects is strictly above the second highest. The job then goes to the best pair as in #1246 (same signals, same remainder handling).
4. Otherwise (two or more projects with the same highest decisive score, including zero) the job is not linked, no `IndustryProjectJobMatch` is created, and the job is deferred: it takes no remainder and is not reconsidered in the pass. Runs (`exact_fit`) and project age never decide between two projects.
5. Candidates of a deferred job: every pair that fits, sorted best first with the #1246 order (score, project `createdAt`, project id, line id). Each candidate carries the project id, the line id, the score and the signals (`exact_fit` included when the runs equal the remainder).
6. A job that fits no line but is eligible for lines that still have a remainder (its runs are above that remainder, the "one bigger job" case) is deferred as well, with those lines as candidates flagged `oversized` (score and signals from the static signals, never `exact_fit`). It is never linked automatically. A job with no eligible pair at all produces nothing, as today.
7. `JobMatchPlanner::plan` returns a `JobMatchPlan` with the `assignments` (as today) and the `unassignedJobs` (`JobToAssign`: job id, `list<AssignmentCandidate>`), both deterministic and independent of the input order.
8. Storage: new entity `UnassignedJob` (table `industry_unassigned_jobs`): one row per job (OneToOne `CachedIndustryJob`, unique, `onDelete CASCADE`), `candidates` json (list of `projectId`, `lineId`, `score`, `signals`, `oversized`), `detectedAt`. Reversible migration (`CREATE TABLE` / `DROP TABLE`), nothing to transform in production.
9. `IndustryJobMatcher::matchOpenProjects` persists the deferred jobs: a job already stored keeps its `detectedAt` and gets the new candidates; a new one is created. In the same flush it removes the stored rows of every job of the pass that is now linked, of every job without output (`hasNoOutput`) and of every job linked to a project, so a job leaves the state when it ends without output or when it is linked.
10. `IndustryJobMatcher::linkJobToBomLine` (manual link, used by `LinkJobProcessor` and the future one-click assignment) removes the stored row of the job.
11. Jobs not eligible anywhere, and jobs of the passes where a project failed to release, behave as in #1246. The `Match jobs` button, Mercure events and API are unchanged: the list, its counter and the dismissal are #1248.
12. Glossary: new term "Job à attribuer" / "Unassigned job"; "Job lié" and "Passe de rattachement" mention the clear-winner gate. `docs/modules/industry.md` rows `IndustryJobMatcher` and jobs of a project mention it.

## Examples

| Situation | Result |
|---|---|
| One project with a fitting line | Linked |
| Projects A and B, same blueprint, job of 5 runs, no signal on either | Unassigned, 2 candidates (A first, older) |
| Same, only A has `exact_fit` (remainder 5, B 8) | Unassigned: `exact_fit` is not decisive |
| Same, job output in B's container, A's scope unknown | Linked to B (`place` 4 against 0) |
| Same, both have the container, only B plans the job station | Linked to B (`place`+`structure` 6 against 4) |
| A and B both `place` and `structure` | Unassigned |
| Two lines of the same project, nothing else | Linked to the best line of that project (not ambiguous between projects) |
| A and B tied, B's line is filled by an earlier exact job of the pass | The job then has one project left: linked to A |
| Job 12 runs, A line remainder 10, B line remainder 6 | Unassigned, 2 candidates `oversized` |
| Job 12 runs, only A with a remainder 10 | Unassigned, 1 candidate `oversized` |
| Unassigned in sync 1, job linked by hand | Row removed |
| Unassigned in sync 1, job cancelled in sync 2 | Row removed |
| Unassigned in sync 1, same candidates in sync 2 | One row, same `detectedAt` |

## Cases and limits

- #1125 (blueprint in another project container) stays an eligibility filter: a filtered pair is not a candidate.
- Deferred jobs are decided at pick time, so an earlier link of the pass can turn an ambiguous job into a single-project one (table above). Same input, same output.
- Candidate lists are not filtered by user rights: #1248 filters them when it reads them.
- A row whose candidates point to a project that later completes is not cleaned by this issue: the next pass replaces or removes it when the job is still a candidate or linked (#1248 filters closed projects).

## Tests expected

Pure planner tests for rules 1 to 7 (decisive tie, strictly above, single project, same-project lines, oversized, order independence, deferred job takes no remainder). Matcher tests for rules 8 to 10 (stored, kept `detectedAt`, removed on link, on no output, on manual link). Existing #1246 tests that expect a tie to go to the oldest project are inverted.

## Out of scope

The list, the counter, the dismissal and the one-click assignment (#1248), a dedicated character (#1249), shared jobs (#1250), copy and invention jobs (#1193), any front change.

## Decisions taken

- Oversized jobs become candidates only when no line fits (a fitting line is always a better proposal).
- Ambiguity is by project, not by line: two lines of one project are the player's business inside the project.
- The row is tied to the cached job (OneToOne, cascade) rather than an ESI id: no orphan rows when a character or its jobs are purged.
