# Spec #145 (lot b) — Une taxe en pourcents s'appelle « percent »

## Contexte

Le même nom `facilityTaxRate` désigne des pourcents (entité `IndustryStructureConfig`, services `EsiCostIndexService::estimateJobInstallCost`, `JobInstallCostEstimator::estimate`, `InventionService`, `ProductionCostService`, résolveur `FacilityTaxRateResolver` qui rend des pourcents) et une fraction dans le noyau (`ProductionStructure::$facilityTaxRate`, `InstallCost::forJob`). Une confusion d'échelle passe inaperçue à la lecture ; le lot a borne désormais la fraction à 0,5 dans le noyau, ce qui fait échouer une telle confusion au lieu de la laisser passer.

## Règles

- **R1 — Noyau inchangé.** `rate` = fraction (`ProductionStructure::$facilityTaxRate`, `InstallCost`).
- **R2 — Pourcents nommés `percent`.** Dans le code PHP hors noyau :
  - `IndustryStructureConfig` : propriété `facilityTaxPercent`, accesseurs `getFacilityTaxPercent()` / `setFacilityTaxPercent()` ; **colonne inchangée** (`facility_tax_rate`, nom explicite dans le mapping) → aucune migration ;
  - `FacilityTaxRateResolver` → `FacilityTaxResolver` (méthode `percentFor` inchangée) ;
  - paramètres, variables et clés de tableau qui portent des pourcents : `facilityTaxPercent` (`EsiCostIndexService::estimateJobInstallCost`, `JobInstallCostEstimator::estimate`, `InventionService`, `ProductionCostService`, `BatchProfitScannerService`, `PivotAdvisorService`, `ConfiguredProductionStructures`).
- **R3 — Contrat API inchangé.** Le champ JSON `facilityTaxRate` (pourcents, spec #113) des ressources et entrées API et le front ne changent pas : renommer le contrat demande une coordination front, hors lot.
- **R4 — Aucun comportement modifié.** Mêmes montants partout ; `doctrine:migrations:diff` ne produit rien.

## Vérification

Tests existants renommés (appels aux nouveaux noms) : ils échouent avant le renommage du code (méthode ou classe inconnue), passent après sans changement d'attendu.

## Glossaire

Mettre à jour la colonne « Symbole(s) » de **Taxe d'installation** (`IndustryStructureConfig::$facilityTaxPercent`, `FacilityTaxResolver`) et préciser : pourcents hors du noyau, fraction (`rate`) dans le noyau.
