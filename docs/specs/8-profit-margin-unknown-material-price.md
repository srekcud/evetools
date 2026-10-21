# Spec #8 (lot Profit Margins) — Prix de matériau inconnu dans Profit Margins

## Contexte

`ProfitMarginService::analyze()` prix les matériaux achetés (feuilles de l'arbre) au prix pondéré Jita. Un matériau sans prix vaut 0 (`$priceData['weightedPrice'] ?? 0.0`) : le coût matériaux, le coût total et toutes les marges sont sous-évalués sans que l'écran le dise. Le test golden fige ce comportement (Robotics à 0). Les lots invention, pivot, Buy vs Build et Group Industry de #8 sont déjà corrigés ; celui-ci est le dernier hors coût d'installation (#7).

## Règles métier

- **R1 — Ligne de matériau.** Un matériau sans prix pondéré a `unitPrice` et `totalPrice` à `null`.
- **R2 — Coût matériaux inconnu.** Dès qu'un matériau n'a pas de prix, `materialCost` vaut `null`.
- **R3 — Propagation.** `materialCost` inconnu rend `totalCost`, `costPerUnit` et les quatre marges `null` (même chemin qu'un coût d'invention inconnu). Pour chaque option de décrypteur : `totalProductionCost` et `bestMargin` `null` ; `inventionCost` reste celui de l'option.
- **R4 — Types sans prix.** Le résultat porte `missingPriceTypeIds` : matériaux sans prix, sans doublon, triés ; `[]` sinon. Le bloc `invention` garde ses propres `missingPriceTypeIds`.
- **R5 — API et frontend.** La resource expose les champs nullables et `missingPriceTypeIds`. `ProfitMarginTab.vue` affiche les montants `null` comme inconnus (jamais 0), y compris le recalcul par lieu de vente (`venueMargin`) quand on choisit un décrypteur, et liste les matériaux sans prix.

## Exemples chiffrés

Golden Nitrogen Fuel Block 1 run ME 10 (fixtures du test golden). Les montants sont recalculés par le test-writer.

| # | Cas | Attendu |
|---|---|---|
| E1 | Tous les matériaux pricés | sortie inchangée, `missingPriceTypeIds` [] |
| E2 | Robotics sans prix (cas actuel du golden) | ligne Robotics `unitPrice`/`totalPrice` null ; `materialCost`, `totalCost`, `costPerUnit`, marges null ; `missingPriceTypeIds` [Robotics] |
| E3 | T2 avec décrypteur choisi, un matériau sans prix | options : `inventionCost` chiffré, `totalProductionCost` et `bestMargin` null |
| E4 | Deux matériaux sans prix | les deux typeId, triés |
| E5 | Prix pondéré à 0 renvoyé par le marché | prix connu (inchangé) |

## Hors périmètre

- Coût d'installation (#7), décrypteur sans effet sur le ME, nombre de succès d'invention (#18), migration sur le noyau.

## Décision prise en autonomie

Spec écrite en mode boucle, même règle que les autres lots de #8.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante**. Aucun ajout.
