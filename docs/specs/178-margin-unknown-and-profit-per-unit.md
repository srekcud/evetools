# #178 — Profit Margins : donnée inconnue et profit « par unité »

Groupe `margin-display`, circuit:m, mode nuit. Frontend seul (`ProfitMarginTab.vue`, `profit/CostSummaryCards.vue`). Le backend expose déjà `outputQuantity` (total produit sur tous les runs, `ProfitMarginService`) et des `margins` à `null` quand le prix est inconnu.

## État constaté (audit du 2026-10-22 en partie périmé)

- `effectiveTotalCost` renvoie déjà `null` pour un coût de production inconnu (#192 et antérieurs), `bestProfit` aussi dans ce cas. Rien à faire.
- Restent : (1) `bestProfit` vaut 0 quand aucun lieu de vente n'a de marge connue (`bestVenue` nul) ; (2) `bestVenue.unitPrice` retombe sur `?? 0` ; (3) `profitPerRun` divise le profit par `runs` et la carte l'affiche sous « Par unité ».

## Règles

1. R1. Aucun lieu de vente à marge connue (aucun prix de vente, ou toutes les marges nulles) : `bestProfit` est `null` ; la carte Profit affiche « inconnu » (`industry.inventionUnknown.label`) et non « +0 ». `bestSellRevenue`, `bestMargin` et le prix unitaire restent inconnus comme aujourd'hui.
2. R2. `bestVenue.unitPrice` ne retombe jamais sur 0 : sans prix, le lieu n'est pas retenu (la marge connue implique un prix connu ; le `?? 0` disparaît).
3. R3. Le profit par unité vaut `bestProfit / outputQuantity` (`outputQuantity` = unités produites sur tous les runs). Le calcul est renommé `profitPerUnit` (prop de `CostSummaryCards` comprise), cohérent avec « Par unité » des deux autres cartes.
4. R4. `profitPerUnit` est `null` quand `bestProfit` est `null` ou quand `outputQuantity` vaut 0 (jamais 0 par défaut, jamais division par zéro).
5. R5. Sans résultat (`result` nul) rien n'est affiché, comme aujourd'hui.

## Exemples

| Cas | bestProfit | runs | outputQuantity | Carte Profit | Sous « Par unité » |
|---|---|---|---|---|---|
| produit à 10 unités par run, 2 runs | 1 000 | 2 | 20 | +1.00 K | +50.00 ISK |
| 1 unité par run, 3 runs | 900 | 3 | 3 | +900.00 | +300.00 ISK |
| aucun prix de vente connu | inconnu | 2 | 20 | inconnu | inconnu |
| `outputQuantity` 0 | 1 000 | 1 | 0 | +1.00 K | inconnu |

## Cas limites

- Profit négatif : signe et couleur inchangés, valeur par unité négative divisée de la même façon.
- Decryptor sélectionné localement : `bestProfit` vient déjà du recalcul avec le total de l'option ; la division par `outputQuantity` s'applique à ce profit.
- Coût de production inconnu : `bestProfit` nul, donc profit par unité inconnu (déjà le cas, à garder).

## Hors périmètre

- Les `|| 1` de `effectiveCostPerUnit` et les replis `0` de `effective*` quand `result` est nul (jamais affichés sans résultat).
- Le backend et les libellés i18n (la clé `industry.margins.perUnit` est déjà la bonne).

## Décisions ouvertes

Aucune.
