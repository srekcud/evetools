# Triage feedback testeur — industrie (2026-11-12, V0.17)

Doublons : aucune issue ouverte ne recoupe (recherche gh sur jobs, container, contribution, invention, chaîne, blacklist).

## A. « Associer les jobs » → 500
- Front : `frontend/src/components/group-industry/JobsTable.vue:55-71`, store `stores/group-industry/project.ts:167`, `POST /industry/projects/{id}/match-jobs`.
- Processor : `src/State/Processor/Industry/MatchJobsProcessor.php:44-102`.
- H1 (moyenne-haute) : `catch (\Throwable)` l.72-76 avale l'échec de `syncCharacterJobs`, qui a flushé (`IndustryJobSyncService.php:287,296`, `DerivedContributionService.php:71`) ; une exception SQL ferme l'EntityManager, le flush suivant (`IndustryJobMatcher.php:57`) lève → 500. `ProjectActivityRecorder.php:51-58` avale aussi.
- H2 (moyenne) : exception métier non rattrapée : `ProducerMissingFromSdeException` (`IndustryActivityProductRepository.php:244` via `IndustryJobMatcher.php:67`), `LogicException` ligne sans runs (`IndustryJobMatcher.php:106`), `DerivedContributionService.php:353`.
- Logs prod à chercher : `Failed to sync jobs for character`, `Project activity not recorded`.
- Test : intégration `testMatchJobsReportsTheFailedCharacterInsteadOfAServerErrorWhenItsSyncFailsInTheDatabase` (UniqueConstraintViolation dans la sync d'un perso → 200 + `failedCharacters`).

## B. « Voir les jobs » sans effet
- `ProjectCockpitCalculator.php:246` renvoie `tab: 'materials'` → `projectDetailTabs.ts:11-21` mappe sur `bom`, déjà l'onglet par défaut (`GroupIndustryDetail.vue:90`, `actOnNextAction` l.323-340). JobsTable en bas de l'onglet (l.629), sans ref ni ancre.
- Test : Vitest `GroupIndustryDetail.spec.ts › the launch_job next action brings the jobs table into view` (scrollIntoView appelé).

## C. Container projet « introuvable »
- Résolution par nom exact sur toute la corpo, hangars CorpSAG1-7 visibles par le propriétaire (`ProjectContainerService.php:188,207-240`, `VisibleCorporationAssetsFinder.php:30-41`, `CachedAssetRepository.php:172-243`). Pas de station sur `IndustryProject`.
- H1 (~35 %) division non visible par le propriétaire : `no_visible_division` seulement si aucune division (`ProjectContainerService.php:172`).
- H2 (~35 %) nom non normalisé en modification (`GroupIndustryDetail.vue:303`, `UpdateProjectProcessor.php:103`), comparaison sensible casse/espaces (l.213).
- H3 assets corpo périmés, pas de date de sync affichée (l.168). H4 container hors CorpSAG.
- Défaut certain : même nom dans N stations → contenus additionnés (l.193-197), « Vérifié » possible avec du stock ailleurs.
- Test : `ProjectContainerServiceTest::testVerifyContainerCurrentlyReportsNotFoundWhenNamedContainerSitsInADivisionHiddenFromOwnerIssueN`.

## D. « J'ai fourni » sur une étape d'invention
- `ContributeModal.vue:66` présélectionne `job_install` sur `isJob` seul ; valeur remise à 0 (l.69, `selectType` l.159-165), envoi exige valeur > 0 (l.98-104, backend `ProjectContributionService.php:268-272`).
- La modale ne peut pas produire un apport d'intermédiaire `material` sur une ligne de job (l.81-82, 161).
- BPC T2 détenus jamais déduits : `IndustryProjectService::replaceT2BlueprintCopiesByInventions` l.729-805 ; `OriginalBlueprintFinder` exclut les copies ; specs 707 règle 6 et 708 règle 4 l'excluent.
- Risque latent (60 %) : `IndustryProjectContributionRepository::findIntermediateStockByTypeId` l.123-137 somme par `line.typeId` sans filtrer `jobGroup` ; la ligne d'invention porte le typeId du T2 (`IndustryProjectService.php:771`).
- Tests : Vitest `preselects bpc when the prefilled line is a solo invention step` ; intégration `testMaterialContributionOnInventionLineIsNotCountedAsProductStock`.

## Annexes (dette)
- `DerivedContributionService::synchronize` : paramètre `$ownerParticipant` reçoit l'installateur (l.57-59, 104-111).
- `producerOf` en trois versions divergentes : `IndustryActivityProductRepository.php:240`, `IndustryProjectService.php:476`, `PlansStockpileImports.php:27`.
