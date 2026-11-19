# Audit couche API Platform Industry (auditor) — 2026-10-22

`tests/Unit/State` : 921 verts.

## SÉCURITÉ (hors issue publique, exploitabilité faible)
- S1 `CreatePurchaseProcessor.php:66` find() transaction wallet sans contrôle de propriétaire ; réponse expose type/qty/prix (`:72`). UUID v7 requis (exposé seulement au propriétaire, `PurchaseSuggestionProvider.php:110`).
- S2 `UpdateStepProcessor.php:91` find() structure d'un autre user (même soft-deleted) ; réponse renvoie nom et bonus (`IndustryResourceMapper.php:239-242`). Ids non exposés (`CorporationStructureProvider.php:66-79`).
Test : user B, id de A → 404.

## P1
1. **Estimations d'un projet figées à la création** [bug, industry] — seuls `CreateProjectProcessor.php:80-135` écrivent `estimated*` ; `UpdateProjectProcessor.php:95-115` régénère les étapes sans recalcul ; `Industry.vue:218-232` crée avec le 1er produit, autres via createStep. Test : 1→10 runs, estimatedSellPrice ×10 ; deux produits estimés.
2. **Prix de vente estimé d'une réaction à 1 u/run** [bug, industry] (= legacy #3) — `CreateProjectProcessor.php:126-128` `findBlueprintForProduct($productTypeId, 1)` puis `?? 1` ; stub `CreateProjectProcessorTest.php:241`.
3. **Profit : coût inconnu → 0** [bug, industry] (partiel #8) — `IndustryResourceMapper.php:149-158` `getEstimatedMaterialCost() ?? 0.0` (taxe, jobs idem), annule spec #21 R3.
4. **Profit total de la liste ≠ somme des lignes** [bug, industry] — `ProjectCollectionProvider.php:43` somme `IndustryProject::getProfit()` (`IndustryProject.php:432-447`, valeurs saisies seules) ; lignes : `IndustryResourceMapper.php:160-168` (repli estimations).
5. **Runs d'une étape intermédiaire annulés par la cascade** [bug, ux, industry] — `UpdateStepProcessor.php:102-111` → `IndustryStepCalculator.php:51-57` → `SplitGroupQuantities.php:51-61` (ceil besoin), `:73-86` prorata (10+10, saisir 15 → 12/8) ; UI `StepTree.vue:630-654`.
6. **Job rattaché à la main perdu au matching suivant** [bug, industry] (partiel #58 fermée) — `LinkJobProcessor.php:46-121` ne passe pas en `manual` ; aucun appel front n'envoie `jobMatchMode` ; `IndustryJobMatcher.php:50,172-175` supprime les matches `auto`.
7. **Rattachement manuel accepte un job annulé ; UI l'affiche « terminé »** [bug, industry] (partiel #4) — `CachedIndustryJobRepository.php:110-115` (`AvailableJobsProvider.php:74-78`) ; `LinkJobProcessor.php:66-75` sans contrôle statut/activité vs matcher `IndustryJobMatcher.php:65,185-193` ; `StepsTab.vue:253-255` ; création de match dupliquée `LinkJobProcessor.php:87-114` vs `createJobMatch`.
8. **ME saisi sur une étape de réaction appliqué aux matériaux** [bug, industry] (partiel #15, 2519314 = scanner/pivot seulement) — `UpdateStepProcessor.php:81-84`, `CreateStepProcessor.php:191` ; `StepTree.vue:662-686` ; `IndustryStepCalculator.php:147-157` vs factory 0 (`IndustryProjectFactory.php:206-209`) ; glossaire l.60.
9. **CreateStepProcessor : runs stockés comme quantité ; ajout à un groupe découpé sans recalcul** [bug, industry] (partiel #2) — `CreateStepProcessor.php:87,130,186` `setQuantity($runs)` (glossaire l.12) ; `:137` totalGroupRuns non mis à jour (glossaire l.34) ; pas de SplitGroupMaterialNeeds ; `:170-174` type sans blueprint `?? $typeId`.

## P2
10. **Type absent du SDE : l'arbre disparaît sans message** [bug, ux, industry] (#109 hors périmètre) — `ProjectProvider.php:56-66` catch RuntimeException → tree null ; figé `ProjectProviderGoldenTreeCharacterizationTest.php:457-463` ; `StepsTab.vue:323` masque.
11. **Coût de transport de la liste d'achats ignore le réglage utilisateur** [bug, ux, industry] — `ShoppingListProvider.php:78` 1200 en dur, `ShoppingTab.vue:70` ref(1200) ; `FeesConfig.vue:34` enregistre `exportCostPerM3`, lu par `BatchScanProvider.php:60`.
12. **adapt-stock sans effet ; adapt-stock et apply-stock sans appelant** [bug, tech-debt, industry] (partiel #44) — `AdaptStockProcessor.php:53-87` cascade réapplique le besoin ; groupe découpé : stock déduit de chaque job ; `ApplyStockProcessor.php:60-66` ignore nom inconnu, requête par ligne ; `stores/industry/projects.ts:166,191` sans appelant ; aucun test.
13. **Syncs à la demande taisent leurs échecs** [bug, industry] (≈ ESI #4, #21) — `MatchJobsProcessor.php:55-75` ; `SyncCharacterSkillsProcessor.php:85-93,57` sans token/scope compté synchronisé ; messages FR en dur.
14. **Rigs d'une structure non validés** [bug, industry] — `CreateStructureInput`/`UpdateStructureInput` sans contrainte ; `IndustryStructureConfig.php:174-178` ; `IndustryBonusService.php:289-296` additionne doublons, saute nom inconnu ; glossaire l.77 « au plus 3 rigs ».
15. **Validation manquante aux bornes** [bug, industry] — TE impair accepté (glossaire l.55) ; `Uuid::fromString` → 500 ; `jobsStartDate` invalide → 500 (`UpdateProjectProcessor.php:88-90`) ; taux de frais sans borne, système favori non vérifié (`UpdateUserSettingsInput`, processor `:50-72`) ; `items` non vérifié (`ApplyStockProcessor.php:58`).
16. **Suppression d'étape : règles seulement côté UI** [bug, industry] — `DeleteStepProcessor.php:44-54` ; `StepTree.vue:850` ; pas de recalcul des enfants ; groupe d'un seul job subsiste (glossaire l.34).

## P3
17. **Meilleur prix Jita / structure en trois copies, logique dans State** [tech-debt, market, industry] (≈ #50) — `ShoppingListProvider.php:141-170`, `ShoppingList/ParseProcessor.php:122-140`, `CreateProjectProcessor.php:106-123` ; #104/#117 même bug corrigé deux fois.
18. **Unités par run : deux règles** [tech-debt, industry] (= legacy #15) — `?? 1` dans `CreateProjectProcessor.php:127`, `AdaptStockProcessor.php:75`, `UpdateStepProcessor.php:141`, `IndustryStepCalculator.php:167` vs `SplitGroupMaterials.php:25-36` ; mapping activité→activityId réécrit 5 fois.
19. **Bonus de temps des skills dupliqué, N+1** [tech-debt, perf, industry] — `IndustryResourceMapper.php:427-442` vs `IndustryCalculationService::calculateSkillTimeMultiplier` (`:265-287`) ; `:411-416` requête par (blueprint, perso).
20. **PATCH projet construit tout l'arbre pour rien** [perf, industry] — `ProjectResource.php:51-56` provider PATCH = ProjectProvider (`:47-67`) ; idem ProjectStepProvider.
21. **Transaction wallet rattachable à plusieurs projets** [bug, industry] — `PurchaseSuggestionProvider.php:85-95` alreadyLinked = projet courant ; `CreatePurchaseProcessor.php:65-77` pas d'unicité ; fallback SQL `:66-72` activité 1 seulement.
22. **Conventions API Platform, code mort, pas de tests HTTP** [tech-debt, industry] (partiel #44, #46) — 0 `uriVariables`/`Link` dans `src/ApiResource/Industry/` ; formats hétérogènes (`CreateStepProcessor.php:102-105` vs `UpdateStepProcessor.php:111-121`) ; `StepPurchaseDeleteProvider.php:63` remove() dans le provider ; `updateStepStructure` (`steps.ts:102`) sans appelant, null ignoré en merge-patch (`UpdateStepProcessor.php:90`) ; `fetchTargets/createTarget/updateTarget` (`stockpile.ts:15,28,47`) non branchés ; `tests/Functional` vide ; 22 processors + 24 providers sans test.

Inconnu : routes sans Link fonctionnent-elles (pas de test HTTP) ; 15 erreurs PHPStan sur le périmètre, baseline non vérifiée.
