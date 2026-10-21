# Spec #7 (lot L1b Profit Margins) — Coût d'installation inconnu dans Profit Margins

## Contexte

`ProfitMarginService::collectJobInstallCosts()` chiffre chaque job (fabrication, réaction) de l'arbre avec `calculateEiv()` + `calculateJobInstallCost()`, qui renvoient 0 quand l'indice de coût ou un adjusted price manque. Le coût total est sous-évalué et les marges gonflées sans que l'écran le dise. Le lot L1a (e7715ec) a ajouté `EsiCostIndexService::estimateJobInstallCost()` ; les lots Pivot (7e64c6a) et Buy vs Build l'utilisent déjà. Voir `.claude/tasks/7-design.md` (L1b) et la spec #8 Profit Margins (`docs/specs/8-profit-margin-unknown-material-price.md`), dont ce lot reprend la propagation.

## Règles métier

- **R1 — Source.** Le coût d'installation de chaque job de l'arbre vient de `estimateJobInstallCost()` (mêmes matériaux ME0, runs, système, activité et taxe qu'aujourd'hui).
- **R2 — Étape.** Un job dont le coût est inconnu a `installCost` `null` dans `jobInstallSteps`, et porte `unknownReason` (`missing_cost_index` ou `missing_adjusted_price`) ; une étape chiffrée porte `unknownReason` `null`.
- **R3 — Total.** Dès qu'une étape est inconnue, `jobInstallCost` vaut `null`, et `totalCost`, `costPerUnit`, les marges, et pour chaque option de décrypteur `totalProductionCost` et `bestMargin` valent `null` (même chemin que le coût matériaux inconnu). `inventionCost` d'une option reste chiffré.
- **R4 — Motifs.** Le résultat porte `unknownInstallCostReasons` : motifs rencontrés, sans doublon, triés ; `[]` sinon. `missingPriceTypeIds` reste réservé aux prix de marché.
- **R5 — Sans système.** Sans système de fabrication choisi (`solarSystemId` null), comportement inchangé (aucune étape, `jobInstallCost` 0) : hors périmètre.
- **R6 — API et frontend.** La resource expose `installCost` nullable et `unknownInstallCostReasons`. `ProfitMarginTab.vue` affiche une installation inconnue « Inconnu »/« Unknown » (étape et total) et les motifs avec les libellés existants `industry.inventionUnknown.missing_cost_index` / `.missing_adjusted_price`.

## Exemples chiffrés

Golden Nitrogen Fuel Block et Hail L (fixtures du test golden) ; montants recalculés par le test-writer.

| # | Cas | Attendu |
|---|---|---|
| E1 | Indice et adjusted prices connus | sortie inchangée, `unknownInstallCostReasons` [] |
| E2 | NFB, système sans indice de fabrication | étape NFB `installCost` null, `missing_cost_index` ; `jobInstallCost`, `totalCost`, marges null |
| E3 | NFB, Robotics sans adjusted price | `missing_adjusted_price` ; total inconnu |
| E4 | Arbre avec réaction (Hail L / Fernite Carbide), indice de réaction absent, fabrication présent | seule l'étape réaction inconnue ; total inconnu |
| E5 | T2 avec décrypteur, installation inconnue | options : `inventionCost` chiffré, `totalProductionCost`/`bestMargin` null |

## Hors périmètre

- Nouvelle formule (L2), taxe (Q2), copie et invention (L3), cas sans système (R5).

## Décision prise en autonomie

Même règle que les lots #8 et L1b précédents.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante**. Aucun ajout.
