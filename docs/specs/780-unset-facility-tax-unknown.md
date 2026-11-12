# Spec #780 : une taxe d'installation non saisie est un coût inconnu, jamais 0 %

Circuit m, niveau de technicité 3 (code Industry). Mode nuit : questions tranchées sur la recommandation, listées en « Décisions ».

## Contexte

L'ESI n'expose pas la taxe d'une structure dont le joueur n'est pas propriétaire : elle vient de l'utilisateur seul. Une structure de joueur sans taxe saisie (`IndustryStructureConfig::$facilityTaxPercent` = `null`) est aujourd'hui calculée comme une structure à 0 % :
- `ConfiguredProductionStructures::productionStructureOf()` remplace `null` par `UNSET_FACILITY_TAX_PERCENT` (0.0) ;
- `JobInstallCostEstimator::estimate()` fait `($facilityTaxPercent ?? 0.0)`.

Le coût d'installation, le choix de structure (`StructureSelector`) et les marges paraissent donc moins chers que la réalité, sans signal. La convention « une taxe non saisie vaut 0 % » (glossaire, #145) est abrogée par #780.

Décision de Sylvain : une taxe saisie à 0 reste un vrai 0 % (152 structures GEZ ont été mises à 0 volontairement) ; seul `null` signifie « non saisie ». Une station NPC garde sa taxe fixe de 0,25 %.

## Règles

R1. `ProductionStructure::$facilityTaxRate` devient `?float` (fraction). `ConfiguredProductionStructures` passe `null` tel quel ; `0.0` reste `0.0`, `3.5` (pourcents) devient `0.035`. La constante `UNSET_FACILITY_TAX_PERCENT` n'est plus lue.

R2. `InstallCost::forJob()` et `forResearch()` acceptent `?float $facilityTaxRate`. Avec `null`, `$facilityTax` est `Cost::unknown(MissingData::facilityTax())` et `$total` est inconnu ; `$systemCost`, `$sccSurcharge` et `$alphaCloneTax` restent connus. Un cost index inconnu et une taxe inconnue donnent un total inconnu qui liste les deux causes, `cost_index` puis `facility_tax`. Les bornes de `assertRate` ne changent pas pour une valeur non nulle.

R3. `MissingData::facilityTax()` : raison `facility_tax`, sans `typeId`. `MissingDataPresenter` la renvoie telle quelle dans `missingData`.

R4. `ProductionCostCalculator`, `ResearchProfitability` et `StructureTotalCostComparator` transmettent `$structure->facilityTaxRate` (nullable) à `InstallCost` : le coût d'installation de la ligne, donc le coût total de la structure, est inconnu avec la cause `facility_tax`. Sans structure (station NPC), la taxe est `NPC_STATION_FACILITY_TAX_RATE` (0,0025), inchangé.

R5. `StructureSelector::installRateOf()` renvoie `null` quand la taxe est `null` (comme pour un cost index inconnu) : la structure ne participe pas au critère du taux le plus bas tant qu'une autre a un taux connu ; si aucune n'en a, le départage par préférence est inchangé. Le choix par matériaux (`smallestMaterialMultiplier`) ne lit pas la taxe et ne change pas.

R6. `StructureComparisonPresenter` : `facilityTaxPercent` vaut `null` (et non 0) quand la structure n'a pas de taxe ; sinon `round(rate × 100, DISPLAYED_TAX_DECIMALS)` comme aujourd'hui.

R7. `FacilityTaxResolver::percentOf()` et `percentFor()` renvoient `?float` : `null` quand la structure a une taxe `null`, 0,25 sans structure, `rate × 100` sinon.

R8. `JobInstallCostEstimator::estimate()` : `$facilityTaxPercent === null` ne vaut plus 0. Les contrôles actuels gardent leur priorité (cost index inconnu, puis prix ajusté manquant) ; ensuite, `null` renvoie `cost: null`, `unknownReason: EsiCostIndexService::UNKNOWN_REASON_MISSING_FACILITY_TAX` (`missing_facility_tax`), `missingAdjustedPriceTypeIds: []`. `0.0` calcule normalement.

R9. Les chaînes historiques propagent le `null` sans le convertir : `EsiCostIndexService::estimateJobInstallCost()`, `InventionService` (copie, invention, `expectedCostPerRun`), `PivotAdvisorService` (`totalCostWithInvention` prend `?float`), `BatchProfitScannerService`. Une ligne de scan ou de pivot dont le coût d'installation est inconnu pour cette raison porte `unknownReason = missing_facility_tax`, marge et coût total `null`, comme pour `missing_cost_index`. Les paramètres par défaut `= null` de `InventionService` signifient désormais « taxe non fournie » : coût inconnu, plus 0.

R10. Front : la liste des structures (`StructureConfig.vue`) et le formulaire signalent une structure de joueur sans taxe par un avertissement visible (teinte d'alerte, pas seulement l'italique actuel) : liste, ligne « Taxe d'installation » ; formulaire, texte d'aide sous le champ vide qui dit que le coût d'installation reste inconnu tant qu'elle n'est pas saisie. Une station NPC garde « taxe fixe 0,25 % ». `ProjectStructures.vue` (comparateur de structures) affiche « non saisie » quand `facilityTaxPercent` est `null` (type `number | null`). `facility_tax` rejoint `MissingDataReason`, `missing_facility_tax` rejoint `JobInstallCostUnknownReason` et le libellé `inventionUnknown.missing_facility_tax` existe en en et fr ; `useUnknownInstallCostReasons` mappe `facility_tax` vers `missing_facility_tax`. Clés i18n : l'implementer les nomme, parité en/fr obligatoire.

