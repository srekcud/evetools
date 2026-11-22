# #1249 Dedicate a character to an active project for job matching

Circuit M. Spec validated in autonomy (night mode). Builds on #1246 and #1247 (scored pass, clear-winner gate). Code: `src/Industry/Domain/JobMatching/JobMatchPlanner.php` (`staticSignalsOf`), `CandidateProject`, `src/Service/Industry/IndustryJobMatcher.php` (`matchOpenProjects`, `producerInstallerIdsOf`), `src/Entity/Character.php`, `src/ApiResource/Me/CharacterResource.php`, `CharacterCollectionProvider`, `UpdateProjectProcessor`, `LeaveProjectProcessor`, `KickParticipantProcessor`. Front: `frontend/src/components/industry/SkillsConfig.vue` (character list of the industry settings), `frontend/src/views/GroupIndustry/GroupIndustryDetail.vue` (project page), `frontend/src/components/group-industry/ProducerCharactersSelect.vue` (pattern for loading `/me/characters`). Map: `docs/modules/industry.md`.

## Problem

`IndustryProject::$producerCharacterIds` (#1000) is set by the project owner. A participant cannot say "this character works for project A until further notice", although that is the strongest matching signal when several projects run in parallel.

## Vocabulary

**Active project** (EN) / **Projet actif** (FR): the open project a user dedicated one of their characters to. One per character, nullable. Distinct from **Personnage producteur** (chosen by the owner for a project): the producing character answers "which characters may the planning use in this project", the active project answers "where does this character's work go". Both feed the `intent` signal. Glossary entry added in the same commit as the code.

## Rules

1. Storage: nullable column `characters.active_project_id`, `Character::$activeProject` (ManyToOne `IndustryProject`, `onDelete: SET NULL`), `getActiveProject()` / `setActiveProject(?IndustryProject)`. Reversible migration (`ADD` / `DROP COLUMN` and foreign key). Existing rows stay null, nothing to transform.
2. Eligibility to dedicate: the character belongs to the caller; the project is one of `IndustryProjectRepository::findOpenOfAcceptedParticipant($caller)` (not completed, caller accepted participant, owner included). Anything else: 404 (no leak of other projects).
3. Service `App\Service\Industry\Project\CharacterActiveProject`:
   - `dedicate(User, Character, ?IndustryProject)`: applies rule 2 and sets or clears (`null`), flushes;
   - `clearForProject(IndustryProject)`: nulls the active project of every character pointing at it;
   - `clearForParticipant(IndustryProject, User)`: nulls it on the user's characters pointing at that project.
4. Clearing: `UpdateProjectProcessor` calls `clearForProject` when the status becomes `completed`; `LeaveProjectProcessor` and `KickParticipantProcessor` call `clearForParticipant` for the user who leaves or is removed; project deletion is covered by `SET NULL`. A completed project is never an active project.
5. API (API Platform, no classic controller): new resource `CharacterActiveProjectResource` (`id`, `activeProjectId`, `activeProjectName`, both nullable) with `PATCH /me/characters/{id}/active-project`, `application/merge-patch+json`, input `SetCharacterActiveProjectInput {projectId: string|null}`, `uriVariables` with `Link`, provider `CharacterActiveProjectProvider` (the resource of the caller character, 404 for another user's character), processor `SetCharacterActiveProjectProcessor`, output the updated resource (200). `GET /me/characters` (`CharacterResource`) gets the same two read-only properties `activeProjectId` and `activeProjectName`. Invalid uuid or unknown or ineligible project: 404. Missing `projectId` key: 400.
6. Matching, in the scored pass (`matchOpenProjects`):
   - `CandidateProject` gets `dedicatedInstallerIds` (list of installer ids, default `[]`): the characters of the users whose jobs are matched for the project (`usersWhoseJobsAreMatched`) whose active project is this project;
   - `intent` is granted to a (job, line) pair when the job installer is in the project `dedicatedInstallerIds` (new), or through the reservation of the line (unchanged), or through the owner producer list (unchanged) unless the installer is dedicated to another project of the pass, in which case the producer list gives no `intent` for that installer (the dedication outranks a producer list);
   - the dedication never creates a pair: the line must still be eligible (blueprint, remainder, characters of the project, container filter #1125) as today; without such a line in the active project the other signals apply and the job goes where they say;
   - a character whose active project is not among the projects of the pass (completed, draft released) has no dedication effect: the pass ignores it, nothing is cleared by the matcher;
   - same input, same output: deterministic as before (ties keep project age, line, job id).
7. The signal is stored as today (`matchSignals` contains `intent`); the clear-winner gate of #1247 uses the score as today: a dedicated job whose active project holds the only `intent` wins nettement over a project with `place` only.
8. Front, one shared component `CharacterActiveProjectSelect.vue` (select of the caller open projects, first option "No active project", disabled while saving, error text on failure; props: `character`; emits `updated`). Used in two places:
   - industry settings, character list (`SkillsConfig.vue`): one column "Active project" per row, loaded through a small composable `useCharacterActiveProjects` (`GET /me/characters`, `PATCH`);
   - project page, project header block (near the producer characters, hidden on a completed project): panel "Dedicate a character to this project" listing the caller characters with a checkbox each, ticked when the character's active project is this project; ticking a character dedicated elsewhere shows the other project name and moves it on confirmation of the checkbox (the PATCH replaces the old value).
   The open projects come from the existing group-industry store `myProjects` (`fetchMyProjects`), not a new endpoint.
9. i18n FR and EN: keys under `industry.activeProject.*` (column, select placeholder, none option, panel title, hint, error, "currently dedicated to {project}"). No hard-coded label.
10. No async action, no Mercure topic, no new sync. The change applies to the next job sync match; the "Match jobs" button already re-runs the pass.

## Examples

| Situation | Result |
|---|---|
| Characters A and B of the same user, projects P1 (older) and P2, same blueprint, job of character A on a 5-run line in both; A dedicated to P2 | Job goes to P2, signals contain `intent` |
| Same, nobody dedicated, no other data | No clear winner (#1247): job to assign, P1 listed first (oldest) |
| A dedicated to P2, P2 has no line for the job blueprint with a remainder, P1 has | Job goes to P1, no `intent` |
| A dedicated to P2, P1 lists A in `producerCharacterIds`, both have a fitting line | Job goes to P2 (P1 gets no `intent` for A) |
| A dedicated to P2, line of P1 reserved by the user of A, both fit | Both hold `intent`, no clear winner (#1247): job to assign, P1 listed first |
| A dedicated to P2, job fits only P2 | Linked to P2 as before, `intent` in `matchSignals` |
| Project P2 completed | Characters dedicated to P2 become null, response of next GET shows `activeProjectId: null` |
| Participant leaves P2 or is kicked | Their characters dedicated to P2 become null, characters of others untouched |
| `PATCH` with the project of a user the caller does not share | 404 |
| `PATCH` on a character of another user | 404 |
| `PATCH {projectId: null}` | Active project cleared, 200 |

## Cases and limits

- A character can be dedicated to a draft project (open per rule 2): it has no effect until the project is launched and enters the pass.
- Owner can dedicate their own characters; "leaving" does not apply to the owner (cannot leave).
- Deleting the character removes the row, nothing else to clear.
- `producerCharacterIds` of the project is unchanged by a dedication.

## Tests expected

- Domain (pure): the rows of the table above on `JobMatchPlanner` with `dedicatedInstallerIds` (intent from dedication, dedication beats producer list, reservation tie, no pair created, no dedication => as before).
- `CharacterActiveProject`: dedicate (eligible, other user's project, completed project, null clears), `clearForProject`, `clearForParticipant`.
- `IndustryJobMatcher`: a character whose `activeProject` is a project of the pass yields the `dedicatedInstallerIds`; one dedicated to a project outside the pass does not.
- Processors: `SetCharacterActiveProjectProcessor` and `CharacterActiveProjectProvider` (200, 404 foreign character, 404 foreign project, 400 missing key), completion / leave / kick call the clearing (one test each).
- Functional: PATCH then GET `/me/characters` returns `activeProjectId` and `activeProjectName`.
- Front (at most 10 tests): select shows the current value and PATCHes on change, none option clears, error shown on failure; project panel ticks the characters of this project and PATCHes on tick and untick, hidden when the project is completed.

## Out of scope

Shared job between projects (#1250), copy and invention jobs (#1193), automatic dedication, a bulk "release all characters", showing the dedication in the companion, any change to the unassigned jobs list.

## Open decisions (taken)

- Column on `characters` rather than a link table: one active project per character, read with the characters, never queried on its own.
- The dedication outranks the owner's producer list but not an explicit reservation: a reservation is a commitment on one line, the dedication is a standing intent; the tie then falls on project age.
- No prototype screen exists for this setting: the select sits in the existing character list of the industry settings and a panel in the project header. To place in the flow page at #1307.
