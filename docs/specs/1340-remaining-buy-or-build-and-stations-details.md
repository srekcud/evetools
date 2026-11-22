# #1340 - Remaining Buy or build and Stations details in the wizard

Circuit m, mode nuit (spec validated in autonomy). Base: `feat/industry-flow`. Front and backend, no migration, no new access path, no new ESI call.

## Goal

Before #1328 removes `ProjectBuyOrBuild.vue` and `ProjectStructures.vue`, every field of the issue either shows in the wizard (sourcing step, station step) or is dropped on purpose with a reason (issue comment, Sylvain agrees before #1328).

## Where each field goes

| Old field | New home | Decision |
|---|---|---|
| Build cost if the surplus is reused, and the saving | Sourcing step, Build cell of the line | kept |
| "Choice changes without the import" alert | Sourcing step, first cell of the line | kept |
| Partial coverage marker of the Jita price | Sourcing step, Jita cell, next to the per unit price | kept |
| Volume per unit of the import detail | Sourcing step, Jita cell detail | kept |
| Courier hub name in the header | Station step footnote | kept |
| Transported volume x jumps | none | dropped, see below |
| Comparison per manufactured product | none | dropped, see below |

Dropped (listed in the issue comment):
- Volume x jumps: the new legs are priced per m3 per leg (courier Jita to hub, jump bridge), not per jump. The leg line already shows the volume and its cost, and the station card shows the jumps from the preferred system. A volume x jumps figure would not explain any cost.
- Comparison per manufactured product: stations are chosen per work type, and a station gives the same bonus and tax to every product of a type. A per product table repeats the per type one without a decision attached.

## Backend rules (sourcing decisions, `GET /industry/projects/{id}/sourcing-decisions`)

1. Row field `volumePerUnit` (float or null): the volume of one unit of the type, from the same `ItemVolumes` the Jita import uses. Null when unknown, never 0.
2. Build option field `perUnitIfSurplusReused` (float or null): Build option total of the universe divided by the quantity the universe plan produces, only when the plan produces more than it consumes (surplus > 0). Null for any other option or without surplus. Same rounding (2 decimals) as `perUnit`.
3. Row field `savingIfSurplusReused` (float or null): non null only when the recommended option is Jita or Structure (a buy), the Build option exists with a known total, the recommended option total is known, and `build total x demand / produced` is strictly below the recommended option total. Value = recommended total - that reused build total, scaled by the row share (`quantity / universeDemand`) like the option totals. Null otherwise. This is the rule of the old `IntermediateBuyOrBuildComparator::savingIfSurplusReusedOf`, with the recommended option as the buy cost.
4. Row field `choiceChangesWithoutImport` (bool): the verdict of Build against Jita differs with and without the import. Verdict of two costs = unknown if one is unknown, Build if build <= other, else Buy. With import compares Build total with Jita total; without import compares Build total with the Jita price (total minus transport). False when the row has no Build option or no Jita option. A known Jita price with an unknown import cost gives a change (unknown against a verdict), as before.
5. Jita option field `coveragePercent` (float or null): `PartialPricePresenter::coveragePercentOf` of the Jita price cost for the type. Null when the order book covers the quantity. The field exists on every option, null outside Jita.
6. The line carries what these rules need: `volumePerUnit` and the universe produced quantity (`SourcingUniverse` keeps the produced quantity of each built product). Constructors keep working for the existing tests (new parameters at the end).

## Backend rule (work stations, `GET /industry/projects/{id}/work-stations`)

7. Resource field `courierHubSolarSystemName` (string or null): name of the courier hub system read from the SDE solar systems; null when the system is unknown. Same source as the old `ProjectBuyOrBuildProvider`.

## Front rules

8. `SourcingOption` gets `perUnitIfSurplusReused: number | null` and `coveragePercent: number | null`; `SourcingRow` gets `volumePerUnit: number | null`, `savingIfSurplusReused: number | null`, `choiceChangesWithoutImport: boolean`; `ProjectWorkStations` gets `courierHubSolarSystemName: string | null`.
9. Build cell (`sourcing-cell-<type>-build`): below the detail, when `perUnitIfSurplusReused` is not null, a line `data-testid="sourcing-reused-<type>"` with "{amount}/u if the surplus is reused"; when `savingIfSurplusReused` is not null, a second line `sourcing-reused-saving-<type>` with "saving {amount} if the surplus is reused". FR and EN strings; amounts through `formatIsk` and `formatNumber(…, 2)` like the per unit price.
10. Jita cell: `PartialPriceMarker` (existing component) next to the per unit price when `coveragePercent` is not null; the detail line adds the volume ("{volume} m3/u") when `volumePerUnit` is not null, `data-testid="sourcing-volume-<type>"`. No line at all when it is null (unknown stays silent, not 0).
11. First cell of the line: when `choiceChangesWithoutImport` is true, an amber line `data-testid="sourcing-without-import-<type>"` "without the import the choice changes". Same palette as the existing amber notes.
12. Station step footnote: when `courierHubSolarSystemName` is not null the footnote names the hub ("Courier Jita to {hub}"); when null the footnote keeps today's text. The unknown rates branch is unchanged.
13. The old screens and their keys stay (#1328 removes them). New keys live under `groupIndustry.setup.sourcing` and `groupIndustry.setup.stations`, FR and EN, same wording as the old keys `ifSurplusReused`, `savingIfSurplusReused`, `withoutImport`, `importDetail`.
14. Glossary: add "Surplus reutilise" / "Reused surplus" only if the term is missing (check `docs/glossary.md`).

## Edge cases

- Surplus 0 or no Build option: no reused line, no reused saving.
- Recommended is Build or null: `savingIfSurplusReused` null.
- Row without Jita option (Jita option always exists in sourcing, price may be unknown): `coveragePercent` null, `choiceChangesWithoutImport` false when both verdicts are unknown.
- Reaction line bought: the line has no Build option universe change; fields come from the universe options as before.

## Out of scope

- Removing the old screens and endpoints (#1328).
- Card detail changes: the wizard only opens on a draft project, so after start these details are no longer shown; the issue accepts the wizard as a home.
- Mercure: read-only fields on existing GETs, no new async action.
- The per product comparison and volume x jumps (dropped above).

## Open decisions (taken)

- D1: wizard only, no card detail block (the fields explain the cost of a choice made in the wizard).
- D2: dropped fields listed in the issue comment, Sylvain agrees before #1328.
- D3: money rule 3 and the verdict of rule 4 are computed in the backend, not in the front.

## Examples (numbers)

| Case | Build total | Produced | Demand | Buy (recommended) total | Result |
|---|---|---|---|---|---|
| Surplus reused makes build cheaper | 1 000 | 120 | 100 | 950 | `perUnitIfSurplusReused` 8.33, `savingIfSurplusReused` 950 - 1 000 x 100 / 120 = 116.67 |
| Reuse not enough | 1 200 | 120 | 100 | 950 | per unit if reused 10, saving null (1 000 >= 950) |
| No surplus | 1 000 | 100 | 100 | 950 | both null |
| Import flips the verdict | 1 000 | | | Jita price 900, import 200 | with import Build, without Buy: `choiceChangesWithoutImport` true |
| Import changes nothing | 1 000 | | | Jita price 1 500, import 200 | Build both ways: false |
