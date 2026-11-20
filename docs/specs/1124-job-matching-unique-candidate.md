# #1124 A job is linked automatically only when one project fits it, suggested otherwise

Circuit m, mode nuit. Stacked on #1123 (PR #1127). Rule from the "Decision (2026-11-20)" comment of the issue, same logic as `AutomaticPurchaseLinker` (#750). Prerequisites checked on the #1123 branch: `IndustryJobMatcher::matchEsiJobs`, `releaseAutomaticMatches`, `OpenProjectsJobMatcher::matchOpenProjectsOf`, `IndustryProjectJobMatchRepository::findEsiJobIdsMatchedToOtherProjects`, `AvailableJobsProvider` + `JobLinkModal.vue` (manual link, lists every unlinked job of the blueprint, oversized included).

## Rules

1. Automatic matching only links a job whose runs fit the remaining runs of the line. The oversized fallback (second pass of `matchJobsToLine`, one job larger than the remaining runs) is removed: an oversized job stays unlinked.
2. A **contested job** is a job that fits a line of at least two candidate projects. A contested job is never linked automatically, in any project. A job that fits one candidate project only is linked there.
3. Candidate projects: the open projects passed to `IndustryJobMatcher::contestedJobIds()`, minus `Draft` (#1123). Candidate lines: job lines of a project without a manual match (lines holding a manual match keep their jobs and are not recomputed). Per project, "fits" is simulated exactly as the real matching: same characters (owner and accepted members), same start filter (`created_at`), jobs without output ignored, jobs held by projects outside the candidate set or by the manual lines of the project unavailable, greedy on the remaining runs shared across the lines of the project.
4. Contestation is a fixed point: once a contested job is set aside, the remaining runs of a line grow and another job may now fit two projects; the computation repeats with the contested jobs excluded until no new contested job appears. The result does not depend on the order of the projects.
5. `IndustryJobMatcher::matchEsiJobs(IndustryProject, array $contestedJobIds = [])`: contested jobs are skipped like jobs held by another project; everything else is unchanged.
6. `OpenProjectsJobMatcher::matchOpenProjectsOf`, order: (a) drafts released (#1123); (b) the automatic matches of every non-draft project released (a failing release is logged and that project is skipped for the run); (c) `contestedJobIds()` over those projects, once; (d) each of them matched with the contested jobs, then its jobs recorded and its lines refreshed, as today. A failure on one project is logged and the others are still processed.
7. `MatchJobsProcessor` (owner button): the contested jobs are computed over the open projects of the user plus the project being matched, and passed to `matchEsiJobs`. Its behaviour is otherwise unchanged.
8. The suggestion is the existing manual link: a job that is not linked automatically stays in the list of available jobs of each candidate project (`AvailableJobsProvider`), and the owner links it with `linkJobToBomLine` (`manual = true`). No endpoint, no field, no migration, no front change.

## Examples

Formula Ishtar, jobs started after both `created_at`, same owner.

| Projects (line runs) | Jobs (runs) | Result |
|---|---|---|
| A 2, B 5 | J1 5 | J1 fits B only: linked to B, whatever the order; A gets nothing (was: oversized fallback of A) |
| A 5, B 5 | J1 5 | contested: linked nowhere, listed as available in A and in B |
| A 6, B 4 | J1 6, J2 4 | J1 fits A only, J2 fits B only (J1 > 4): J1 to A, J2 to B |
| A 10, B 6 | J1 6, J2 4 | J1 fits both: contested; then J2 fits both (A has 10 left): contested; nothing linked |
| A 5 alone | J 3, J 7, J 8 | J 3 linked; J 7 and J 8 unlinked (was: J 7 linked) |
| A 5 in progress, B 5 draft | J1 5 | B is not a candidate (#1123): J1 linked to A |
| A with a manual match on its line, B 5 | J1 5 | A line not a candidate: J1 linked to B |

## Edge cases

- A job fitting two projects of two different users who do not share a project is not seen as contested from one user's sync (the candidate set is the projects of the syncing user); the next sync of the other user releases and recomputes. Open question, not decided tonight: widen the candidate set to every open project that includes the job installer.
- Matches already stored by the previous behaviour (oversized jobs, jobs on the wrong project) are released at the next sync by step 6b and recomputed; no data to restore and no console command.
- A line without runs, or whose producer is missing from the SDE, is reported as before (`LineMatchFailure`) during the matching and ignored by `contestedJobIds`.

## Out of scope

- The project container as match input (#1125).
- Showing "contested" or "suggested" on the available jobs list (the list already shows all unlinked jobs).
- The manual `Match jobs` action on a draft (open question of #1123).
