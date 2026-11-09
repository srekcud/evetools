# #250 : investigation (auditor, 2026-11-03)

Réponse : oui, un ME de réaction non nul est appliqué, seulement s'il est déjà enregistré (projets créés avant 303e1320, 31/10). Aucun chemin d'écriture n'en crée de nouveau.

## Passent toujours 0
- PivotAdvisorService.php:209 et :534 (appelé par :174 et :443 avec `$isReaction ? 0 : $me`)
- BatchProfitScannerService.php:312
- IndustryTreeService.php:116-119 (`$effectiveMe`)
- IndustryProjectFactory.php:577 via SplitGroupMaterials:24 (meLevel du plan = 0 pour une réaction, :406-409)

## Peuvent passer un ME non nul (lisent `$step->getMeLevel()` sans regarder l'activité)
- ProductionStepNeedsResolver.php:106 (liste d'achats via IndustryShoppingListBuilder.php:63-64, arbre via ProductionTreeFromSteps.php:115)
- IndustryStepCalculator.php:119 (cascade des runs en amont)
- SplitGroupMaterialNeeds.php:43-44
- SplitStepProcessor.php:118 recopie le ME sur les nouveaux jobs sans tester la réaction

## Protections actuelles
- UpdateStepProcessor.php:92-94 refuse (400) un ME non nul sur une réaction
- CreateStepProcessor.php:138 et :205 forcent 0
- Import de stockpile : noyau, ME 0 (PlansStockpileImports l.44)
- Commande app:industry:reset-reaction-me-levels (ReactionStepMeLevelReset.php:36-37) : projets Active seulement ; lancée en prod ? inconnu
- Contradiction doc : docs/modules/industry.md:82 vs :41 et docs/glossary.md:42

## Impact
Réactions héritées à ME 10 : matériaux ~11 % trop bas (877 au lieu de 974, base 100, 10 runs, rig 2,64) dans liste d'achats, arbre, runs amont ; un split propage le ME.

## Tests qui le démontreraient (`Currently…Issue250`, `#[Group('issue-250')]`)
- ProductionStepNeedsResolver : réaction stockée ME 10 = quantité de forReactionJob
- IndustryStepCalculator : consommateur réaction ME 10 = mêmes runs amont qu'à ME 0
- SplitGroupMaterialNeeds::applyChangeOfJobs : idem
- SplitStepProcessor : split d'une réaction ME 10 = jobs ME 0

## Correctif minimal recommandé
1. Une seule méthode « ME de l'étape » tenant compte de l'activité, utilisée aux 3 lecteurs (ProductionStepNeedsResolver:106, IndustryStepCalculator:119, SplitGroupMaterialNeeds:43-44)
2. Forcer 0 dans SplitStepProcessor:118
3. Ne pas toucher MaterialQuantityFormula::forJob (7 appelants, signature sans activité)
Affichage : étapes à ME 0 inchangées ; réactions héritées à ME 10 : +~11 %.
