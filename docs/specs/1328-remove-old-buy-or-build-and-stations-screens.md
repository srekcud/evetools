# #1328 Remove the old Buy or build and Stations screens

Circuit m, mode nuit. Integration branch `feat/industry-flow`, no migration, no new access.

## Context

The startup wizard (#1289), the startup choices block (#1290) and `GET /sourcing-decisions` (#1288) replace the old Buy or build screen (#343) and the old Stations screen (#341/#342). They were kept because some fields had no new home. #1337, #1338, #1339, #1340 and #1342 gave a home to each of them; Sylvain agreed to drop two fields (volume x jumps of the transport detail, comparison per manufactured product).

## Where each field now lives (checked by grep on `5181353a3`)

| Old field | New home |
|---|---|
| Cost if the surplus is reused, saving | wizard sourcing step, `sourcing-reused-*` (#1340) |
| "Choice changes without the import" | first cell of the sourcing row (#1340) |
| Partial Jita coverage marker, volume per unit | Jita cell of the sourcing row (#1340) |
| Courier hub name | wizard stations step footer (#1340) |
| Switch buy or build on a running project, with confirmation, "excluded" badge | card detail, `FlowDetailBuyOrBuildBlock` (#1337) |
| Installation tax rate, "tax not set" | wizard station cards (#1338), card detail structure block (#1342) |
| Favorite system, jumps, banners | wizard station cards (#1339) |
| Accept outside favorite system, minimum saving | wizard stations step (#1339) |
| Volume x jumps, comparison per product | dropped, agreed by Sylvain |

## Rules

1. `ProjectBuyOrBuild.vue` and `ProjectStructures.vue` are no longer rendered by `GroupIndustryDetail.vue`, in any tab, in any project status.
2. In a draft project the prepare block keeps the startup choices, the blacklist editor and the invention exclusion toggle; in other statuses the page renders as before without the two cards.
3. `GET /industry/projects/{projectId}/buy-or-build` no longer exists (404). `ProjectBuyOrBuildResource`, `ProjectBuyOrBuildProvider`, `BuyOrBuildRowPresenter`, `IntermediateBuyOrBuildComparator` and `IntermediateComparison` are deleted.
4. The front stores `group-industry/buyOrBuild.ts` and `group-industry/structures.ts`, their fixtures and specs are deleted: `ProjectBuyOrBuild.vue` and `ProjectStructures.vue` were their only consumers.
5. The chain tab reads three endpoints one after the other (`/chain`, `/research`, `/structure-health`), through `projectDetailRequestQueue` as before.
6. Kept untouched: `BuyOrBuildChoice` (used by `DeliveredSourcingLine`), the PATCH that changes `blacklistTypeIds` and its tests (`ProjectBuyOrBuildSwitchApiTest`), `GET /structures` (see out of scope), every shared Domain class.

## Examples (red tests)

| Test | Expected now (red) | Expected after |
|---|---|---|
| `GET /buy-or-build` as an accepted member | 200 | 404 |
| Detail page, tab `bom`, draft, owner | `ProjectBuyOrBuild` and `ProjectStructures` present | absent; blacklist editor and invention toggle present |
| Detail page, tab `bom`, `in_progress` | `ProjectBuyOrBuild` present | absent; materials table present |
| Detail page, tab `chain`, `in_progress` | `ProjectStructures` present | absent; chain, research and structure health present |
| Chain tab reads, in order | `/chain`, `/buy-or-build`, `/research`, `/structures`, `/structure-health` | `/chain`, `/research`, `/structure-health` |

## Edge cases

- A member who cannot edit sees the same page, without the two cards.
- `onBuyOrBuildSwitched` stays: the card detail still uses it after a switch.

## Tests contradicted or removed

- Deleted with the code: `projectBuyOrBuild.spec.ts`, `projectStructures.spec.ts`, `groupBuyOrBuild.spec.ts`, `groupStructures.spec.ts`, `buyOrBuildRows.ts`, `structureRows.ts`, `BuyOrBuildRowPresenterTest`, `IntermediateBuyOrBuildComparatorTest`, `ProjectBuyOrBuildApiTest`.
- Rewritten: `groupIndustryDetailPrepareBlock.spec.ts`, `groupIndustryDetailBuyOrBuildPlacement.spec.ts`, `groupIndustryDetailSourcingCardOrder.spec.ts`, `groupProjectDetailSerialReads.spec.ts`; stubs of the two removed components dropped from the other detail specs.

## Out of scope

- `GET /industry/projects/{projectId}/structures` with `ProjectStructuresResource`, `ProjectStructuresProvider` and `StructureComparisonPresenter`: the issue lists only `/buy-or-build`; once the screen is gone the route has no caller, reported as debt for Sylvain.
- Unused i18n keys of the two screens.

## Glossary

`Acheter ou fabriquer (projet)`, `Coût avec réemploi du surplus` and `Import` cite classes that are deleted: their "Code" column points to the wizard and `DeliveredSourcingLine` instead.
