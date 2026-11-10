# Spec #538 : une règle de prix commune et sa source affichée (lot 1 sur 4)

- **Statut** : validée en autonomie (mode nuit) sur la décision de Sylvain du 2026-11-10 (option A, base de vente « structure sell, sinon Jita sell pondéré »). Lot 1 : Marges et Scan. Lots suivants, une PR chacun : 2 Achat vs Prod et Pivot ; 3 Stockpile, BOM (étiquette seulement) et Évaluateur ; 4 front (étiquettes).
- **Circuit** : `l`. Technicité 3 (code Industry, montants affichés modifiés).

Termes en gras : [glossaire](../glossary.md). Ajouté par cette spec : **Source de prix**.

## Contexte

Marges valorise matériaux et vente en **prix pondéré** sur la quantité réelle (`ProductionCostCalculator` via `JitaMarketPrices`, `ProfitMarginService::sellPrices`). Le Scan valorise tout au meilleur ordre Jita (`BatchProfitScannerService`, `getPricesWithFallback`). Un même produit donne deux marges selon l'écran. Aucun écran n'affiche la méthode, le côté ni la couverture de ses prix.

## Règle commune (décision du 2026-11-10)

1. **Coût ou besoin d'une quantité connue : prix pondéré** sur la quantité réelle, côté vente du carnet Jita, avec repli ESI à la demande (`getWeightedSellPricesWithFallback`), et **couverture du carnet** (#147). Un prix à couverture < 1 est un **prix partiel** : montant affiché, marqué, jamais retiré.
2. **Vente du produit** : prix du lieu choisi. Jita : pondéré Jita sell sur la quantité produite (avec repli ESI). Structure : plus bas ordre de vente de la structure (inchangé). Contrats : médiane des ventes (inchangé, Scan seulement).
3. **BOM et contributions** : hors de ce lot, restent au percentile 5 % figé (#154, #363).
4. **Source de prix** : chaque montant de Marges et de Scan porte `{market, side, method, coveragePercent}`.
   - `market` : `jita`, `structure`, `contracts`.
   - `side` : `sell` ou `buy`.
   - `method` : `weighted`, `lowest`, `median`.
   - `coveragePercent` : `null` si complet, sinon couverture × 100 arrondie à 1 décimale et bornée à [0,1 ; 99,9] (même règle que `PartialPricePresenter`, #147 R10). Pour plusieurs matériaux, la plus basse (pire cas).
5. Le Scan garde ses hypothèses propres (ME 10, ou 2 pour un T2 ; 1 run ; coût de transport) et les expose : `assumptions: {runs, materialEfficiency, ...}` tirées de ses valeurs actuelles, sans nouveau réglage.

## Marges (`ProfitMarginService`)

6. `sellPrices()` lit le prix Jita sell pondéré avec repli ESI (comme les matériaux), au lieu de `getWeightedSellPrice` sans repli. Un type absent du cache synchronisé n'est plus « sans prix » quand l'ESI le sert.
7. `analyze()` expose `priceSources` : `materials` (jita, sell, weighted, couverture la plus basse des `warnings`), `sale.jitaSell` (jita, sell, weighted, `jitaSellCoveragePercent`), `sale.structureSell` (structure, sell, lowest, null), `sale.structureBuy` (structure, buy, lowest, null), `sale.contractSell` (contracts, sell, lowest, null).
8. Montants, marges, `missingData` et `warnings` de Marges ne changent pas, hormis le repli ESI de la règle 6.

## Scan (`BatchProfitScannerService`)

9. **Matériaux** : le prix unitaire d'un matériau est le prix pondéré sur la quantité du Scan pour ce produit (`adjustedQty` d'un run, après ME), jamais le meilleur ordre. Deux produits qui consomment le même matériau en quantités différentes peuvent donc avoir deux prix unitaires.
10. **Vente Jita** : `sellPrice` = pondéré Jita sell sur `outputPerRun`, avec repli ESI. Vente structure et contrats inchangées (règle 2).
11. **Un seul accès au carnet pour tout le Scan** : le service Jita expose une méthode qui pondère plusieurs paires (typeId, quantité) sur une seule lecture du carnet en cache, plusieurs quantités possibles pour un même typeId. Le Scan n'appelle jamais `getWeightedSellPrices` une fois par produit.
12. **Prix partiel** : une ligne du Scan dont un matériau ou la vente a une couverture < 1 reste calculée et porte `priceSources.materials.coveragePercent` ou `priceSources.sale.coveragePercent`. Elle n'est ni écartée ni inconnue.
13. **Inconnu** : un matériau sans prix pondéré (carnet vide, couverture 0, couverture absente de la réponse) reste un prix manquant (`missing_material_price`, `missingPriceTypeIds`). Pas de `?? 1.0`, pas de repli sur le meilleur ordre.
14. Chaque ligne expose `priceSources` : `materials` (jita, sell, weighted, couverture), `sale` (selon le lieu : jita sell weighted / structure sell lowest / contracts sell median), et `assumptions` (règle 5).
15. **Critère testable** : pour un même produit, un run, ME 10, mêmes matériaux et même carnet Jita, le prix unitaire de chaque matériau et le prix de vente Jita du Scan égalent ceux de Marges (`materials` et `sellPrices.jitaSell`).

## Exemples chiffrés (ce seront les tests rouges)

Carnet de vente Jita du type M : 100 à 5,00, 100 à 6,00, 100 à 7,00 (300 unités). Carnet du produit P : 100 à 10,00, 100 à 12,00 (200 unités).

| Cas | Quantité | Avant (meilleur ordre) | Après (pondéré) | Couverture |
|---|---|---|---|---|
| Matériau M, besoin 150 | 150 | 5,00 → coût 750 | (500 + 300) / 150 = 5,3333 → coût 800 | 100 % : complet |
| Matériau M, besoin 300 | 300 | 5,00 → 1 500 | 6,00 → 1 800 | complet |
| Matériau M, besoin 400 | 400 | 5,00 → 2 000 | 6,00 → 2 400 | 300/400 = 75 % : partiel |
| Vente P, 100 unités par run | 100 | 10,00 | 10,00 | complet |
| Vente P, 150 par run | 150 | 10,00 | (1 000 + 600) / 150 = 10,6667 | complet |
| Vente P, 300 par run | 300 | 10,00 | 11,00 | 200/300 = 66,7 % : partiel |
| M sans carnet | 10 | `null` | `null` | `missing_material_price` |

Marge Scan, un run, P à 150 par run, matériau M à 150, sans transport ni installation, frais 0 : coût 800, vente 150 × 10,6667 = 1 600, marge 100 % (avant : 150 × 10,00 = 1 500 sur 750 = 100 % : même pourcentage, montants différents). Un second cas avec M à 300 et P à 150 donne coût 1 800, vente 1 600, marge − 11,1 % (avant : 1 500 sur 1 500 = 0 %).

## Cas limites

- Quantité fractionnaire : arrondie au supérieur avant l'appel (comme `JitaMarketPrices`).
- Quantité ≤ 0 : pas de prix, matériau inconnu.
- Carnet sans clé `coverage` : traité comme absent, inconnu.
- Structure inconnue ou sans ordre : `sale` vaut `null`, vente inconnue (inchangé).
- Même typeId à deux quantités dans un même appel batch : deux résultats distincts.

## Accès et fraîcheur

Aucun nouvel identifiant reçu, aucune ressource nouvelle : les endpoints du Scan et de Marges gardent leur accès. Les prix viennent du cache Jita synchronisé (2 h) puis du repli ESI à la demande (5 min), comme aujourd'hui.

## Tests existants modifiés

- `tests/Unit/Service/Industry/BatchProfitScannerServiceTest.php` : tout test qui fige un prix au meilleur ordre (matériau ou vente Jita) change de valeur pour les carnets multi-ordres ; les carnets à un ordre ne changent pas.
- `ProfitMarginGoldenCharacterizationTest`, `ProfitMarginServicePartialPriceTest` : inchangés, sauf le mock du repli ESI de la règle 6.
- Tout test fonctionnel de l'API Scan qui fige `sellPrice` : à relire.

## Hors périmètre

- Achat vs Prod, Pivot, Stockpile, Évaluateur, étiquette BOM (lots 2 et 3) ; affichage front des étiquettes (lot 4).
- Vente au prix d'un ordre d'achat (Jita buy) : non retenue.
- Percentile 5 % de la BOM et des contributions : inchangé, aucune migration.
- Aucun réglage ni champ retiré : `sellPrice`, `materialCost`, `jitaSellCoveragePercent` gardent leur nom.

## Glossaire

Ajouter **Source de prix** (Price source) : étiquette `{market, side, method, coveragePercent}` jointe à un montant de Marges ou du Scan, qui dit d'où vient son prix. Modifier **Prix pondéré** : règle commune de Marges et du Scan depuis #538.

## Actions prod

Aucune migration ni commande console. Montants du Scan modifiés : à annoncer dans la PR avec l'exemple avant/après ci-dessus.
