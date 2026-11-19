# État des issues noyau Industry (#2, #6, #7, #8, #18) — 2026-10-22

Base : main 2519314. **Aucun appelant ne consomme `src/Industry`** (ProductionCostCalculator, ProductionPlanner, StructureSelector n'ont que leurs tests). Le rang 1 du strangler (ProfitMarginService) n'est pas migré. 24 tests de caractérisation `…Issue{2,6,8}` figent les bugs.

- #2 runs lus comme une quantité : (c) rangs 1 à 7. Nouveau : `StockpileStartingStockReducer:31` passe des runs et `:69` une quantité au même paramètre `$runs`.
- #6 trois règles de bonus : (c) rangs 4, 5, 7. Arbre `IndustryTreeService:150-152`, factory `IndustryProjectFactory:446-474`, étapes `IndustryCalculationService:95-153`. D3b existe dans StructureSelector, non branché.
- #7 coût d'installation : (b) `EsiCostIndexService::syncAdjustedPrices:59` met en cache 0.0 (peu de valeur seul : `calculateEivFromPrices:174` remet `?? 0.0`) ; (c) formule sans SCC `:264-266`, `0.0` sur index absent `:255,:261`, TTL = intervalle scheduler, réimplémentations InventionService:81,:305,:597, BatchProfitScanner:321, sans taxe BuyVsBuild:215,:291, Pivot:176,:440. Formule correcte dans le noyau (`InstallCost.php`).
- #8 prix absent = gratuit : corrigé scanner (ed95dae). Restes : ProfitMarginService:85 (r1), BuyVsBuild:125,133,203 (r2), Pivot:170,211,434, **InventionService:272 (décrypteur via getPrice, jamais dans le cache de fond → toujours 0 ISK en prod) et :286 (datacores)**, GroupIndustryContributionService:51,:172-174 (entité non nullable, migration).
- #18 Profit Margins : (b) frais de courtage sur un buy order `ProfitMarginService:386,:464 → :435` (figé par golden :266) ; (c) décrypteur sans effet sur le ME (:61), un seul succès (:131,:146), `ceil(1/p)` InventionService:316.

## Lots autonomes
1. InventionService : prix décrypteur/datacore inconnus (#8, #18) — lancé 2026-10-22 (wt-EO).
2. ProfitMarginService : un buy order ne paie que la taxe de vente (#18).
3. EsiCostIndexService::syncAdjustedPrices sans 0.0 (#7) — faible valeur seul.
