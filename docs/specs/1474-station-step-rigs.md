# Spec #1474: station step shows rigs and missing data, warns when no rig targets the product

Circuit M, mode nuit. Group `group:flow-location`.

## Problem

Every candidate of the station step can show the same material bonus (the hull role bonus alone) when its rigs were never entered. The screen does not say which rigs were counted, drops `row.missingData`, and gives no hint that a structure has no rig for the product being built.

## Rules

1. `WorkStationRow` carries `productCategory` (the `IndustryRigCategory` used to compute `materialMultiplier`, `null` when the work type has none, as for blueprints). Default `null` so existing constructions stay valid.
2. `WorkStationAdvisor::rowsOf` fills it with the category of the first job (the one already used for `materialMultiplier`).
3. `WorkStationPresenter::rowOf` exposes three new fields per row:
   - `productCategory`: the slug or `null`;
   - `rigs`: one entry per material rig of the structure, `{categories: list<string>, bonusPercent: ?float}`; `bonusPercent` is `RigBonus::effectivePercentIn(structure security)` rounded to 2 decimals, `null` when the SDE gives the rig no modifier in that security (never `0`);
   - `noRigForProduct`: `true` when `productCategory` is not `null` and no rig of the structure targets it; `false` otherwise (a `null` category never raises it).
4. Front, `ProjectSetupStations.vue`, on every station card, whether or not costs are compared:
   - `station-rigs-{workType}-{structureId}`: the rigs taken into account (category labels and bonus, `unknown` when `bonusPercent` is `null`), or a "no material rig" line when the list is empty;
   - `station-no-rig-{workType}-{structureId}`: shown only when `noRigForProduct`, amber, names the category (for instance "no rig for drone") and links to the industry structure settings (the existing `STRUCTURE_SETTINGS_LOCATION`);
   - `station-missing-{workType}-{structureId}`: the distinct readable reasons of `row.missingData` (so `unrecognised_rig` of #1331 is finally visible); a reason without label falls back to a generic "some data is unknown" text, never the raw snake_case code.
5. Labels in `fr.json` and `en.json` under `groupIndustry.setup.stations` (`rigs`, `missing`); category slugs of `IndustryRigCategory` have a label, an unknown slug falls back to itself.

## Examples

| Structure | Product category | rigs | noRigForProduct |
|---|---|---|---|
| Raitaru, drone rig (6 % effective in null-sec) | `drone` | `[{categories: [drone, fighter], bonusPercent: 6.0}]` | `false` |
| Sotiyo, no rig | `drone` | `[]` | `true` |
| Raitaru, ship rig only | `drone` | one entry, ship categories | `true` |
| Any structure | `null` (blueprints) | whatever it has | `false` |

## Out of scope

- Ranking logic (already correct: a drone rig makes the Raitaru win, characterised by the existing advisor tests).
- A recognised rig whose name matches no `StructureRigTargets` pattern stays ignored (science rigs rely on it); question left to Sylvain.
- Editing rigs from the station step: the link goes to the existing settings.