## Exemples chiffrés (tests rouges)

| Entrée | Sortie attendue |
|---|---|
| Config `facilityTaxPercent` = `null`, `configuredBy()` | `facilityTaxRate === null` |
| Config `facilityTaxPercent` = `0.0` | `facilityTaxRate === 0.0` (vrai 0 %, calcul connu) |
| Config `facilityTaxPercent` = `3.5` | `facilityTaxRate === 0.035` |
| `InstallCost::forJob(Manufacturing, EIV 1 000 000, index 0.05, ×1, taxe null, alpha non)` | `total` inconnu, `missingData` = [`facility_tax`] ; `systemCost` connu 50 000 ; `sccSurcharge` connu 40 000 |
| Même appel avec taxe `0.0` | `total` connu 90 000 |
| Même appel avec index `null` et taxe `null` | `total` inconnu, raisons [`cost_index`, `facility_tax`] |
| `forResearch(ResearchMaterial, …, taxe null)` | `total` inconnu, raison `facility_tax` |
| `ProductionCostCalculator` avec une structure à taxe `null` | coût d'installation et total inconnus, `facility_tax` listée ; matières et transport connus |
| `ProductionCostCalculator` sans structure | taxe 0,0025, coût connu (inchangé) |
| `StructureSelector`, A (taxe `null`, index bas) et B (taxe 1 %, index plus haut), critère taux le plus bas | B choisie (taux de A inconnu) |
| `StructureSelector`, A et B toutes deux à taxe `null` | départage par préférence, inchangé |
| `JobInstallCostEstimator::estimate(…, taxe null, prix et index connus)` | `cost null`, `unknownReason 'missing_facility_tax'` |
| `estimate(…, taxe 0.0)` | `cost` calculé, `unknownReason null` |
| `estimate(…, taxe null, index null)` | `unknownReason 'missing_cost_index'` (priorité inchangée) |
| `FacilityTaxResolver::percentOf(structure taxe null / sans structure / taxe 0.035)` | `null` / `0.25` / `3.5` |
| `StructureComparisonPresenter`, structure taxe `null` | `facilityTaxPercent` `null` |
| Front, structure de joueur `facilityTaxRate: null` | avertissement visible dans la liste ; `0` affiche « 0 % » sans avertissement |
| Front, station NPC | taxe fixe, pas d'avertissement |

## Cas limites

- Une structure à `0.0` ne déclenche ni avertissement ni coût inconnu (garde sur le comportement voisin).
- `facility_tax` n'apparaît qu'une fois même si plusieurs lignes d'un même coût ont la structure sans taxe (déduplication de `Cost::plus`).
- Un projet dont le plan choisit une structure sans taxe : ses coûts d'installation deviennent inconnus avec la cause `facility_tax`, jamais un montant partiel.

## Accès et fraîcheur

Aucun nouvel accès : `configuredBy($userId)` filtre déjà par utilisateur, aucune ressource API ni champ exposé n'est ajouté (`StructureConfigResource::$facilityTaxRate` est déjà nullable). Aucune migration.

## Tests existants contredits ou modifiés

Tout test qui construit un `ProductionStructure`, ou appelle `estimate()`, `InventionService` ou `FacilityTaxResolver` en comptant sur « `null` vaut 0 » : `ConfiguredProductionStructuresTest`, `JobInstallCostEstimatorTest`, `FacilityTaxResolverTest`, `InventionServiceTest`, `PivotAdvisorService*Test`, `StructureComparisonPresenterTest`, `Double/StructureTotalCostExample`. Le `test-writer` les liste et les met à jour avec leur nouvelle valeur exacte.

## Hors périmètre

- Retirer le champ de taxe, la constante `UNSET_FACILITY_TAX_PERCENT` ou une colonne : la constante reste en place si PHPStan l'accepte, sinon décision à noter.
- Migration, valeur par défaut en base, import de taxes depuis l'ESI (impossible).
- Station NPC : aucun changement.
- Drapeau « taxe supposée 0 % » : écarté, l'état inconnu existant suffit (voir Décisions).

## Décisions (mode nuit)

1. État inconnu (`MissingData`) plutôt que drapeau « supposée 0 % » : l'issue le cite en premier, le noyau l'a déjà (`cost_index`), et un drapeau obligerait à propager un second état dans chaque chaîne. Conséquence assumée : un utilisateur qui n'a jamais saisi de taxe voit ses coûts d'installation inconnus jusqu'à la saisie ; le sélecteur écarte ces structures au profit de celles à taux connu.
2. La convention du glossaire « non saisie = 0 % » (#145) est remplacée par « non saisie = inconnue » ; le glossaire est mis à jour.
3. La constante `UNSET_FACILITY_TAX_PERCENT` n'est pas supprimée sans accord de Sylvain.

## Glossaire

« Taxe d'installation » et « Taux d'installation » : une taxe non saisie est inconnue (cause `facility_tax`), jamais 0 % ; un 0 saisi reste un vrai 0 %.
