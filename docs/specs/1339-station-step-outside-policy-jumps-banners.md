# #1339 · Policy outside the favorite system, jumps and banners in the station step

Circuit m. Branch `feat/industry-flow`. The old Stations screen (`ProjectStructures.vue`) stays until #1328.

## Existing (verified, not rebuilt)
- `PATCH /industry/projects/{id}` already takes `acceptsOutsideFavoriteSystem` and `minimumOutsideSaving` (`UpdateProjectProcessor::changeTheSelectionPolicy`: refused 400 on a completed project, BOM rebuilt, rolled back on refusal; `minimumOutsideSaving` is `PositiveOrZero`, same as `CreateProjectInput`).
- `GroupProject` (`projectStore.currentProject`) carries both fields; `WorkStationRow.inFavoriteSystem` is read.
- The station step (`ProjectSetupStations.vue`) is shown by `ProjectSetup.vue` for the draft and for `?edit=stations`, only to the owner or an admin (`canRunSetup`), so the new block inherits that access check.

## Rules
1. **Backend, jumps.** `WorkStationRow` gets `?int $jumps`: jumps from the favorite system of the row activity (`FavoriteSystems::forActivity`) to the solar system of the structure, via `JumpDistances::jumpsBetween`. `null` when the favorite system, the structure system or the route is unknown, never 0. Set on every row, blueprints rows included. `WorkStationAdvisor` takes `JumpDistances` in its constructor. `WorkStationPresenter` exposes `jumps` (int or null). The ranking and every cost are unchanged.
2. **Policy block.** On top of the station step, a block `stations-policy`: a checkbox `stations-policy-outside` (accepts a station outside the favorite system) and, only while it is checked, a number input `stations-policy-threshold-input` (minimum saving, ISK) with a button `stations-policy-threshold-save`. The save button is disabled while the draft is not a finite number >= 0, equals the stored value, or a request runs. A hint under the threshold says it recalculates the material list.
3. **Saving the policy.** Toggling the checkbox sends `{ acceptsOutsideFavoriteSystem }`, the save button sends `{ minimumOutsideSaving }`, both through `projectStore.updateProject` (PATCH, merges the answer in `currentProject`). On success the work stations are read again (`setupStore.fetchWorkStations`) because the plan changed. The picks already made in the step are kept. A refusal shows its message (through `projectRefusalMessage`) in `stations-policy-error` (role alert) and the stored values stay displayed.
4. **Card.** Each station card shows the position under the system line (`station-position-{workType}-{structureId}`): "Favorite system" when `inFavoriteSystem`; otherwise "Outside the favorite system" followed, when `jumps` is known, by the jump count ("2 jumps", singular for 1), and "jumps unknown" when it is not. A station in the favorite system shows no count.
5. **Banners.** Pure function `stationBannerOf(type, picks, policy)` in `projectSetup.ts`. For a work type with `costsCompared`: `cheapest` = the row `recommendedStructureId`, `retained` = the row of `selectedStructureId(type, picks)`. No banner when either row is missing, they are the same, either `totalCost` is null, or `saving = retained.totalCost - cheapest.totalCost` is not above 0.01 ISK. Otherwise, in this order:
   - `onlyFavoriteSystem` when the policy is off and `cheapest` is outside the favorite system;
   - `belowThreshold` when the policy is on, `cheapest` is outside the favorite system and `saving + 0.01 < minimumOutsideSaving`;
   - `notRetained` in every other case.
   The banner is `station-banner-{workType}` (role status, amber), text "{structure} would cost {amount} less, transport included. ..." with the three endings of the old screen (only the favorite system / saving under the project threshold ({threshold}) / not retained). Action button `station-banner-{workType}-switch` (owner or admin, always the case here): "Allow for this project" on `onlyFavoriteSystem` (sends `acceptsOutsideFavoriteSystem: true`), "Back to the favorite system" on the two others (sends `false`), same as the old screen. Disabled while a request runs.
6. **Types, i18n, glossary.** `WorkStationRow.jumps: number | null` in `types.ts`; FR and EN keys under `groupIndustry.setup.stations.policy.*`, `.position.*`, `.banner.*` (no key of the old screen is removed or reused: #1328 deletes them). Glossary: the « Politique de sélection » line says it is changed after creation in the station step.

## Examples (reaction type, rows A cheapest 900 outside, B retained 1 000 in the favorite system, saving 100)
| Policy | Threshold | Banner | Action |
|---|---|---|---|
| off | any | `onlyFavoriteSystem` | Allow (sends true) |
| on | 500 | `belowThreshold` | Back (sends false) |
| on | 100 | `notRetained` | Back (sends false) |
| on | 100.005 | `notRetained` (100 + 0.01 is not below) | Back |
| any | any, retained = A | none | |
| any | any, B pick with A `totalCost` null | none | |
| off, A in the favorite system, B retained outside | `notRetained` | Back (sends false) |

Jumps: favorite 30000142, structure in 30000142 -> `inFavoriteSystem` true, jumps 0 (exposed, not shown); structure 3 gates away -> 3; structure without system -> null; no favorite system -> null.

## Edge cases
- Policy checkbox off: the threshold input is hidden, the stored threshold is untouched.
- Threshold `0` is valid and sent as 0.
- Completed project or refused rebuild: server message shown, no state change on the page.
- Work type `locked`: its banner is still shown (information), the picks stay frozen.
- Blueprints type (`costsCompared` false): no banner.

## Out of scope
- No change to the recommendation: `WorkStationAdvisor` still ranks by total cost and ignores the policy; the policy changes the BOM structure of jobs without a stored station (kept as is).
- The detail of the card structure block, `ProjectStructures.vue` and its keys (#1328), the byTotalCost banner of the old screen (the issue lists three).
- No Mercure event: the PATCH is synchronous and answers with the new project; the page reads again by itself.

## Decisions
- D1 banners compare the pick of the step with the cheapest total, not the old per product group (those groups do not exist in the work stations model).
- D2 jumps computed in the advisor from the favorite system of the activity, same definition as `StructureTotalCostComparator`.
- D3 the revert button on `notRetained` is kept as on the old screen.
