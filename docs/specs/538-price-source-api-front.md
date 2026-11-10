# Spec #538 : exposition API et front des sources de prix (lot 4 sur 4)

- **Statut** : validée en autonomie (mode nuit), sur la décision de Sylvain du 2026-11-10 (option A) et les specs des lots 1 à 3 (`538-common-price-rule.md`, `538-buy-vs-build-pivot.md`, `538-stockpile-percentile-label.md`).
- **Circuit** : `l`. Technicité 3 (code Industry). **Aucun montant ne change** : étiquettes seulement.

Termes en gras : [glossaire](../glossary.md).

## Contexte

Les services portent déjà `priceSources` (Scan, Marges, Achat vs Prod, Pivot, Stockpile), `PriceSource::jitaSellPercentile` et les étiquettes statiques de la BOM et des apports. Les ressources API et l'écran ne les exposent pas encore, sauf là où la ressource type un tableau libre (`components`, `candidates`, `stages`). L'Évaluateur affiche déjà prix au meilleur ordre et prix pondérés avec couverture, sans étiquette.

## Règles

1. **Une clé `priceSources` par ressource**, copiée telle quelle depuis la sortie du service, typée (forme de `PriceSource::toArray()`) :
   - `BatchScanResultResource` : `{materials, sale, assumptions}` (copié par `BatchScanProvider::mapToResource`) ;
   - `ProfitMarginResource` : `{materials, sale: {jitaSell, structureSell, structureBuy, contractSell}}` (copié par `ProfitMarginProvider`) ;
   - `BuyVsBuildResource` : racine `{materials, sale}` ; `components[].priceSources` reste dans le tableau `components`, désormais décrit dans le docblock ;
   - `StockpileStatusResource` : racine `{stockValue, deficitCost}` ; `StockpileImportPreviewResource` : racine `{estimatedCost}` ; `priceSources` et `priceSource` d'item restent dans `stages`, décrits dans le docblock ;
   - `PivotAdvisorResource` : `sourceProduct.priceSources` et `candidates[].priceSources` sortent déjà ; le docblock les décrit, aucune clé racine.
