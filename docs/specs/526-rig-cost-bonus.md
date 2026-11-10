# #526 — Bonus de rig de coût sur le coût d'installation

Spec nuit (circuit:m, niveau 3 : code Industry, montants affichés modifiés). Suite de l'enquête #219.

## Règles

1. **Donnée** : l'attribut dogma 2595 (`attributeEngRigCostBonus`) porte la réduction de coût du rig, négatif dans le SDE (`-10` rig I, `-12` rig II), lu par `SdeRigBonusReader::costBonusOf()` en `RigBonus` (positif), avec les modificateurs de sécurité 2355 / 2356 / 2357. Attribut absent : `null`, jamais 0. Les rigs de fabrication portent 2595 = 0 : ils ne changent rien.
2. **Rig de coût** : un rig dont la réduction de coût est > 0 devient un `StructureRig` sans matériaux ni catégorie ciblée, qui porte `costBonus` (`StructureRig::costOnly()`). Dans le catalogue, seuls les « Standup M/L/XL-Set Laboratory Optimization I/II » existent ; la lecture reste celle du SDE, sans table codée en dur.
3. **Multiplicateur** : `ProductionStructure::installCostMultiplierFor($activity)` = bonus de rôle de coût × Π `costBonus->multiplierIn(sécurité)` des rigs. Les rigs de coût ne s'appliquent qu'aux activités de laboratoire (copie, invention, recherche ME, recherche TE). Fabrication et réaction : bonus de rôle seul (le SDE donne 0 aux rigs de fabrication ; un rig de laboratoire n'agit pas sur une fabrication).
4. **Où il s'applique** : au terme du cost index uniquement (`InstallCost` le reçoit déjà comme `structureCostModifier`), jamais à la taxe d'installation ni à la SCC. Les consommateurs de `costRoleBonus` passent à `installCostMultiplierFor()` : `ProductionCostCalculator::installCost`, `StructureTotalCostComparator::installCost`, `ResearchProfitability::researchCostOf`, `StructureSelector::installRateOf` (le taux d'installation qui classe les structures).
5. **Station NPC** : pas de structure, pas de rig, `Multiplier::one()` inchangé.

## Exemple chiffré (relevé en jeu de #219)

Copie, EIV 2 694 500 (base 2 % = 53 890), cost index 0,0743, taxe 1 %, Sotiyo (rôle × 0,95), rig de laboratoire II (−12) en nullsec (× 2,1) :

| Terme | Avant | Après |
|---|---|---|
| Rig | ignoré | 1 − 12 × 2,1 / 100 = 0,748 |
| Système | 53 890 × 0,0743 × 0,95 = 3 804 | 53 890 × 0,0743 × 0,748 × 0,95 = 2 845 (en jeu : 2 846) |
| Taxe 1 % | 539 | 539 |
| SCC 4 % | 2 156 | 2 156 |
| Total | 6 498 | 5 540 (en jeu : 5 541, écart d'arrondi du cost index) |

## Cas limites

- Rig de laboratoire en highsec : modificateur 1,0 (rig II : 0,88) ; lowsec 1,9.
- Rig sans attribut 2595 (données de test anciennes) : aucun rig de coût, comportement inchangé.
- Deux rigs de coût : les multiplicateurs se multiplient.

## Hors périmètre

- `JobInstallCostEstimator` (scanner, Pivot, invention historique) : il ne reçoit aucune structure et ignore aussi le bonus de rôle ; le câbler touche `EsiCostIndexService`, `BatchProfitScannerService`, `PivotAdvisorService`, `InventionService` et `FacilityTaxResolver`. Issue laissée ouverte, commentaire dessus.
- Ajout des rigs « Blueprint Copy / Invention / ME / TE Optimization » (L-Set) et « Cost Optimization » (M-Set) au catalogue `RigCatalogue` : le catalogue actuel propose des noms M/L « Laboratory Optimization » absents du SDE ; décision de produit, non traitée ici.
- Bonus de rig sur le temps des jobs de laboratoire.

## Décisions ouvertes

- Aucune bloquante. Les montants affichés changent (coût d'installation de copie, invention et recherche baissé dès qu'un rig de laboratoire est configuré) : à signaler dans la PR.
