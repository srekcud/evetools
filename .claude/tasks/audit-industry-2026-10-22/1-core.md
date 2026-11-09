# Audit noyau Industry (auditor) — 2026-10-22

Lancé : 303 tests unitaires Industry verts (1 incomplet D11a) ; deptrac sans violation ; Infection src/Industry 428 mutants, 420 tués, 3 échappés, 5 timeouts, covered MSI 99 %.
Conforme : R1, R2, R5, R6, R7, R8, R12/D4b ; aucun catch ni `?? 0` dans src/Industry (sauf `ProductionPlanner.php:173` légitime) ; attributs dogma présents en SDE ; aucune probabilité d'invention à 0.

## P1
1. **Noyau sans appelant en production ; suivi le déclare fusionné** [tech-debt, industry, investigation] — 0 référence à `App\Industry\` hors src/Industry ; `red-queue.md:9` rang 1 « fusionné e1a0d20 » alors que e1a0d20 = test stockpile ; travail rang 1 non commité dans worktree `evetools-wt-CP` (Decryptors.php, RigCategories.php, UserProductionStructures.php, MissingDataPresenter.php non suivis) ; `deptrac.yaml:110` interdit Service → IndustryApplication (`:100`) ; code mort : JobDuration, SkillTimeModifier, SdeStructureBonusReader, SdeRigBonusReader (`config/services.yaml:85-87`), `Plan::jobsFor()`, `Intermediate::totalRuns()` ; `MaterialQuantityFormula.php:9-18` double `MaterialQuantity.php` (assumé spec #17). Interne (suivi) + public (aucun écran migré).
2. **Plafond de runs du BPC (D2) jamais transmis au planificateur** [bug, industry] — `ProductionCostCalculator.php:59-63` PlanRequest sans `blueprintCopyMaxRuns` ; `ProductionCostRequest.php:21-32` sans champ ; `Recipe::$maxProductionLimit` lu (`SdeBlueprintCatalog.php:78`) jamais utilisé. Démo : T2 32 runs, BPC 10 runs → 1 job 32 runs. 4×8 runs ME5 = 216 vs 213. Test : 4 JobCost, somme 32, aucun > 10.
3. **ME/TE du BPC inventé (decryptor) ignorés pour les matériaux T2** [bug, industry] (doublon partiel #18) — `ProductionCostCalculator.php:60` cible prend `$request->efficiency` ; `InventionOutcome::of()` (`:213`) ME/TE seulement pour les tentatives. Démo Process (+3) requête ME2 : 220 Tritanium (ME 2) au lieu de 213 (ME 5). Décision (c) de `profit-margin-wiring.md` §0.3 non implémentée ; spec R8.

## P2
4. **facilityTaxRate : pourcentage dans l'entité, fraction dans le noyau, sans borne** [bug, tech-debt, industry] — `IndustryStructureConfig.php:93-95` (3.5 = 3,5 %) vs `ProductionStructure.php:13-14,30`, `InstallCost.php:37` fraction ; `InstallCost::assertRate()` (`:67-72`) sans borne haute (3,5 → taxe 3,5 M sur EIV 1 M ; cost index 5,0 accepté) ; float non nullable → taxe non saisie = 0 % (`todo.md:165` promettait une issue). Spec #113 : 0 à 50 %.
5. **Carnet Jita trop court : prix partiel présenté comme connu** [bug, market, industry] — `JitaMarketPrices.php:25-28` jette `coverage` ; carnet 20 ordres (`glossary.md:125`) ; pas de cas de couverture partielle dans `JitaMarketPricesTest`. Test : couverture 30 % → MissingData `market_depth` ou couverture exposée.
6. **Structure assignée (D3) non transmise, choix de structure fait deux fois** [tech-debt, industry] — `ProductionCostCalculator.php:292` StructureMaterialModifiers sans assignedStructures ; `:150-153` rappelle select() sans assignée pour l'install ; `StructureMaterialModifiers.php:33-38` gère l'assignée.

## P3
7. **Copie/invention : la structure la plus récente gagne** [investigation, industry] — `ProductionCostCalculator.php:233` select($activity, null) ; `ProductionStructure.php:45-48` multiplicateur 1 hors fabrication ; tri `StructureSelector.php:65-69` → configuredAt. Raitaru 10 % récente devant Sotiyo 0 %. Décision Sylvain.
8. **Recette SDE relue à chaque arête** [perf, industry] — `ProductionPlanner.php:147-148` buildableRecipe() avant isset($finished) ; `SdeBlueprintCatalog.php:38-81` 2 requêtes/feuille, 4-5/recette, sans mémoire ; Nomad : 300 recipeFor + 66 recipeOf (`:104`) ; Profit Margins = 9 costOf().
9. **MSI sans cliquet, deptrac non bloquant, pas de CI** [tech-debt, industry] — `infection.json5:3` report-only ; baseline sans mesure du Domain ; `Makefile:57` `-docker compose … deptrac` ; `.github/` sans workflow ; ADR-0009 cliquet MSI, spec §6.7. Mutant échappé `ProductionCostCalculator.php:262` (système structure vs favori inversés).

## P4
10. **Glossaire désaligné** [docs, industry] — `glossary.md:57` ceil sur tentatives (R8, `InventionExpectation.php:46` sans ceil) ; `:73` 2 motifs listés vs 4 dans `MissingData.php:12-15` ; `:23,25,69,71` « aucun symbole » alors que `Intermediate::$demand/$surplus`, `InstallCost::JOB_COST_BASE_RATE/SCC_SURCHARGE_RATE` existent.
11. **OutputPerRun sans VO, 0 accepté** [tech-debt, industry] — `Recipe.php:18` Quantity (accepte 0, `Quantity.php:14`) ; division `ProductionPlanner.php:49`, `ProductionCostCalculator.php:89` ; latent (SDE sans 0).
12. **D3 (filtre) et D3b (départage) se contredisent** [investigation, docs, industry] — code = filtre strict (`StructureSelector.php:43-49`) : structure du favori sans rig adapté passe devant une meilleure ailleurs, sans avertissement. Décision Sylvain.

Inconnu : adaptateur `ConfiguredProductionStructures` absent de main et du worktree CP.
