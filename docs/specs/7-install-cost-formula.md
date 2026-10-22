# Spec #7 (lot L2) — Formule R6 du coût d'installation via le noyau

## Contexte

Voir `.claude/tasks/7-design.md` §L2 et `docs/specs/industry-engine.md` R5/R6. `EsiCostIndexService::estimateJobInstallCost()` (lot L1a) applique encore l'ancienne formule `EIV × runs × index × (1 + taxe/100)` : taxe multiplicative, sans SCC. Le noyau (`InstallCost::forJob`, `EstimatedItemValue::of`) porte déjà la formule R6 ; Profit Margins et Buy vs Build y sont passés (7459fa9, 190308e).

Recensement sur `main` (e937f9c) des appelants de l'ancienne formule :

| Appelant | Méthode | Lot |
|---|---|---|
| `PivotAdvisorService:168` (candidats), `:433` (produit source) | `estimateJobInstallCost` (taxe `null`) | **L2** |
| `ProductionCostService:103-105` | `calculateEiv` + `calculateJobInstallCost` | L5 (après #121) |
| `BatchProfitScannerService:319` | `calculateEivFromPrices` + formule locale | L3b |
| `InventionService` | formule locale | L3a |

L2 se limite donc au corps de `estimateJobInstallCost`, utilisé seulement par le Pivot Advisor.

## Règles métier

- **R1 — Une seule formule.** `estimateJobInstallCost()` calcule l'EIV avec `EstimatedItemValue::of` et le coût avec `InstallCost::forJob` (montant = `total`). Aucune copie de la formule dans `src/Service`.
- **R2 — Formule R6.** base = EIV × runs (fabrication, réaction) ; coût = base × index + base × taxe + base × SCC (4 %). Pas de bonus de rôle (`Multiplier::one()`), pas de taxe alpha (`false`, aucune donnée de clone).
- **R3 — Unité de la taxe.** Le paramètre `$facilityTaxRate` reste en pourcents (10.0 = 10 %), `null` = 0 ; conversion en fraction à la frontière avec le noyau.
- **R4 — Activités.** `manufacturing` → fabrication, `reaction` → réaction. La copie (D11a, #219) et l'invention (L3a) ne passent pas par cette méthode.
- **R5 — Contrat inchangé.** Même forme de retour `{cost, unknownReason, missingAdjustedPriceTypeIds}`, mêmes motifs d'inconnu et même priorité (indice absent avant adjusted price absent, typeId listés sans doublon et triés), blueprint sans matériau → `cost` 0.0.
- **R6 — Pivot.** Le Pivot Advisor garde une taxe `null` dans ce lot ; la taxe de structure (décision Q2) arrive en L3c.

## Exemples chiffrés

Fixtures de `EsiCostIndexServiceTest` : Nitrogen Fuel Block (NFB), EIV 17 031,184 ; Jita fabrication 0,05, réaction 0,04.

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | NFB, 1 run, fabrication Jita, taxe 10 % | 3 235,92496 (936,71512) |
| E2 | NFB, 3 runs, fabrication Jita, taxe `null` | 4 598,41968 (2 554,6776) |
| E3 | NFB, 1 run, fabrication Jita, taxe `null` (cas Pivot) | 1 532,80656 (851,5592) |
| E4 | NFB, 1 run, réaction Jita, taxe 10 % | 3 065,61312 (749,372096) |
| E5 | Robotics sans adjusted price ; système sans indice ; les deux | inchangé (inconnu, motifs et typeId de L1a) |
| E6 | Blueprint sans matériau | 0.0 (inchangé) |

## Cas limites

- Taxe 0 explicite et taxe `null` donnent le même montant.
- La taxe s'applique à la base, pas au terme système (additive) : E1 vaut 17 031,184 × (0,05 + 0,10 + 0,04), pas 17 031,184 × 0,05 × 1,10 + SCC.

## Impact affiché

Pivot Advisor (onglet Pivot de la page Industry) : marge et profit par unité des candidats et du produit source baissent (coût d'installation ×1,8 sans taxe). À annoncer.

## Hors périmètre

- Taxe de structure pour Pivot, BvB, scanner (L3c) ; invention (L3a) ; scanner (L3b) ; `ProductionCostService` et suppression des anciennes méthodes (L5) ; copie (#219).

## Glossaire

Termes existants : **Coût d'installation**, **EIV**, **SCC**, **Coût inconnu**. Aucun ajout.
