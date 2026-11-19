# Profit Margins green phase, frontend

Date: 2026-10-22. Only `frontend/src/` touched. Nothing committed. `tests/`, `frontend/tests/`, `src/`, `config/` untouched.

## Changes

### `frontend/src/stores/industry/types.ts`
- New `MissingDataReason` (`market_price` | `adjusted_price` | `cost_index` | `invention_probability`) and `MissingData { reason, typeId: number | null }` (glossary term "Donnee manquante" / `App\Industry\Domain\MissingData`).
- `ProfitMarginJobStep`: `unknownReason` replaced by `missingData: MissingData[]`.
- `ProfitMarginResult`: `copyCost` removed; `missingPriceTypeIds` and `unknownInstallCostReasons` replaced by `missingData: MissingData[]`. Amounts were already nullable (materials, installCost, materialCost, jobInstallCost, inventionCost, totalCost, costPerUnit, option probability/inventionCost/totalProductionCost/bestMargin). `invention.unknownReason` kept.
- The scanner / Buy vs Build / Pivot types (`missingPriceTypeIds`, `unknownInstallCostReasons` around lines 520-670) are not touched: other screens, not migrated (rank 2).

### `frontend/src/components/industry/ProfitMarginTab.vue`
- `unknownInstallCostReasons` computed from `result.missingData`: `cost_index` -> `missing_cost_index`, `adjusted_price` -> `missing_adjusted_price`, deduplicated, order of appearance. Feeds the existing `productionCostUnknownReasons` (material price / invention reason first, unchanged).
- `missingPriceTypeNames`: type ids taken from `missingData` entries with reason `market_price`, matched against `materials` (a datacore or decryptor typeId is not listed here; its case is covered by `invention.unknownReason = 'missing_market_price'`).
- `effectiveInventionPlusCopy` removed; the cost summary and the breakdown bar use `effectiveInventionCost` (API `inventionCost` now includes the T1 copies).
- Job install step runs rendered with `toLocaleString()` (runs may be fractional).
- No new i18n key. No "probability without skills" label: no spec and no existing i18n key asks for it.

`InventionOptionsPanel.vue` unchanged: it already renders null probability / costs / margins as unknown and reads no removed field.

## Gates (frontend)
Commands (node not on host):
- Tests: `docker run --rm -v $PWD/frontend:/app -v evetools_frontend_node_modules:/app/node_modules:ro --tmpfs /app/node_modules/.vite-temp -w /app node:22-alpine npx vitest run`
  -> 27 files, 193 tests passed (incl. the 3 Profit Margins specs: 8 + 11 + 3).
- Type check: same mounts, `npx vue-tsc --noEmit -p tsconfig.json` -> exit 0, no output. (`-b` avoided: it would rewrite the tracked `tsconfig.tsbuildinfo`.)
- ESLint on the two touched files: 0 errors, 6 pre-existing `no-non-null-assertion` warnings on untouched lines.
- The `@ {price} / unit` "Message compilation error" lines in the vitest output come from the existing `perUnitShort` key in both locales, not from this change.

PHP gates (phpunit, phpstan, deptrac, infection) not run: backend is the other implementer's scope, and no PHP file was touched here.
