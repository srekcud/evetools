# Spec #180 — Marge de l'onglet Estimation des coûts

## Contexte

Preuves de l'issue relues dans le code (2026-11-04) :
- **Runs présentés comme unités** : déjà corrigé par #281 (`outputQuantity` = quantité produite, `sellPricePerUnit = sellPrice / outputQuantity`, entête « N units »), couvert par `costEstimationTabSellPricePerUnit.spec.ts`. Rien à faire.
- **`perUnitShort`** : le message est déjà `{'@'} {price} / unit` (échappement vue-i18n) et se rend, vérifié par les mêmes tests. Rien à faire.
- **Marge** : `CostEstimationTab.vue` calcule encore `profit / sellPrice × 100` (`profitMargin`), alors que le glossaire (**Marge**, spec #19) et l'écran du projet (`IndustryProject::getProfitPercent`, `profitPercent` de `ProjectDetail` et `ProjectTable`) divisent par le coût total. Seul point à corriger.

## Règles métier

- **R1 — Marge.** Dans la comparaison de prix de vente, `marge (%) = profit / coût total × 100` avec `profit = prix de vente − coût total` (montant du profit inchangé).
- **R2 — Coût total nul.** Marge inconnue : l'écran affiche `---`, jamais 0 ni 100 %. Coût total inconnu (`null`) : `---` comme aujourd'hui. Le profit reste affiché quand le coût total est connu.
- **R3 — Prix de vente.** `sellPrice` du projet est le revenu saisi par le joueur, sans lieu de vente : aucun frais de courtage ni taxe n'est appliqué (même base que le profit du projet, backend et écran). Le calcul des frais pour un prix saisi n'est pas décidé : voir décisions ouvertes.

## Exemples chiffrés

| # | Coût total | Prix de vente | Profit | Marge aujourd'hui | Marge attendue |
|---|---|---|---|---|---|
| E1 | 8 000 | 10 000 | +2 000 | 20,0 % | 25,0 % |
| E2 | 12 500 | 10 000 | −2 500 | −25,0 % | −20,0 % |
| E3 | 0 | 10 000 | +10 000 | 100,0 % | `---` |
| E4 | `null` | 10 000 | `---` | `---` | `---` |

## Hors périmètre

- Frais de vente sur un prix saisi (courtage, taxe) : décision produit.
- Marges de Profit Margins, Buy vs Build, scanner (déjà sur `profit / coût total`).
- Quantités et prix par unité (#281).

## Décisions ouvertes

- Appliquer les taux de courtage et de taxe de l'utilisateur au prix de vente saisi ? Option A (retenue) : non, le prix saisi est le revenu net, comme le profit du projet. Option B : appliquer les taux de `FeesConfig` comme Profit Margins, ce qui change aussi le profit affiché et celui du projet.
