# Spec #538 : une règle de prix commune et sa source affichée (lot 2 sur 4)

- **Statut** : validée en autonomie (mode nuit) sur la décision de Sylvain du 2026-11-10 (option A) et la spec du lot 1 (`docs/specs/538-common-price-rule.md`, règles 1 à 5 reprises telles quelles). Lot 2 : Achat vs Prod et Pivot. Lots suivants, une PR chacun : 3 Stockpile, étiquette BOM, Évaluateur ; 4 exposition API et front.
- **Circuit** : `l`. Technicité 3 (code Industry, montants affichés modifiés).

Termes en gras : [glossaire](../glossary.md).

## Contexte

Achat vs Prod coûte déjà matériaux et composants au **prix pondéré** avec **couverture du carnet** (`ProductionCostCalculator` via `JitaMarketPrices`) ; seule la vente du produit (`BuyVsBuildService::sellPriceOf`) prend le meilleur ordre Jita. Pivot valorise tout au meilleur ordre (`getPricesWithFallback` : matériaux, vente, besoin en stock), sans couverture, et un appel carnet par analyse reste le meilleur ordre quelle que soit la quantité.

## Règles

Valables pour les deux services. `PriceSource`, `getWeightedSellQuotesWithFallback`, `PriceSource::coverageOfQuote` et `partialPercentOf` viennent du lot 1.

