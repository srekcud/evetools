# #1331 Report unrecognised rigs and read structure bonuses from the SDE everywhere

Circuit M. Spec validated in autonomy (night mode). Follow-up of #1291: the station recommendation (`ConfiguredProductionStructures`) already reads rig and role bonuses from the SDE (`SdeRigBonusReader`, `SdeStructureBonusReader`). Two gaps remain.

## Problem

1. A rig name missing from the SDE is silently dropped by `ConfiguredProductionStructures`: the station row looks complete while one rig is ignored.
2. The structure screens (`IndustryResourceMapper`, `StructureBonusPreviewProcessor`, `CorporationStructureProvider`, `FactoryStations::specialisationsOf`, `RigOptionsProvider`) still read tables written by hand: `IndustryStructureConfig::get{Manufacturing,Reaction}{Material,Time}Bonus`, the `bonus`/`timeBonus`/`costBonus` values of `RigCatalogue`.

## Rules

1. `ConfiguredProductionStructures` records the rig names it cannot resolve in the SDE (`InvTypeRepository::findByNames` misses it): `ProductionStructure::$unrecognisedRigs` (`list<string>`, last constructor argument, default `[]`, order of the configured rigs, no duplicate). The structure still loads, the recognised rigs keep their bonus.
2. `MissingData::unrecognisedRig()`: reason `unrecognised_rig`, no typeId.
3. `WorkStationAdvisor::rowsOf`: a row whose structure has at least one unrecognised rig carries `MissingData::unrecognisedRig()` (once) in `WorkStationRow::$missingData`, after the missing data of the cost. The costs, the ranking and the recommendation are unchanged (the row is computed on the recognised rigs only, so the rig counts as no bonus, never as a hidden one). `WorkStationPresenter` already sends `missingData` through `MissingDataPresenter::ofList`: `{reason: 'unrecognised_rig', typeId: null}`.
4. New `StructureBonusSummary` (`src/Industry/Infrastructure/Doctrine/`, readonly, injected: `InvTypeRepository`, `SdeStructureBonusReader`, `SdeRigBonusReader`). `of(string $structureType, string $securityType, array $rigNames): StructureBonuses`. `StructureBonuses` (`src/Industry/Application/`) = `manufacturingMaterialBonus`, `reactionMaterialBonus`, `manufacturingTimeBonus`, `reactionTimeBonus` (percent, rounded to 2 decimals) and `unrecognisedRigs` (`list<string>`).
5. Security: same mapping and same `UnexpectedValueException` as `ConfiguredProductionStructures` for an unknown value (shared, no copy). Structure type id: `ConfiguredProductionStructures::STRUCTURE_TYPE_IDS` made public. `station` and the legacy types (`engineering_complex`, `refinery`) have no SDE type: no role bonus, rigs still read.
6. Material bonus: the sum, over the recognised rigs, of `reductionPercent × security modifier` of the rig. Manufacturing: generic bonus (`materialBonusOf(Manufacturing)`), else the Thukker capital component bonus (`thukkerMaterialBonusOf`), else nothing. Reaction: `materialBonusOf(Reaction)`. A rig without the attribute adds nothing; a rig with no security modifier in this security (inoperative there) adds nothing. The structure role material bonus is not part of this number (as before).
7. Time bonus: `(1 − role time multiplier × Π rig time multipliers) × 100`. Role: `timeMultiplierOf(type, Manufacturing|Reaction) ?? one`. Rigs: `timeBonusOf` with `reductionPercent > 0` and a non-empty `StructureRigTargets::categoriesOf(name)` (laboratory rigs are not job time rigs), each `multiplierIn(security)`; a rig with no modifier in this security adds nothing. Same rule as `ConfiguredProductionStructures::timeRigsOf`, so the screens and the recommendation agree.
8. `RigBonus::effectivePercentIn(SecurityClass): ?float` = `reductionPercent × modifier`, `null` when the modifier is absent (rules 6 and 7 use it; `multiplierIn` keeps throwing).
9. `IndustryStructureConfig` loses `getManufacturingMaterialBonus`, `getReactionMaterialBonus`, `getManufacturingTimeBonus`, `getReactionTimeBonus`, their private helpers and the `THUKKER_*` / `STANDARD_*` constants. `IndustryResourceMapper`, `StructureBonusPreviewProcessor`, `CorporationStructureProvider` (`sharedConfig`) and `FactoryStations::specialisationsOf` (a specialisation when the material or time bonus of the activity is above 0) use `StructureBonusSummary`.
10. `StructureConfigResource`, `StructureBonusPreviewResource` and the `sharedConfig` array of `CorporationStructureProvider` also expose `unrecognisedRigs` (`list<string>`).
11. `RigCatalogue` keeps `name`, `category`, `size`, `targetCategories` and loses `bonus`, `timeBonus`, `costBonus`. `RigOptionsProvider` (injected: `InvTypeRepository`, `SdeRigBonusReader`; one `findByNames` call) adds them from the SDE for each rig:
    - `bonus` (float): the material bonus of rule 6 without security modifier, `0.0` when the rig has none (laboratory rigs);
    - `timeBonus`: `timeBonusOf` of the family (`manufacturing` rigs read Manufacturing, `reaction` rigs Reaction), present only when above 0;
    - `costBonus`: `costBonusOf`, present only when above 0.
    - `categoryBonuses` (`array<string, float>`): `['basic_capital_component' => Thukker capital component bonus]` only for a rig that carries both the generic material bonus and the Thukker one (the XL-Set Thukker rig); absent otherwise.
    A catalogue rig missing from the SDE is not offered (it cannot be fitted in game); its name is not turned into a bonus.
