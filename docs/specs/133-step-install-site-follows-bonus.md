# Spec #133 (lot b) — Le coût d'installation d'une étape suit la structure de son bonus

## Contexte

Décision de Sylvain (2026-10-23, blocage #133) : pour une étape de projet **sans structure assignée**, le coût d'installation suit la structure retenue pour le bonus matériaux (son système pour le cost index, sa taxe), Jita et taxe NPC si aucune. Aujourd'hui (main 8d673bf) :

- bonus matériaux : `IndustryCalculationService::getStructureBonusForStep()` (`:114-165`) → structure assignée, sinon meilleure structure du système favori, sinon meilleure de toutes ;
- coût d'installation (`ProductionCostService`, BPC Kit et coûts de copie via l'étape racine de `InventionService`) : système = `ProjectStepSolarSystemResolver::forStep()` (structure assignée → favori → Jita) ; taxe = structure assignée, sinon `FacilityTaxResolver::percentFor()` (structure choisie par `StructureSelector` sans catégorie dans le système favori).

Un même job peut donc avoir son bonus d'une structure A et son coût d'installation du système et de la taxe d'une structure B.

## Règles métier

- **R1 — Structure effective.** Structure effective d'une étape = structure assignée, sinon `getStructureBonusForStep($step)['structure']` (peut être null).
- **R2 — Système.** Système de la structure effective s'il est connu ; sinon système favori de l'activité de l'étape (réaction / fabrication) ; sinon Jita.
- **R3 — Taxe (pourcents).** Taxe de la structure effective (`getFacilityTaxPercent()` : station NPC 0,25, structure joueur non saisie 0) ; sans structure effective : taxe de station NPC (0,25, constante existante de `ProductionCostCalculator` × 100, pas de nouvelle valeur).
- **R4 — Un seul résolveur.** `ProjectStepSolarSystemResolver` devient `ProjectStepStructureResolver` (dépend de `IndustryUserSettingsRepository` et `IndustryCalculationService`) avec `solarSystemOf(IndustryProjectStep): int` et `facilityTaxPercentOf(IndustryProjectStep): float`. `ProductionCostService` et `InventionService` (étape racine) l'utilisent et n'utilisent plus `FacilityTaxResolver` (qui reste pour Pivot et scanner, sans étape de projet).
- **R5 — Sans étape racine (invention, copie).** Jita et taxe NPC (inchangé).

## Exemples

Favori de fabrication Jita 30000142. Étape de fabrication sans structure assignée.

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | Bonus retenu : Azbel à Amarr 30002187, taxe 3 % | système Amarr, taxe 3.0 (Jita, taxe du résolveur dans Jita) |
| E2 | Bonus retenu : structure saisie à la main (sans système), taxe 2 % | Jita (favori), taxe 2.0 (Jita, taxe du résolveur) |
| E3 | Aucune structure retenue | Jita, taxe 0.25 (inchangé si l'utilisateur n'a pas de structure dans Jita) |
| E4 | Structure assignée (Raitaru Perimeter 5 %) | Perimeter, 5.0 (inchangé) |
| E5 | Bonus retenu : station NPC (structure `station`) à Jita | Jita, 0.25 |
| E6 | Étape de réaction sans structure, favori de réaction Perimeter, bonus retenu Tatara à Perimeter 1 % | Perimeter, 1.0 |
| E7 | BPC Kit : étape racine sans structure, bonus retenu Azbel Amarr 3 % | invention estimée à Amarr, taxe 3.0 |

## Impact affiché

Estimation des coûts d'un projet, coût de jobs estimé à la création, BPC Kit et coûts de copie : pour les étapes sans structure assignée, système et taxe de la structure du bonus. À annoncer.

## Hors périmètre

Profit Margins, Buy vs Build (noyau, déjà cohérents), Pivot et scanner (pas d'étape de projet), choix de la structure du bonus lui-même.

## Glossaire

Aucun ajout (« structure effective » reste local à la spec).
