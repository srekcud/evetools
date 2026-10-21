# Spec #8 (lot pivot) — Prix de matériau inconnu dans le Pivot Advisor

## Contexte

`PivotAdvisorService` chiffre le produit source et chaque candidat sur 1 run. Un matériau sans prix Jita vaut 0 ISK (`$jitaPrices[...] ?? 0.0`, coût matériaux et couverture) : le candidat paraît moins cher, sa marge et son score sont gonflés, et il peut passer devant des candidats correctement chiffrés. Le scanner a déjà été corrigé de la même façon (ed95dae, `missing_material_price`, `missingPriceTypeIds`). Partie de #8.

## Règles métier

- **R1 — Coût inconnu.** Un candidat dont au moins un matériau n'a pas de prix a `marginPercent`, `profitPerUnit`, `estimatedProfit` et `score` à `null`. Jamais 0.
- **R2 — Couverture.** Si un matériau fabricable du candidat n'a pas de prix, `coveragePercent` et `additionalCost` valent `null`, et le `cost` de ce matériau dans `missingComponents` vaut `null` (sa quantité reste connue).
- **R3 — Motif.** Chaque candidat porte `unknownReason` (`missing_material_price` si R1 s'applique, sinon `null`) et `missingPriceTypeIds` (typeId sans prix, sans doublon, triés ; `[]` sinon). Même vocabulaire que le scanner (`BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE`).
- **R4 — Classement.** Les candidats chiffrés sont triés par score décroissant (inchangé) ; les candidats à score inconnu viennent après, dans l'ordre des typeId croissants. La coupe `MAX_CANDIDATES` s'applique après ce tri.
- **R5 — Produit source.** Si un matériau du produit source n'a pas de prix, sa `marginPercent` est `null`, avec `unknownReason` et `missingPriceTypeIds` selon R3.
- **R6 — Prix de vente absent.** Inchangé : un candidat sans prix de vente est exclu, le produit source garde `marginPercent` null.
- **R7 — Frontend.** `PivotAdvisorTab.vue` affiche les valeurs `null` comme inconnues (jamais 0) et le motif avec le libellé existant du scanner pour `missing_material_price`.

## Exemples chiffrés

Candidat X : matériaux M1 ×10 (prix 5), M2 ×4 (fabricable, prix 50) ; candidat Y : M1 ×10, M3 ×2 (prix absent). ME du service (10 pour T1) appliqué comme aujourd'hui ; les montants exacts sont recalculés par le test-writer à partir de la formule actuelle.

| # | Cas | Attendu |
|---|---|---|
| E1 | X, tous prix connus | marge, score, couverture chiffrés comme aujourd'hui ; `unknownReason` null ; `missingPriceTypeIds` [] |
| E2 | Y, M3 sans prix | marge, profit, score, profit estimé null ; `unknownReason` `missing_material_price` ; `missingPriceTypeIds` [M3] |
| E3 | X et Y | X avant Y quel que soit le score que Y aurait eu avec M3 à 0 |
| E4 | Candidat Z, matériau fabricable M4 sans prix, en stock partiel | `coveragePercent`, `additionalCost` null ; ligne M4 de `missingComponents` avec quantité, `cost` null |
| E5 | Produit source avec un matériau sans prix | `marginPercent` null, `unknownReason` `missing_material_price`, `missingPriceTypeIds` [id] |

## Cas limites

- Même matériau listé deux fois : une seule entrée dans `missingPriceTypeIds`.
- Prix à 0 renvoyé par le marché : prix connu (inchangé).
- Coût d'installation : hors périmètre (#7), inchangé.

## Hors périmètre

- Coût d'installation et EIV (#7), ME par défaut, frais de vente du pivot (#18 hors Profit Margins).
- `BuyVsBuildService`, `ProfitMarginService` hors invention, `GroupIndustryContributionService`.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante**. Aucun ajout.
