# Spec #7 (lot L3a) — Coût d'installation de l'invention via le noyau

## Contexte

Voir `.claude/tasks/7-design.md` §L3 et `docs/specs/industry-engine.md` R5/R6. `InventionService::calculateInventionCost()` calcule le coût d'installation de l'invention avec sa propre formule (`0.02 × EIV × index × (1 + taxe/100)`) et une EIV fausse : l'adjusted price du **produit** T2 au lieu des matériaux de fabrication du blueprint T2. Un indice absent donne 0. Seul consommateur : l'onglet BPC Kit (`BpcKitProvider` → `getBpcKitBreakdown`).

La copie reste hors de ce lot (décision Sylvain 2026-10-22, Q3 : D11a non vérifiée en jeu, #219) : `getCopyJobCost()` et `copyCost` ne changent pas.

## Règles métier

- **R1 — Une seule formule.** Le coût d'installation d'une tentative d'invention vient de `EsiCostIndexService::estimateJobInstallCost(matériaux, 1, système, 'invention', taxe)`, qui accepte désormais l'activité `invention` (→ `ActivityKind::Invention`, base = 2 % × EIV, SCC 4 %, taxe additive). Aucune formule dans `InventionService`.
- **R2 — EIV de l'invention.** Matériaux de **fabrication** du blueprint T2 inventé, quantités ME 0 du SDE (`IndustryActivityMaterialRepository::findMaterialsForBlueprints([bp T2], [fabrication])`), 1 run par tentative. Jamais l'adjusted price du produit.
- **R3 — Taxe.** Inchangée : celle que reçoit `calculateInventionCost()` (pourcents, `null` = 0), soit celle de la structure de l'étape racine pour le BPC Kit.
- **R4 — Inconnu.** Indice d'invention absent ou adjusted price absent → `costBreakdown.inventionInstall` null, `costPerAttempt` null, `totalCost` null. `unknownReason` : `missing_invention_probability` d'abord, puis `missing_market_price`, puis le motif du coût d'installation (`missing_cost_index`, `missing_adjusted_price`). Nouveau champ `missingAdjustedPriceTypeIds` (liste triée, sans doublon, vide sinon), distinct de `missingPriceTypeIds` (prix Jita), dans le résultat et dans chaque option de décrypteur.
- **R5 — BPC Kit.** Une option au coût inconnu n'entre pas dans le choix de la moins chère (règle existante) ; toutes inconnues → `totalInventionCost` null.
- **R6 — Front.** `inventionInstall` nullable : le détail du coût affiche « Inconnu » (même rendu que `costPerAttempt`). Types TS : `inventionInstall: number | null`, `missingAdjustedPriceTypeIds: number[]`, `unknownReason` accepte les motifs du coût d'installation.

## Exemples chiffrés

`EsiCostIndexServiceTest` (NFB, EIV 17 031,184 ; ajouter un indice d'invention Jita 0,08 aux fixtures) :

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | NFB, invention, 1 run, Jita, taxe 10 % | 2 % × 17 031,184 = 340,62368 ; × (0,08 + 0,10 + 0,04) = **74,9372096** (méthode refusait l'activité) |
| E2 | idem, taxe `null` | 340,62368 × 0,12 = **40,8748416** |
| E3 | Vecteur §4.5 : un matériau, qté 1, adjusted price 10 000 000, index 0,08, taxe 5 % | **34 000** (16 800) |
| E4 | Invention, système sans indice d'invention | null, `missing_cost_index` |

`InventionServiceTest` (`EsiCostIndexService` mocké, fixtures Sabre du #8 : datacores 800, copie 1 000) :

| # | Cas | Attendu |
|---|---|---|
| E5 | Estimation invention = 500 | `inventionInstall` 500, `costPerAttempt` 2 300, `totalCost` 6 900 (3 tentatives), `missingAdjustedPriceTypeIds` [] |
| E6 | Appel à l'estimation | matériaux de fabrication du blueprint Sabre (22457) tels que rendus par le repository, runs 1, système demandé, `'invention'`, taxe reçue (ex. 10.0) |
| E7 | Estimation inconnue `missing_cost_index` | `inventionInstall`, `costPerAttempt`, `totalCost` null ; `unknownReason` `missing_cost_index` ; `copyCost` inchangé |
| E8 | Estimation inconnue `missing_adjusted_price` [11399, 11478] | idem, motif `missing_adjusted_price`, `missingAdjustedPriceTypeIds` [11399, 11478] |
| E9 | Probabilité absente + estimation inconnue | `missing_invention_probability` |
| E10 | Prix datacore absent + estimation inconnue | `missing_market_price` |
| E11 | BPC Kit, estimation inconnue pour toutes les options | chaque option `totalCost` null, `summary.totalInventionCost` null |

## Cas limites

- L'EIV ne lit plus l'adjusted price du produit T2 pour l'invention (le harnais ne doit plus stubber `getAdjustedPrice` du Sabre pour ce coût).
- Les tests existants qui stubbent `getCostIndex` à null pour « coût d'installation 0 » (ex. `stubKnownCostsPerAttempt`) doivent stubber une estimation connue, sinon leur coût devient inconnu.

## Impact affiché

BPC Kit : coût d'installation de l'invention, coût par tentative et coût total changent (EIV des matériaux du blueprint T2, SCC 4 %, taxe additive) ; inconnu au lieu de 0 sans indice. À annoncer.

## Hors périmètre

Copie (`getCopyJobCost`, `getProjectCopyCosts`, #219), scanner (L3b), taxe Pivot/BvB/scanner (L3c), `ProductionCostService` (L5), affichage du motif par option dans le BPC Kit.

## Glossaire

Termes existants : **Coût d'installation**, **EIV**, **Base de coût de job**, **Surcharge SCC**, **Coût inconnu**. Aucun ajout.
