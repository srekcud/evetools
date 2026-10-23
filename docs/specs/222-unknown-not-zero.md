# #222 — Unknown or not-yet-loaded values are shown as unknown, never as 0

## Context

Several screens render `0` (ISK, percent, extractor count) when a value is unknown or still loading, and the project table sorts unknown amounts as 0. The Industry dashboard "estimated profit" KPI is already handled by #181 (`projectProfitTotal`, known profits only plus "n excluded"); Profit Margins (#178), Group Industry (#156) and public contracts (#217) are out of scope.

## Decisions (Sylvain, 2026-10-23)

- A shared display component `<IskAmount>` renders ISK amounts that may be unknown.
- In `ProjectTable`, projects whose sort value is unknown always go last, ascending or descending.

## Rules

1. `<IskAmount :value="number | null" :loading?="boolean" :short?="boolean">` (`frontend/src/components/common/IskAmount.vue`):
   - `loading` true → a skeleton placeholder (`aria-busy="true"`), no number;
   - `value` null and not loading → `—` with a tooltip (`title`) saying the value is unknown (`common.unknownValue`, FR « Valeur inconnue », EN "Unknown value");
   - a number → the same text as `formatIsk(value)` today, `0` included (a known 0 is shown as 0).
2. Non-ISK values that may be unknown (extractor counts, pipeline health percent) render `—` with the same tooltip, not `0` / `0 %`. The pipeline health ring is neutral (no red/amber/green colour, no percent) when the stockpile status is unknown.
3. Mining (`MiningStatsCards`, `MiningTab`): while `miningStats` is null, total value, ISK per active day, mined volume and the ore-breakdown total are unknown (skeleton while `ledgerStore.isLoading`, `—` otherwise). Main-system percent is unknown when the backend total is unknown.
4. PI (`PlanetaryInteraction` → `PiKpiCards`): while `planetaryStore.stats` is null, active / expired / expiring / total extractors are unknown (`—`). Colonies count and estimated daily ISK keep their existing fallbacks from the colonies list.
5. Industry pipeline health (`IndustryDashboard`, `StockpileDashboard`): unknown when `stockpileStore.stockpileStatus` is null.
6. `ProjectTable` sort on materials, job cost, taxes, sell price, profit: the value is `actual ?? estimated`; when both are null the project is placed after every project with a value, in both directions, keeping the original relative order among unknowns.

## Examples

| Screen / input | Expected |
|---|---|
| `IskAmount` value `null` | text `—`, `title` = unknown-value label, no `0` |
| `IskAmount` value `0` | `formatIsk(0)` |
| `IskAmount` value `1500000`, loading true | skeleton, no digits |
| `ProjectTable` profits A 1 000 000, B null, C −200 000, sort profit desc | A, C, B |
| same, sort profit asc | C, A, B |
| `ProjectTable` sell price: A actual null + estimated 5 000 000, B both null, C actual 1 000 000, desc | A, C, B |
| `MiningStatsCards` with `miningStats = null`, not loading | no "0 ISK" rendered; total value shows `—` |
| `PiKpiCards` from `PlanetaryInteraction` with stats null and 2 colonies | extractor KPIs show `—`, colonies shows 2 |
| `IndustryDashboard` with stockpile status null | pipeline health shows `—`, no `0%` |

## Out of scope

- Profit Margins, Group Industry, public contracts (#178, #156, #217).
- Replacing every `formatIsk` call in the app with `<IskAmount>`: only the screens above.
