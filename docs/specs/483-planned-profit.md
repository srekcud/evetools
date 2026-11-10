# #483 : profit prévu dans la prévision de vente

Décision de Sylvain (2026-11-10, option A) : le profit prévu s'ajoute à la carte « Prévision de vente » avec le coût de la BOM restante au prix du marché ; un prix manquant rend le coût inconnu, jamais 0. Pas de sélecteur de structure dans la carte (point 2 clos sans code : la modale d'édition du projet change la structure et la carte se recharge). Aucune migration, aucune action asynchrone (pas de Mercure).

Spec parente : `docs/specs/50-lot2-sell-forecast.md` (règles 1 à 9 inchangées, sauf la mention « pas de profit prévu » et la forme de `totals`).

## Règles

1. **Coût du reste à produire** (`RemainingBomCost::of(IndustryProject): RemainingCost`, `RemainingCost{?float total, int unknownLineCount}`) : somme, sur les lignes BOM qui ne sont pas des jobs (`isJob` faux), de `getRemainingQuantity() × getEstimatedPrice()`. Le prix est celui qui a valorisé la BOM à la construction (`IndustryProjectService::bomPriceSource()`, Jita, percentile le moins cher) : c'est le calcul de coût existant du projet, pas un second calcul.
   - ligne dont le reste est 0 : contribue 0, son prix n'est pas requis ;
   - ligne dont le reste est positif et le prix nul : ligne inconnue, comptée dans `unknownLineCount` ;
   - `total` nul dès qu'une ligne est inconnue (jamais une somme partielle) ; `total` vaut 0.0 quand aucune ligne n'a de reste (rien à acheter, valeur réelle) ;
   - les jobs (installation, loyer de ligne) ne sont pas dans la BOM : hors du coût, dit sur la carte.
2. **Profit prévu par colonne** (`ProjectSellForecast`) : `structureProfit` = `structureNetRevenue − remainingCost`, `jitaProfit` = `jitaNetRevenue − remainingCost` ; nul dès que le revenu net de la colonne ou le coût est nul. Sans produit à vendre : revenus nuls donc profits nuls. Sans structure : `structureProfit` nul.
3. **Forme** : `totals` gagne `remainingCost` (`float|null`), `remainingCostUnknownLineCount` (`int`), `structureProfit`, `jitaProfit` (`float|null`). `SellForecast` porte les mêmes quatre champs ; `ProjectSellForecastProvider` les recopie dans `totals`. Rien d'autre ne change dans la ressource.
4. **Front** : dans le pied du tableau de `SellForecastCard.vue`, sous la ligne de total :
   - ligne « Coût du reste à produire » (`data-testid="sell-forecast-remaining-cost"`), une seule valeur sur toute la largeur des colonnes de prix : montant, ou `UnknownValue` avec « N ligne(s) de matières sans prix » ;
   - ligne « Profit prévu » (`data-testid="sell-forecast-profit"`), une valeur par colonne (`sell-forecast-profit-structure`, `sell-forecast-profit-jita`), `UnknownValue` quand nulle, colorée en vert si positive et en rouge si négative ;
   - la mention `netRevenueOnly` est remplacée par `profitBasis` : « Profit prévu = revenu net à vendre − coût des matières restantes ; sans installation des jobs ni coûts déjà engagés. » ;
   - types TS `SellForecast.totals` étendus ; aucun autre écran touché.

## Exemples (revenu net structure 4 571 760, Jita 4 000 000)

| Situation | Résultat |
|---|---|
| Matières : 10 requis, 4 déjà apportés à 100 ISK, 3 requis, 0 apporté à 1 000 | `remainingCost` 3 600 ; `structureProfit` 4 568 160 ; `jitaProfit` 3 996 400 |
| Toutes les matières apportées | `remainingCost` 0.0, profit = revenu net |
| Une matière restante sans prix | `remainingCost` nul, `remainingCostUnknownLineCount` 1, profits nuls |
| Une matière sans prix mais entièrement apportée | ignorée, coût connu |
| Revenu structure inconnu (un produit sans ordre) | `structureProfit` nul, `jitaProfit` calculé |
| Projet sans structure | `structureProfit` nul, `jitaProfit` calculé |
| Rien à vendre | `products` vide, profits nuls, `remainingCost` renseigné |

## Cas limites

- Ligne de job (matière intermédiaire fabriquée) : jamais comptée, sa matière est comptée par ses propres feuilles.
- Surplus d'apports (apporté ≥ requis) : reste 0, contribue 0.

## Hors périmètre

Coût déjà engagé (apports approuvés) et profit du projet entier, installation des jobs, sélecteur de structure dans la carte, `FinancialSummary`, seuil sur coût prévu (#346).

## Décisions prises en autonomie

- Coût du reste à produire = reste des lignes de matières de la BOM × prix estimé de la BOM : le calcul de coût de production du noyau (`ProductionCostCalculator`) vaut pour un produit entier, pas pour un reste partiel, et relancerait un plan complet à chaque lecture de la carte.
- Profit prévu = revenu net à vendre − coût du reste à produire (profit « à venir », sans coût engagé) : c'est ce que la décision demande ; le profit du projet entier reste à décider (issue séparée si besoin).
- La clé i18n `netRevenueOnly` (mention « pas de profit prévu », devenue fausse) est remplacée par `profitBasis`.