12. `ProductionStructureRigsValidator` is unchanged (names, family, size of the catalogue).

## Examples (values of the current tests, SDE dogma of the dev database)

| Structure, security, rigs | Material (man.) | Material (react.) | Time (man.) | Time (react.) |
|---|---|---|---|---|
| athanor, nullsec, Composite Reactor ME II | 0 | 2.64 | 0 | 0 |
| tatara, nullsec, L-Set Reactor Efficiency II | 0 | 2.64 | 0 | 44.8 |
| sotiyo, lowsec, XL Thukker Structure and Component | 3.8 | 0 | 56.6 | 0 |
| sotiyo, nullsec, XL Thukker Structure and Component | 0.2 | 0 | 31.4 | 0 |
| raitaru, highsec, no rig | 0 | 0 | 15 | 0 |
| raitaru, nullsec, M-Set Thukker Basic Capital Component | 0.37 | 0 | 15 | 0 |
| sotiyo, nullsec, XL Laboratory Optimization II | 0 | 0 | 30 | 0 |
| raitaru, nullsec, Standup M-Set Laboratory Optimization II (not in the SDE) | 0 | 0 | 15 | 0, `unrecognisedRigs` = that name |

## Edge cases

- Every rig of a structure unknown: bonuses from the role only, `unrecognisedRigs` lists them all.
- The same unknown name twice: listed once.
- Two time rigs on one structure stack multiplicatively, like in the recommendation (the old sum is gone).
- `station`: all four bonuses 0, no rig lookup needed beyond the names given.
- Preview of a structure never saved: works on the three raw values, no entity needed.

## Out of scope

- Front display of `unrecognised_rig` and `unrecognisedRigs` (the station row of the flow, the structure form) and its i18n label: the data is on the wire, the screens follow in the flow screens.
- Rig discovery from the SDE (names, size, family, target categories stay in `RigCatalogue`, `StructureRigTargets`).
- Rejecting an unrecognised rig when a structure is saved.
- Any change to costs, ranking or recommendation.

## Open decisions (taken, to review at the end)

- Rigs missing from the SDE are hidden from the rig options rather than listed without numbers.
- Legacy `engineering_complex` and `refinery` no longer show their old role time bonus (20 % and 25 %): the SDE has no type for them and the recommendation already refuses them.
- Two time rigs: multiplicative stacking (recommendation) replaces the sum of the old screens.
