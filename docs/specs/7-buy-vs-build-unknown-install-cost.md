# Spec #7 (lot L1b Buy vs Build) — Coût d'installation inconnu dans Buy vs Build

## Contexte

`BuyVsBuildService` chiffre le coût d'installation de chaque composant (`collectBuildableComponents`) et du job final avec `calculateEiv()` + `calculateJobInstallCost()`, qui renvoient 0 quand l'indice de coût ou un adjusted price manque. Le coût de fabrication paraît moins cher, le verdict peut basculer à tort sur `build`, et le coût total est sous-évalué. Le lot L1a (e7715ec) a ajouté `EsiCostIndexService::estimateJobInstallCost()`. Voir `.claude/tasks/7-design.md` (L1b) et la spec #8 Buy vs Build (`docs/specs/8-buy-vs-build-unknown-price.md`), dont ce lot reprend les règles R3-R6.

## Règles métier

- **R1 — Source.** Le coût d'installation d'un composant et celui du job final viennent de `estimateJobInstallCost()` (formule et système inchangés : système de réaction pour une réaction).
- **R2 — Composant.** Coût d'installation inconnu : `buildJobInstallCost` `null` et `buildCost` `null`. Le verdict, l'économie et les totaux suivent ensuite les règles R3-R5 de la spec #8 Buy vs Build (verdict `buy` si l'achat est chiffré, `unknown` sinon).
- **R3 — Job final.** Coût d'installation du job final inconnu : `totalProductionCost` et `marginPercent` `null`.
- **R4 — Motifs.** Le résultat porte `unknownInstallCostReasons` : les motifs (`missing_cost_index`, `missing_adjusted_price`) rencontrés sur les composants et le job final, sans doublon, triés ; `[]` si tout est connu. `missingPriceTypeIds` reste réservé aux prix de marché.
- **R5 — Frontend.** `BuyVsBuildTab.vue` affiche le coût d'installation inconnu « Inconnu »/« Unknown » et la liste des motifs avec un libellé FR/EN (« Indice de coût du système inconnu » / « System cost index unknown » ; « Prix ajusté ESI inconnu » / « ESI adjusted price unknown »).

## Exemples chiffrés

Montants recalculés par le test-writer à partir des fixtures golden Buy vs Build.

| # | Cas | Attendu |
|---|---|---|
| E1 | Tout connu | sortie identique à aujourd'hui, `unknownInstallCostReasons` [] |
| E2 | Système de fabrication sans indice, prix d'achat connus | composants : `buildJobInstallCost`/`buildCost` null, verdict `buy` ; job final inconnu → `totalProductionCost`, `marginPercent` null ; motifs [`missing_cost_index`] |
| E3 | Composant réaction, indice de réaction absent, indice de fabrication présent | seul le composant réaction est inconnu ; le job final (fabrication) reste chiffré |
| E4 | Un matériau d'un composant sans adjusted price | ce composant inconnu, motif `missing_adjusted_price` |
| E5 | Composant sans prix d'achat et coût d'installation inconnu | verdict `unknown`, `optimalMixCost` null |

## Hors périmètre

- Nouvelle formule (L2), taxe d'installation (Q2), Pivot et Profit Margins (autres lots L1b).

## Décision prise en autonomie

Même règle que les lots #8 : un coût qu'on ne peut pas calculer est inconnu, jamais 0.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante**. Aucun ajout.
