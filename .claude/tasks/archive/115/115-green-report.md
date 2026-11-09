# #115 lot 1 — phase verte

## Back

- `TypeNameResolver::findName()` : `invTypeRepository->find($typeId)?->getTypeName()`, plus de délégation à `resolve()`. `resolve()`/`resolveMany()` inchangés.
- `IndustryCalculationService::resolveTypeName(): ?string` (via `findName`), `getProjectDisplayName(): ?string`.
- `IndustryResourceMapper::projectToResource()` : une racine au nom inconnu → `displayName = null` (racine unique : pas de suffixe `×N` sur null ; plusieurs racines : `null` si un nom est null, sinon `implode(' + ')`).
- Resources en `?string` : `ProjectStepResource::$productTypeName`, `StepPurchaseResource::$typeName`, `ProjectResource::$productTypeName` et `$displayName` (défaut `null` au lieu de `''`), phpdoc `rootProducts[].typeName`, `AvailableJobResource::$productTypeName`, `PurchaseSuggestionResource::$typeName`, phpdoc `productName` de `CostEstimationResource` et `ProductionCostService`.
- R5 : nouvelle classe `src/Command/ConsoleProjectLabel.php` (`ConsoleProjectLabel::of($project, $displayName)`), seul endroit du format `type <id> (name missing from the SDE)`, utilisée par `RegenerateProjectStepsCommand` et `MatchProjectJobsCommand`.
- Autres appelants de `resolveTypeName`/`getProjectDisplayName` : les `resolveTypeName` privés (SkillQueue, Planetary, Mining, Invention, ProfitMargin…) sont des méthodes locales, pas celles d'`IndustryCalculationService`. Rien d'autre à adapter.

## Front

- `frontend/src/composables/useTypeNames.ts` : `typeNameLabel(name, typeId)` → nom ou `common.unknownTypeName` (« Nom inconnu (#{typeId}) » / « Unknown name (#{typeId}) ») ; `projectDisplayName(project)` → `displayName`, sinon recomposé depuis `rootProducts` (une racine : `Nom ×N` si count > 1 ; plusieurs : ` + `), sinon `productTypeName`.
- Types `string | null` dans `stores/industry/types.ts` : RootProduct.typeName, StepPurchase.typeName, IndustryProjectStep.productTypeName, IndustryProject.productTypeName/displayName, AvailableJob.productTypeName, PurchaseSuggestion.typeName, CostEstimationStep.productName.
- Composants : ProjectTable (affichage, alt, sous-titre de suppression, `formatRootProducts`, tri `name` sur `projectDisplayName`), IndustryDashboard, ProjectDetail, StepsTab (jobs disponibles, étape liée, select), StepTree (split groups, étape, tri `localeCompare` sur le libellé affiché, modale de suppression), CostEstimationTab, ShoppingTab (achats aplatis), ShoppingPurchasesPanel (suggestions).
- `useShoppingStockAnalysis` et `useStockAnalysis` (fonction `useStockAnalysis()` non appelée, mais typée) : une étape au nom inconnu ne peut pas correspondre à une ligne de stock collée (qui porte un nom) ; libellé « Nom inconnu (#id) » pour l'intermédiaire.
- `findStepForType` (ShoppingTab) ne renvoie plus `name`, champ jamais lu.
- `linkedToStepName` affiché avec `job.productTypeId` : le job et l'étape liée partagent le blueprint, donc le produit.

## Gates

| Gate | Résultat |
|---|---|
| PHPUnit Unit | OK : 2667 tests, 7615 assertions, 1 incomplet préexistant (D11a), 1 notice préexistante (GMP/BCMath, web-push) |
| PHPUnit Integration | OK : 173 tests, 593 assertions |
| PHPStan niveau 8 | 63 erreurs = baseline (`docs/quality/baseline.md`), aucune dans un fichier modifié hormis `IndustryCalculationService::$invTypeRepository is never read` (préexistant, ligne 28 non touchée) |
| deptrac | 35 violations (baseline 36), aucune sur les fichiers touchés |
| Infection (unit, `--with-uncovered`, 7 fichiers PHP à logique) | MSI 14 % (ad11d9f, avant les tests rouges) → 25 % ; 539 mutants, 138 tués, 87 échappés, 314 non couverts |
| vue-tsc -b | 0 erreur (0 avant changement) |
| vitest | 83/83 |
| eslint (fichiers modifiés) | 0 erreur, 1 warning préexistant (`useStockAnalysis.ts:166`) |

Baseline Infection mesurée dans un worktree temporaire détaché sur ad11d9f (96225ab échoue au test initial à cause des rouges), supprimé ensuite.

## Points ouverts

- Mutants échappés sur le code nouveau, faute de test :
  - `IndustryResourceMapper.php:203` `!== null` → `=== null` : racine unique au nom inconnu avec count > 1 (étapes racines splittées) donnerait `" ×2"`. Pas d'exemple dans la spec.
  - `IndustryResourceMapper.php:206` `> 1` → `>= 1` : équivalent (branche `=== 1` avant).
  - `IndustryCalculationService.php:374` inversion du `??` : `getProjectDisplayName()` avec nom personnalisé et type connu non testé (préexistant).
- `ConsoleProjectLabel` compte « non couvert » pour Infection : le test de commande ne porte que `#[CoversClass(RegenerateProjectStepsCommand::class)]`. Ajouter `CoversClass(ConsoleProjectLabel::class)` relève d'une phase rouge.
- `MatchProjectJobsCommand` (R5) toujours sans test.
- Hors lot, laissés tels quels : `IndustrySlotsSection` (productTypeName des slots, lot 2), `ProfitMarginTab`, `StepHierarchyTree` (arbre de production), group-industry, ShoppingList/Appraisal.
- R6 à vérifier à la main dans l'application (pas de harnais front pour ces composants).
