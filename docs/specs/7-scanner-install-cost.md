# Spec #7 (lot L3b) — Coût d'installation du scanner via le noyau

## Contexte

Voir `.claude/tasks/7-design.md` §L3b. `BatchProfitScannerService::scan()` (`:318-321`) calcule le coût d'installation à la main : `EIV × index`, sans SCC ni taxe, 0 quand l'indice manque, et une EIV qui compte 0 pour un adjusted price absent (`EsiCostIndexService::calculateEivFromPrices`). Le scanner précharge les adjusted prices et les deux indices une fois pour des milliers de produits : il ne peut pas appeler `estimateJobInstallCost()` (lecture du cache par produit).

## Règles métier

- **R1 — Estimateur pur.** Nouvelle classe `App\Service\Industry\JobInstallCostEstimator` (final, sans dépendance) : `estimate(list<array{materialTypeId:int, quantity:int}> $materials, array<int,float> $adjustedPrices, ?float $costIndex, int $runs, string $activity, ?float $facilityTaxRate): array{cost: ?float, unknownReason: ?string, missingAdjustedPriceTypeIds: list<int>}`. Elle porte la conversion vers le noyau (`EstimatedItemValue`, `InstallCost`), les motifs et leur priorité, aujourd'hui dans `EsiCostIndexService::estimateJobInstallCost()`.
- **R2 — Délégation.** `EsiCostIndexService::estimateJobInstallCost()` lit le cache (adjusted prices, indice) puis délègue à l'estimateur ; son comportement ne change pas (tests L2/L3a inchangés).
- **R3 — Scanner.** Pour chaque produit : `estimate(matériaux ME 0 du blueprint, prix préchargés, indice préchargé de l'activité, 1, 'manufacturing'|'reaction', null)`. Taxe nulle dans ce lot (la taxe Q2 arrive en L3c).
- **R4 — Inconnu.** Coût d'installation inconnu → coût total, marge, profit par unité et ISK/jour null ; `materialCost` reste affiché s'il est connu. `unknownReason` : `missing_sale_price`, puis `missing_material_price`, puis le motif d'installation (`missing_cost_index`, `missing_adjusted_price`). Pas de nouveau champ de typeId dans la réponse (comme le Pivot Advisor). Une marge inconnue ne passe jamais un filtre de marge minimale (règle existante).
- **R5 — Suppression.** `EsiCostIndexService::calculateEivFromPrices()` n'a plus d'appelant : supprimée avec ses tests.

## Exemples chiffrés

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | Estimateur, NFB (EIV 17 031,184), index 0,05, 1 run, fabrication, taxe 10 % | 3 235,92496 |
| E2 | Estimateur, NFB, index 0,05, taxe `null` | 1 532,80656 |
| E3 | Estimateur, index `null` | null, `missing_cost_index` (typeId sans prix listés quand même) |
| E4 | Estimateur, un adjusted price absent (9848) | null, `missing_adjusted_price`, [9848] |
| E5 | Estimateur, invention, un matériau qté 1 à 10 000 000, index 0,08, taxe 5 % | 34 000 |
| E6 | Estimateur, aucun matériau | 0.0 |
| E7 | Scanner, Shield Extender (`BatchProfitScanRulesTest`) : EIV 19 × 4 + 1 × 9 = 85, index 0,0437 | coût d'installation 85 × 0,0837 = 7,1145 (3,7145) ; marge, profit et ISK/jour recalculés par le test-writer |
| E8 | Scanner, Ferrogel réaction : EIV 190 000, index 0,0713 | 190 000 × 0,1113 = 21 147 (13 547) ; `profitPerUnit` baisse de 76 ISK |
| E9 | Scanner, système sans indice de fabrication | produit gardé sans filtre de marge, marge/profit/ISK/jour null, `unknownReason` `missing_cost_index`, `materialCost` connu |
| E10 | Scanner, matériau sans adjusted price mais avec prix Jita | idem, `missing_adjusted_price` |
| E11 | Scanner, prix Jita absent et indice absent | `missing_material_price` (priorité) |
| E12 | Scanner, système sans indice, `minMarginPercent` donné | produit exclu |

## Impact affiché

Industry Scanner : coûts plus élevés (SCC 4 % sur chaque job), marges et ISK/jour en baisse ; dans un système sans indice, tous les produits passent en « inconnu » et un filtre de marge minimale vide la liste. À annoncer.

## Hors périmètre

Taxe de structure (L3c), copie (#219), `ProductionCostService` et `calculateEiv`/`calculateJobInstallCost` (L5).

## Glossaire

Termes existants : **Coût d'installation**, **EIV**, **Coût inconnu**. Aucun ajout.
