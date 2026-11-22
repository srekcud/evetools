# #1248 Cross-project list of unassigned jobs with one-click assignment

Circuit M. Spec validated in autonomy (night mode). Builds on #1247 (`UnassignedJob`, `IndustryJobMatcher::recordUnassignedJobs`). Rights follow #1194 (a member links their own jobs). Prototype: none shows this list; the companion keeps its structure (#1171), so the screen is a section of the projects page and an alert of the companion (see Decisions).

## Problem

Since #1247 an ambiguous job is stored as an unassigned job with its candidate lines, but nobody can see or settle it: the only manual path is "Link job" inside one project.

## Rules

1. `GET /api/industry/unassigned-jobs` (new ApiResource `UnassignedJobResource`, collection, `ROLE_USER`) lists the unassigned jobs of the current user: stored rows whose job belongs to a character of the user, not dismissed, not without output. Newest `startDate` first.
2. Each item: `esiJobId` (identifier), `characterName`, `blueprintTypeId`, `productTypeId`, `productTypeName`, `runs`, `activityId`, `stationId`, `stationName` (nullable, via `IndustryCalculationService::resolveFacilityName`), `startDate`, `endDate`, `status`, `candidates`.
3. A candidate is a stored candidate (best first, stored order kept) that is still valid at read time: the line exists and is a manufacturing or reaction job line, its project is not `completed` or `draft`, and the user is the owner or an accepted participant of the project (`ProjectAccessChecker`). Candidate fields: `projectId`, `projectName`, `lineId`, `lineName`, `remainingRuns` (line runs minus matched runs, never below 0), `score`, `signals` (values of `MatchSignal`), `oversized`. A job with no valid candidate left is not listed.
4. `POST /api/industry/unassigned-jobs/{esiJobId}/assign` body `{lineId}` links the job to the line and answers with the created `ProjectJobMatchResource` (201). It fails with 404 when the job is not an unassigned job of one of the user's characters (also for a dismissed job), 400 when `lineId` is not one of its valid candidates, and with the same errors as `LinkJobProcessor` for the other rules (activity, blueprint, no output, already linked).
5. The checks of rule 4 and the link itself are not duplicated: extract from `LinkJobProcessor` a service `ManualJobLinker` (activity, blueprint, no output, already linked, then `IndustryJobMatcher::linkJobToBomLine`, then `DerivedContributionService::refreshLine`). `LinkJobProcessor` keeps its access checks and delegates to it, behaviour unchanged. The assignment adds `ProjectJobActivityRecorder::recordJobsOf($project)` (activity feed and Mercure project event). The link is manual (`manual = true`, no signals), the stored row is removed by `linkJobToBomLine` (#1247 rule 10).
6. `POST /api/industry/unassigned-jobs/{esiJobId}/dismiss` (`EmptyInput`) marks the job "not for a project": 204, 404 when it is not an unassigned job of the user's characters. The row stays with `dismissedAt` set (new nullable column `dismissed_at`, reversible migration `ADD COLUMN` / `DROP COLUMN`, nothing to transform in production).
7. A dismissed job is never proposed again and never linked automatically: `IndustryJobMatcher::matchOpenProjects` skips the jobs whose row is dismissed (not eligible, not retired, so the row is neither rewritten nor removed by `recordUnassignedJobs`). The row is still removed by the existing cleanups (job without output, job deleted by cascade). A manual "Link job" of a dismissed job stays possible and clears its row as today.
8. Scope: only the user's own characters, in every rule (the owner does not assign the jobs of other participants here). Nothing about another user is returned. The anonymized or deleted user case behaves as in `AvailableJobsProvider` (no characters, empty list).
9. Counter: the frontend store `useUnassignedJobsStore` (`stores/group-industry/unassignedJobs.ts`) loads the list, exposes `jobs` and `count`, `assign(esiJobId, lineId)` and `dismiss(esiJobId)` (reload after each, error exposed, no silent catch). It reloads when the existing Mercure sync `industry-jobs` completes (`useSyncStore().getSyncProgress('industry-jobs')`, the match runs before the completion message) and on reconnection (`useRefetchOnReconnect`). No new Mercure topic: assign and dismiss are synchronous.
10. Page: `GroupIndustryList.vue`, tab "my projects", gets a section `UnassignedJobsPanel.vue` above the project cards, visible only when `count > 0`, titled "Jobs to assign (N)". One card per job (HUD style, activity colour of the companion palette): character, blueprint and product, runs, station, start and end date, then the candidate lines best first (project, line, remaining runs, signals as chips, "oversized" flag) each with a button "Assign" (one click, no confirmation), and a ghost button "Not for a project". Loading, empty (section hidden) and error states. No tabs, no table.
11. Companion: `buildCompanionTasks` takes `unassignedJobCount` (number or null while unknown) and adds an alert `jobs_to_assign` when it is above 0 (lowest priority, amber like `join_request`); the alert text "N jobs to assign" has a button "Open" that goes to the projects page (`industry-projects`, tab my projects). `GroupCompanionHost` reads the count from the store; the companion reloads it on project events (`handleEvents`) so an assignment made from the page updates it.
12. Labels in FR and EN (`groupIndustry` keys of both locale files): section title, "Assign", "Not for a project", "oversized", signal names, alert text with plural, "Open". Help tables untouched (no scope, no scheduled task).
13. Glossary: "Job à attribuer" gets the list, the assignment and the dismissal ("Pas pour un projet"); `docs/modules/industry.md` row for `IndustryJobMatcher` and a row for the new resource, provider, processors and `ManualJobLinker`.

## Examples

| Situation | Result |
|---|---|
| Job unassigned between A and B, user owner of A and accepted in B | Listed, 2 candidates, best first |
| Same, user not a participant of B | Listed with A only |
| Same, A completed, B draft | Not listed |
| Assign to A's line | Match created manual, row removed, line refreshed, activity feed entry, list loses the job |
| Assign with a line of B that is not a candidate | 400 |
| Assign a job of another user's character | 404 |
| Assign twice | second call 404 (row removed) |
| Dismiss, then a new sync with the same ambiguity | Job never listed, never linked, row kept |
| Dismissed job, later linked by hand through "Link job" | Linked, row removed |
| Count 3 then one assignment | Panel shows 2, companion alert says 2 |
| Count 1 then job ends without output at the next sync | Row removed, count 0, section and alert disappear |

## Cases and limits

- Candidates are those of the last pass: a line filled since then is still offered until the next pass (the processor does not check the remainder, as `LinkJobProcessor`).
- Reading is per user: the list never mixes the rows of two users, even for a job a participant's project also sees.
- The count is the number of listed jobs (after candidate filtering), not the number of rows.

## Tests expected

Unit: candidate filtering (rules 3, 8), `ManualJobLinker` rules, assign and dismiss processors (rules 4, 6), matcher skip of dismissed jobs (rule 7), existing `LinkJobProcessorTest` adapted to the extracted service. Integration: repository (dismiss, listing by user). Functional: the three endpoints (access, 404, 400, success, no leak across users). Frontend: store (load, reload on sync completion, assign, dismiss, error), panel (render, assign click, dismiss click, hidden at 0), `buildCompanionTasks` alert, companion alert text.

## Out of scope

A dedicated character (#1249), shared jobs (#1250), copy and invention jobs (#1193), the owner assigning other participants' jobs, undoing a dismissal, the final placement of the list in the flow page (#1307 and following), Link job availability (#1199).

## Decisions taken

- No prototype screen exists for this list: it sits on the existing projects page, in the style of the prototype cards, until the flow page replaces it (deviation noted for the final review).
- Dismissal is a column on the existing row, not a new table: one job, one owner, and the row already follows the job (cascade, removal when it ends).
- A dismissed job leaves the matching pass entirely: "Not for a project" means no automatic link either.
- The assign endpoint only accepts stored candidates, so a client cannot attach a job to an arbitrary line through it (the generic "Link job" stays for that).