1. **Quantité réelle, pondéré, repli ESI, couverture** : tout prix Jita d'une quantité connue est le prix pondéré de cette quantité (arrondie au supérieur), côté vente, avec repli ESI. Couverture < 1 : **prix partiel**, montant gardé et marqué, jamais retiré.
2. **Inconnu** : carnet vide, couverture 0, clé `coverage` absente, quantité ≤ 0 : prix inconnu (`null`). Pas de `?? 1.0`, pas de repli sur le meilleur ordre.
3. **Un seul accès au carnet par appel** de `analyze()` pour tout le Pivot (matériaux, besoins, vente, source comprise). Jamais un appel par type ni par candidat.
4. **Chaque service garde ses champs** : aucun champ, réglage ni constante retiré. `priceSources` est ajouté à la sortie (l'API ne l'expose qu'au lot 4).

## Achat vs Prod (`BuyVsBuildService`)

5. **Coûts matériaux, composants, `buyCostJita`** : déjà au pondéré sur la quantité réelle avec couverture (`JitaMarketPrices`), valeurs inchangées. Un test le fige (vérifié dans le code, pas de changement de production).
6. **Vente du produit** : prix le plus bas de la structure de vente choisie quand elle existe, sinon prix pondéré Jita sell sur la quantité produite (`runs × sortie par run`, `rootCost->producedQuantity`), avec repli ESI. `sellPrice` reste le prix unitaire arrondi à 2 décimales ; quantité produite de 1 : égal à l'ancien meilleur ordre.
7. Vente à couverture partielle : `sellPrice` et `marginPercent` calculés et gardés, couverture dans `priceSources.sale`. Vente inconnue (règle 2) : `sellPrice` et `marginPercent` à `null`, `priceSources.sale` à `null`.
8. `priceSources` de l'analyse : `materials` (jita, sell, weighted, couverture la plus basse de `warnings`, `null` si aucun) et `sale` (règle 7 ; structure → `structureSellLowest`, Jita → `jitaSellWeighted` avec couverture partielle ou `null`).
9. `priceSources` de chaque composant : `build` (jita, sell, weighted, couverture la plus basse de ses `warnings`), `buyJita` (jita, sell, weighted, `buyCostJitaCoveragePercent`), `buyStructure` (`structureSellLowest`, ou `null` quand `buyCostStructure` est `null`).
10. Le prix structure d'un composant (`buyCostStructure`, plus bas ordre) ne change pas : c'est le lieu choisi.

## Pivot (`PivotAdvisorService`)

11. **Matériaux d'un run** : coût d'un candidat ou du produit source = somme sur ses matériaux de `quantité ME ajustée d'un run × prix pondéré de cette quantité`. Un matériau sans prix (règle 2) : coût `null`, `missingPriceTypeIds`, `unknownReason` inchangés.
12. **Vente** : `sellPrice` d'un candidat ou du produit source = prix pondéré Jita sell sur `outputPerRun`. Pas de prix (règle 2) : candidat écarté, source `sellPrice` à `null` (comportements actuels). Marge, profit, score gardent leurs formules.
13. **Besoin en stock** (`runs` demandés, matériaux constructibles du produit source) : valeur du besoin = `besoin × prix pondéré du besoin` ; valeur couverte par le stock = `min(stock, besoin) × même prix unitaire` (le `coveragePercent` des candidats reste un ratio de valeurs) ; `missingComponents[].cost` et `additionalCost` = `quantité manquante × prix pondéré de la quantité manquante` (c'est l'achat réel). Prix du besoin inconnu : `coveragePercent` et `additionalCost` `null` comme aujourd'hui ; prix de la quantité manquante inconnu : `cost` `null`.
14. **Prix partiel** : un candidat ou le produit source dont un prix (matériau d'un run, besoin, quantité manquante, vente) a une couverture < 1 reste calculé et porte la couverture dans `priceSources`. Il n'est ni écarté ni inconnu.
15. `priceSources` d'un candidat et du `sourceProduct` : `materials` (jita, sell, weighted, couverture la plus basse parmi tous les prix de matériaux utilisés pour ce produit, `null` si complets), `sale` (jita, sell, weighted, couverture de la vente ou `null`), `assumptions` : `{runs: 1, materialEfficiency}` (le coût et la vente sont évalués sur un run, comme le Scan). `emptyResult` n'a pas de `priceSources`.
16. Le cas « source sans matériau constructible » (`buildResultWithSourceOnly`) suit les mêmes règles avec une lecture du carnet pour le produit source.

## Exemples chiffrés (ce seront les tests rouges)

Carnet de vente Jita : matériau M 100 à 5,00, 100 à 6,00, 100 à 7,00 ; produit P 100 à 10,00, 100 à 12,00.

| Cas | Quantité | Avant (meilleur ordre) | Après (pondéré) | Couverture |
|---|---|---|---|---|
| Achat vs Prod, vente P, 100 produits | 100 | 10,00 | 10,00 | complet |
| Achat vs Prod, vente P, 150 produits | 150 | 10,00 | (1 000 + 600) / 150 = 10,6667 | complet |
| Achat vs Prod, vente P, 300 produits | 300 | 10,00 | 11,00 | 66,7 % : partiel |
| Achat vs Prod, structure à 11,00, 150 produits | 150 | 11,00 | 11,00 (étiquette structure) | aucune |
| Achat vs Prod, P sans carnet | 10 | `null` | `null` | `sale` `null` |
| Pivot, matériau M, 150 par run | 150 | 5,00 → coût 750 | 5,3333 → coût 800 | complet |
| Pivot, vente P, 150 par run | 150 | 10,00 | 10,6667 | complet |
| Pivot, besoin 300 de M, stock 100, `runs` 2 | 300 | valeur 1 500, manque 200 × 5,00 = 1 000 | prix du besoin 6,00 (valeur 1 800, couverte 600, coverage 33,33 %), manque 200 × 5,50 = 1 100 | complet |
| Pivot, matériau M, 400 par run | 400 | coût 2 000 | 6,00 → coût 2 400 | 75 % : partiel |
| Pivot, M sans carnet | 10 | `null` | `null` | `missing_material_price` |

## Cas limites

- Quantité fractionnaire arrondie au supérieur avant l'appel ; quantité ≤ 0 : prix inconnu.
- Même typeId à deux quantités dans une analyse Pivot : deux prix distincts, une seule lecture du carnet.
- `buyCostStructure` `null` : `priceSources.buyStructure` `null`, `buyCostJita` reste la référence.
- Structure de vente sans prix pour le produit : la vente retombe sur Jita pondéré (comme aujourd'hui sur le meilleur ordre).

## Accès et fraîcheur

Aucun nouvel identifiant ni ressource : `BuyVsBuildProvider` et `PivotAdvisorProvider` gardent leur accès (utilisateur connecté). Prix du cache Jita (2 h) puis repli ESI à la demande, comme au lot 1.

## Tests existants modifiés

Les tests Pivot qui stubent `getPricesWithFallback` stubent désormais `getWeightedSellQuotesWithFallback` (callbacks de `SellQuoteCallbacks`) ; carnets à un ordre : mêmes valeurs. Le support `BuildsBuyVsBuildOnGoldenSde` stube aussi la méthode pour la vente.

## Hors périmètre

- Stockpile, BOM, Évaluateur (lot 3) ; exposition API et front (lot 4).
- Prix structure des composants, taxes, coûts d'installation, volumes journaliers, invention : inchangés.
- Vente au prix d'un ordre d'achat : non retenue. Aucune migration.

## Glossaire

Mettre à jour **Prix pondéré** (Achat vs Prod et Pivot alignés, lot 2) et **Source de prix** (`priceSources` aussi dans `BuyVsBuildService::analyze` et `PivotAdvisorService::analyze`). Carte du module : lignes `BuyVsBuildService`, `PivotAdvisorService`, « Prix Jita, structure, meilleur prix ».

## Actions prod

Aucune migration ni commande console. Montants de vente d'Achat vs Prod et montants du Pivot modifiés : exemple avant/après dans la PR.
