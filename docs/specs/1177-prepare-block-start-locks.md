# #1177 One Prepare block in draft and say what Start locks

Front only. Circuit l treated without sub-agent (night chain budget). Stacked on #1217 (same `GroupIndustryDetail.vue`).

## Rules

1. In `draft`, the Sourcing tab (`bom`) shows one block `prepare-block` titled "Prepare" (`groupIndustry.prepare.title`, FR "Préparer") that contains, in this order: `ProjectBlacklistEditor`, `ProjectInventionExclusionToggle`, `ProjectBuyOrBuild`, `ProjectStructures`. A help line (`groupIndustry.prepare.help`) says that starting locks the blacklist and the invention exclusion, while buy or build and structures stay editable.
2. In `draft`, `ProjectStructures` is rendered only inside the block, never in the Production tab (`chain`). Outside `draft`, nothing changes: `ProjectBuyOrBuild` stays in Sourcing after the invention toggle and before the materials table, `ProjectStructures` stays in Production.
3. Outside `draft` (published, in progress, selling, completed) the block frame is not rendered.
4. Leaving `draft` through "Start the project" (target `in_progress` while the project status is `draft`), from the header button or the next action card, opens a confirmation modal (`start-confirm`, same template as `sellingConfirm`) instead of patching at once. It states that the blacklist and the invention exclusion can no longer be changed and lists their current values in `start-confirm-locks`: blacklist (`{groups}` groups and `{items}` items excluded, or "nothing excluded") and invention (excluded from or included in the plan). Title and confirm button use `groupIndustry.detail.statusActions.in_progress`. Confirm patches `{ status: 'in_progress' }`, cancel patches nothing.
5. Starting a `published` project (target `in_progress`, status `published`) keeps patching at once, without modal (nothing is locked at that point).
6. In `draft`, the `start_project` next action card description is `groupIndustry.cockpit.nextAction.start_project.descriptionLocks` (names the Prepare block and says what Start locks). In `published` it keeps `.description`. `ProjectNextActionCard` takes an optional boolean prop `locksOnStart` (default false) set by the view when the status is `draft`.
7. Every new text exists in `en.json` and `fr.json`.

## Edge cases

- Non-manager viewer in draft: the block is shown, children keep their own read-only behaviour (editor hidden, toggle disabled, switches off).
- Empty blacklist and no exclusion: modal still opens and says nothing is excluded and invention is included.

## Out of scope

- Publish from `draft` also leaves `draft` (and locks); its confirmation is not requested by the issue. Open question for Sylvain, no change tonight.
- No backend change: no new next action code, `ProjectCockpitCalculator` untouched.
- No setting, field, route or screen removed.