2. **BOM et apports** : `ProjectBomLineResource::$estimatedPriceSource` = `IndustryProjectService::bomPriceSource()->toArray()` quand `estimatedPrice` est connu et que la ligne n'est pas un job ; sinon `null`. `ProjectContributionResource::$estimatedValueSource` = `ProjectContributionService::materialContributionPriceSource()->toArray()` pour une contribution de type `material` dont `estimatedValue` est connu ; sinon `null` (jobs, BPC, location de ligne, courrier : saisis ou dérivés, pas de source de marché).
3. **Évaluateur** : trois fabriques `PriceSource` (`jitaSellLowest`, `jitaBuyLowest`, `jitaBuyWeighted(?float)`) ; `jitaSellLowest` : marché `jita`, côté `sell`, méthode `lowest`, couverture `null` ; `jitaBuyLowest` idem côté `buy` ; `jitaBuyWeighted` : côté `buy`, méthode `weighted`. `AppraisalItemResource::$priceSources` = `{sell, buy, sellWeighted, buyWeighted}` ; chaque source est `null` quand le prix de la colonne est `null` ; `sellWeighted` et `buyWeighted` portent la couverture **partielle** (`< 1`) en pourcents (`PriceSource::partialPercentOf`), `null` si complète. Les colonnes « split » se déduisent de `sell`/`buy` et de `sellWeighted`/`buyWeighted` : pas de source propre. Valeurs, arrondis, totaux inchangés. Aucune lecture de carnet de plus.
4. **Rien ne disparaît** : aucun champ retiré, aucun montant modifié, `?? 0` et `?? 1.0` interdits. Une couverture absente reste `null`, jamais 100 %.
5. **Front, un composant `PriceSourceBadge`** (HUD EVE : étiquette courte en capitales, police mono, discrète, pas de pastille SaaS). Entrée : une source `{market, side, method, coveragePercent, percentile?}` ou `null`. `null` : rien d'affiché (le montant inconnu reste `UnknownValue`, jamais 0). Texte : marché + méthode (« Jita · pondéré », « Jita · P5 » pour `percentile` avec la valeur, « Structure · plus bas », « Contrats · médiane », côté achat précisé « Jita achat · pondéré ») ; tronqué avec ellipsis, infobulle avec marché, côté, méthode complète et couverture ; prix partiel : couverture affichée par `PartialPriceMarker` à côté. Textes en i18n fr/en (marché, côté, méthode, infobulle).
6. **Placement** (étiquette à côté du montant ou de l'en-tête de colonne dont elle dit la source) : Scanner (matières, vente), Marges (matières, chaque lieu de vente), Achat vs Prod (matières, vente, achat Jita), Pivot (matières, vente), Stockpile (valeur du stock, coût du déficit, coût estimé de l'aperçu d'import), Évaluateur (en-tête de chaque colonne, couverture partielle par ligne), BOM d'un projet (prix estimé), apports (valeur estimée d'un apport matériau). Un même écran réutilise le même composant ; aucune nouvelle maquette.
7. **Types front** : `PriceSource` et `PriceSources*` dans les `types.ts` des stores concernés ; une source absente d'une réponse ancienne (cache) est traitée comme `null`, jamais plantage.

## Exemples chiffrés (ce seront les tests rouges)

| Cas | Entrée | Sortie attendue |
|---|---|---|
| Scan | ligne avec matières à 75 % | `priceSources.materials` = `{jita, sell, weighted, 75.0}` |
| Marges | lieu structure | `sale.structureSell` = `{structure, sell, lowest, null}` |
| Stockpile statut | stock complet, déficit à 75 % | `priceSources.deficitCost.coveragePercent` 75,0 ; `stockValue` `null` couverture |
| Aperçu d'import | `estimatedCost` inconnu | `priceSources.estimatedCost` `null` |
| BOM | ligne matière, prix 5,00 | `estimatedPriceSource` = `{jita, sell, percentile, null, percentile 5.0}` |
| BOM | ligne job | `estimatedPriceSource` `null` |
| Apport matériau | valeur 500 | `estimatedValueSource` `percentile` 5,0 ; apport `job_install` : `null` |
| Évaluateur | tritanium 100, sell 5,00, buy 4,00, pondéré sell 5,50 à 75 %, pondéré buy complet | `sell` lowest, `buy` lowest, `sellWeighted` 75,0, `buyWeighted` `null` couverture |
| Évaluateur | type sans prix acheteur | `buy` et `buyWeighted` `null`, `buyPrice` `null` |
| Front | source `percentile` 5.0 | « Jita · P5 », infobulle avec marché, côté, méthode |
| Front | couverture 75 | badge + marqueur partiel 75 % |
| Front | source `null` | aucun badge ; le montant inconnu s'affiche « — » |

## Cas limites

- Réponse sans `priceSources` (ancienne version en cache) : rien d'affiché, pas d'erreur.
- Nom de lieu ou de type long : tronqué avec infobulle, la mise en page ne casse pas.
- Évaluateur en erreur de prix (`priceError`) : prix `null`, sources `null`.
- Évaluateur dont aucun type n'est trouvé : `items` vide, rien à étiqueter.

## Accès et fraîcheur

Aucune nouvelle ressource ni route ; mêmes contrôles d'accès (utilisateur connecté, projets et apports de l'utilisateur). Étiquettes issues des mêmes lectures de carnet que les montants : aucun appel marché ni ESI ajouté.

## Hors périmètre

Valeurs, arrondis, totaux ; maquettes nouvelles ; source des totaux de l'Évaluateur (l'en-tête de colonne porte l'étiquette) ; surplus et ventes de projet ; PI ; migration.

## Décisions

- **Pas de source `split`** : moyenne de deux sources déjà étiquetées.
- **Source `null` quand le prix est `null`**, comme les lots 1 à 3.
- **Un seul composant front** plutôt qu'une étiquette par écran : cohérence UX.
- **Pas de découpe 4a/4b** : changements API additifs et courts, front à un composant.

## Glossaire et carte

**Source de prix** : méthodes `lowest` (Jita vente, Jita achat) et `weighted` côté achat, exposition API. Carte `docs/modules/industry.md` : lignes des ressources touchées, `PriceSourceBadge`.

## Actions prod

Aucune migration ni commande console. Aucun montant affiché ne change (PR : à vérifier sur un échantillon).
