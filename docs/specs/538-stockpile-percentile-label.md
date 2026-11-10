# Spec #538 : une règle de prix commune et sa source affichée (lot 3 sur 4)

- **Statut** : validée en autonomie (mode nuit) sur la décision de Sylvain du 2026-11-10 (option A) et les specs des lots 1 et 2 (`538-common-price-rule.md`, `538-buy-vs-build-pivot.md`, règles 1 à 4 reprises). Lot 3 : Stockpile et étiquette de la BOM et des apports. Lot 4 : exposition API, front, et **étiquettes de l'Évaluateur** (voir Décisions).
- **Circuit** : `l`. Technicité 3 (code Industry, montants affichés modifiés).

Termes en gras : [glossaire](../glossary.md).

## Contexte

`StockpileService` valorise tout au meilleur ordre Jita (`getPricesWithFallback`) : `previewImport` (prix et coût estimé de l'import) et `getStockpileStatus` (valeur du stock, coût du déficit, totaux). La BOM d'un projet (`IndustryProjectService::buildBom`) et les apports de matériaux (`ProjectContributionService::resolveMaterialValue`) sont au **percentile 5 %** figé (#154, #363).

## Règles

1. **Stockpile au prix pondéré sur la quantité réelle** : valeur du stock = `stock × prix pondéré du stock` ; coût du déficit = `déficit × prix pondéré du déficit` ; coût estimé d'un import = somme de `quantité × prix pondéré de la quantité`. Côté vente du carnet Jita, repli ESI (`getWeightedSellQuotesWithFallback` via `WeightedSellQuotes`). Un prix par couple (type, quantité) : stock et déficit d'un même type ont des prix distincts, et un même couple n'est lu qu'une fois.
2. **Prix partiel** : couverture < 1, le montant est calculé et gardé, la couverture est portée dans `priceSources`. Jamais retiré, jamais `null` à cause de la couverture.
3. **Inconnu** : carnet vide, couverture 0 ou absente : prix inconnu (`null`). Un montant dont la quantité est > 0 et le prix inconnu est `null` (inchangé : `stockValue`, `deficitCost`, total d'étape, `totalInvested`, `estimatedCost`) et le type entre dans `missingPriceTypeIds`. Quantité 0 : montant 0, aucun prix demandé, type non manquant. Jamais `?? 1.0` ni repli sur le meilleur ordre.
4. **Une seule lecture du carnet** par appel de `previewImport` et de `getStockpileStatus`, pour toute la liste de couples. Jamais un appel par type.
5. **Champ `unitPrice` conservé** (aucun champ retiré) : prix pondéré de la quantité sur laquelle l'item est valorisé. Statut : le déficit s'il est > 0, sinon le stock s'il est > 0, sinon `null`. Aperçu : prix pondéré de la quantité de la cible.
6. **`priceSources`** (clé ajoutée aux sorties, l'API ne l'expose qu'au lot 4) :
   - statut : chaque item porte `priceSources: {stock, deficit}` ; la racine porte `priceSources: {stockValue, deficitCost}`. Une source est `PriceSource::jitaSellWeighted(couverture partielle ou null)` ; pour la racine, la couverture partielle la plus basse parmi les items valorisés. `null` quand le montant est 0 (quantité 0) ou inconnu. Statut sans cible : les deux de la racine à `null`.
   - aperçu : chaque item porte `priceSource` (source de son `unitPrice`, `null` si inconnu) ; la racine porte `priceSources: {estimatedCost}` (couverture la plus basse, `null` si `estimatedCost` est `null`).
7. **BOM et apports : valeurs inchangées, étiquette seulement.** `PriceSource::jitaSellPercentile(float $percent)` : marché `jita`, côté `sell`, méthode `percentile`, `coveragePercent` `null`, et un champ `percentile` (en pourcents, `5.0`) que `toArray()` ajoute **uniquement** pour cette méthode (les tableaux des lots 1 et 2 ne changent pas). `IndustryProjectService::bomPriceSource()` et `ProjectContributionService::materialContributionPriceSource()` (statiques publiques) renvoient cette étiquette, calculée à partir de la constante de percentile réellement utilisée (`JitaMarketService::CHEAPEST_PERCENTILE`, valeur 0,05, défaut de `getCheapestPercentilePrices` ; `ProjectContributionService::DEPOSIT_DAY_PERCENTILE`). L'étiquette ne peut pas diverger de la valeur.

## Exemples chiffrés (ce seront les tests rouges)

Carnet de vente Jita du type M : 100 à 5,00, 100 à 6,00, 100 à 7,00.

| Cas | Quantité | Avant (meilleur ordre 5,00) | Après (pondéré) | Couverture |
|---|---|---|---|---|
| Stock 100 de M, valeur du stock | 100 | 500 | 5,00 → 500 | complète |
| Cible 300, stock 100, déficit 200 | 200 | 1 000 | 5,50 → 1 100 | complète |
| Cible 500, stock 100, déficit 400 | 400 | 2 000 | 6,00 → 2 400 | 75 % : partiel |
| Stock 150 et déficit 150 (même quantité) | 150 | 750 chacun | 5,3333 → 800 chacun, une seule demande | complète |
| Aperçu d'import, cible M 150 | 150 | 750 | 800 | complète |
| M sans carnet, stock 10 | 10 | `null` | `null`, `missingPriceTypeIds` [M] | `priceSources.stock` `null` |
| M, stock 0 et déficit 0 | 0 | 0 | 0, non manquant | `null` |
| Racine : un item complet et un item à 75 % | | | `stockValue` source à 75,0 | la plus basse |
| BOM, apport matériau | | percentile 5 % | valeurs identiques ; étiquette `percentile` 5,0 | `null` |

## Cas limites

- Même type à deux quantités (stock et déficit) : deux prix, une lecture du carnet.
- Item d'aperçu à quantité ≤ 0 : montant 0, pas de demande, pas manquant.
- Total d'étape, `totalInvested`, `estimatedCost` : `null` dès qu'un montant requis est inconnu (inchangé).
- `suggestedProjectRuns`, statuts, pourcentages, `kpis` hors montants : inchangés.
- Aucune valeur de BOM ni d'apport ne change : mêmes appels à `getCheapestPercentilePrices`.

## Accès et fraîcheur

Aucun nouvel identifiant ni ressource : `StockpileStatusProvider` et `StockpileImportPreviewProcessor` gardent leur accès (utilisateur connecté, cibles de l'utilisateur). Prix du cache Jita (2 h) puis repli ESI à la demande.

## Tests existants modifiés

Les tests qui stubent `getPricesWithFallback` pour le Stockpile stubent `getWeightedSellQuotesWithFallback` (callbacks de `SellQuoteCallbacks`) ; carnets à un ordre : mêmes valeurs. Les tests qui comparent des tableaux complets reçoivent `priceSources` et `priceSource`.

## Décisions

- **Évaluateur au lot 4** : `AppraiseProcessor` garde déjà les prix au meilleur ordre et au prix pondéré (vente et achat) avec couverture, aucune valeur ne change. Étiqueter ses colonnes revient à exposer `priceSources` dans `AppraisalItemResource`, une ressource API : c'est le périmètre du lot 4, qui ajoutera aussi les fabriques `PriceSource` Jita acheteur et meilleur ordre.
- **Forme de l'étiquette percentile** : champ `percentile` ajouté seulement pour la méthode `percentile`, plutôt qu'un nombre dans `method` ou un `coveragePercent` détourné.
- **`unitPrice` du statut** : déficit d'abord, car c'est le prix d'achat réel ; stock sinon.

## Hors périmètre

- Évaluateur (lot 4), ressources API et front (lot 4).
- Valeurs de BOM, d'apports, de surplus ; `getCheapestPercentilePrices`.
- Aucune migration, aucun réglage retiré.

## Glossaire et carte

**Prix pondéré**, **Valeur du stock**, **Coût du déficit**, **Source de prix** (méthode `percentile`, Stockpile). Carte du module : lignes `StockpileService`, `IndustryProjectService`, `ProjectContributionService`, « Prix Jita, structure, meilleur prix ».

## Actions prod

Aucune migration ni commande console. Valeur du stock et coût du déficit du Stockpile modifiés : exemple avant/après dans la PR.
