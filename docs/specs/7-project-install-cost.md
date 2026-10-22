# Spec #7 (lot L5) — Coût d'installation des projets via le noyau, suppression de l'ancienne formule

## Contexte

Voir `.claude/tasks/7-design.md` §L5. `ProductionCostService::estimateJobInstallCosts()` est le dernier appelant de l'ancienne formule (`EsiCostIndexService::calculateEiv()` + `calculateJobInstallCost()` : taxe multiplicative, sans SCC, 0 quand l'indice ou un adjusted price manque). Consommateurs : `CostEstimationProvider` (onglet Estimation des coûts d'un projet, via `estimateTotalCost`) et `CreateProjectProcessor` (snapshot `IndustryProject::estimatedJobCost` à la création). #121 est fusionnée (e937f9c).

## Règles métier

- **R1 — Formule.** Chaque étape fabrication/réaction : `EsiCostIndexService::estimateJobInstallCost(matériaux ME 0 du blueprint, runs de l'étape, système de l'étape, activité, taxe)`. Système de l'étape : inchangé (structure de l'étape, sinon système favori de l'activité, sinon Jita).
- **R2 — Taxe.** Structure assignée à l'étape : sa taxe (`IndustryStructureConfig::getFacilityTaxRate()`, pourcents ; station NPC sans taxe saisie = 0,25, structure joueur sans taxe saisie = 0, comportements existants). Étape **sans structure** : `FacilityTaxRateResolver::percentFor(utilisateur du projet, système de l'étape, activité)` (décision Q2, comme Profit Margins ; NPC 0,25 % sans structure capable).
- **R3 — Inconnu.** Étape à coût inconnu : `installCost` null, `costIndex` null si l'indice manque (plus de `?? 0.0`), `unknownReason` (`missing_cost_index`, `missing_adjusted_price`) et `missingAdjustedPriceTypeIds` par étape (null / [] si connue). Une étape inconnue rend `total` (et `jobInstallCost` de l'estimation) null, donc `totalCost` et `perUnit` null ; `materialCost` reste tel quel. `CreateProjectProcessor` enregistre alors `estimatedJobCost` null.
- **R4 — Suppression.** `EsiCostIndexService::calculateEiv()`, `calculateJobInstallCost()`, `applyInstallCostFormula()` et ce qui n'a plus d'usage (constante `PERCENT`) sont supprimés avec leurs tests (dont `testCalculateJobInstallCostCurrentlyReturnsZeroWithoutCostIndexIssue7`). Critère `industry-engine.md:340` : une seule implémentation de R6 dans `src/`.
- **R5 — Front (onglet Estimation des coûts).** Types : `CostEstimation.jobInstallCost: number | null`, `CostEstimationStep.costIndex: number | null`, `installCost: number | null`, `unknownReason`, `missingAdjustedPriceTypeIds`. Affichage « Inconnu » (libellé existant `industry.costEstimation.unknown`) pour le total des jobs, l'index et le coût d'une étape inconnue ; la répartition en pourcentages n'est pas affichée si le total des jobs est inconnu ; « enregistrer les coûts dans le projet » n'envoie pas `jobsCost` inconnu (bouton désactivé si `jobInstallCost` null).

## Exemples chiffrés

`FacilityTaxInstallCostTest` (cache réel, Jita fabrication 0,0625) :

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | Rifter, 4 runs, station NPC de Jita sans taxe saisie (0,25 %) ; base 80 000 | 80 000 × (0,0625 + 0,0025 + 0,04) = **8 400** (5 012,5) |
| E2 | Merlin, 5 runs, Raitaru de Perimeter sans taxe saisie (0 %) ; base 60 000, index 0,125 | 7 500 + 0 + 2 400 = **9 900** (7 500) |
| E3 | Rifter, étape sans structure, utilisateur sans structure configurée | taxe NPC : **8 400** (5 000) |
| E4 | Rifter, étape sans structure, l'utilisateur a une Raitaru à 5 % dans le système | 80 000 × (0,0625 + 0,05 + 0,04) = **12 200** (5 000) |
| E5 | Étape dans un système sans indice | `installCost` null, `costIndex` null, `missing_cost_index` ; `total` null |
| E6 | Matériau du blueprint sans adjusted price (typeId listé) | `installCost` null, `missing_adjusted_price`, typeId listé ; `total` null |
| E7 | Deux étapes, l'une inconnue | `total` null, l'autre étape garde son montant |
| E8 | `estimateTotalCost` avec une étape inconnue | `jobInstallCost`, `totalCost`, `perUnit` null ; `materialCost` connu |
| E9 | Création de projet dont une étape est inconnue | `estimatedJobCost` null |

`ProductionCostServiceTest` : les attendus existants sont recalculés par le test-writer (R6 + taxe R2).

## Cas limites

- Les étapes copie / invention ne sont pas comptées ici (comportement inchangé).
- Une étape achetée ou en stock est encore comptée : c'est #127, lot séparé qui suit.

## Impact affiché

Onglet Estimation des coûts d'un projet et coût de jobs estimé à la création (liste et détail des projets) : ×1,6 à ×2,5 selon l'indice et la taxe, « Inconnu » sans indice au lieu de 0. Les projets existants gardent leur snapshot `estimatedJobCost` (pas de recalcul). À annoncer.

## Hors périmètre

Liste d'achats (thread #173/#177), étapes achetées ou en stock (#127, lot suivant), copie (#219), bonus de rôle.

## Glossaire

Mettre à jour la ligne « Code » de **EIV** et **Coût d'installation** (méthodes supprimées) et retirer l'ambiguïté « Coût d'installation » / « EIV avec prix manquant » devenue caduque si plus aucun code ne l'implémente.
