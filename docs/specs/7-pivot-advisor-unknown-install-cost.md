# Spec #7 (lot L1b Pivot) — Coût d'installation inconnu dans le Pivot Advisor

## Contexte

`PivotAdvisorService` chiffre le coût d'installation de chaque candidat et du produit source avec `calculateEiv()` + `calculateJobInstallCost()`, qui renvoient 0 quand l'indice de coût du système ou un adjusted price manque. Le coût total est sous-évalué, la marge et le score gonflés. Le lot L1a (e7715ec) a ajouté `EsiCostIndexService::estimateJobInstallCost()`, qui rend ce cas explicite. Voir `.claude/tasks/7-design.md` (L1b) et la spec #8 pivot (`docs/specs/8-pivot-advisor-unknown-material-price.md`), dont ce lot reprend la forme.

## Règles métier

- **R1 — Source.** Le coût d'installation (1 run) d'un candidat et du produit source vient de `estimateJobInstallCost()` (formule inchangée).
- **R2 — Coût inconnu.** Si `cost` est `null`, le candidat a `marginPercent`, `profitPerUnit`, `estimatedProfit` et `score` à `null` ; le produit source a `marginPercent` `null`. La couverture (`coveragePercent`, `additionalCost`, `missingComponents`) ne dépend que des prix des matériaux : inchangée.
- **R3 — Motif.** `unknownReason` : `missing_material_price` si un prix de matériau manque (prioritaire, inchangé), sinon le motif de l'estimation (`missing_cost_index` ou `missing_adjusted_price`), sinon `null`. `missingPriceTypeIds` reste réservé aux prix de marché.
- **R4 — Classement.** Inchangé (spec #8 pivot R4) : score inconnu après les scores connus, par typeId croissant.
- **R5 — Frontend.** `PivotAdvisorTab.vue` affiche les deux nouveaux motifs avec un libellé FR/EN (« Indice de coût du système inconnu » / « System cost index unknown » ; « Prix ajusté ESI inconnu » / « ESI adjusted price unknown »).

## Exemples chiffrés

Les montants sont recalculés par le test-writer à partir des fixtures existantes du pivot.

| # | Cas | Attendu |
|---|---|---|
| E1 | Indice et adjusted prices connus | sortie identique à aujourd'hui |
| E2 | Système sans indice de fabrication | candidats et source : marge, score inconnus ; `unknownReason` `missing_cost_index` ; couverture chiffrée |
| E3 | Un matériau d'un candidat sans adjusted price | ce candidat inconnu (`missing_adjusted_price`), les autres chiffrés et classés devant |
| E4 | Matériau sans prix de marché et indice absent | `missing_material_price` (priorité) |
| E5 | Candidat réaction, indice de réaction absent mais indice de fabrication présent | candidat réaction inconnu, candidats fabrication chiffrés |

## Hors périmètre

- Nouvelle formule (L2), taxe d'installation (Q2 de la note #7), autres appelants (Buy vs Build, Profit Margins : lots L1b suivants).

## Décision prise en autonomie

Même règle que les lots #8 : un coût qu'on ne peut pas calculer est inconnu, jamais 0.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante** (motifs `adjusted_price`, `cost_index`). Aucun ajout.
