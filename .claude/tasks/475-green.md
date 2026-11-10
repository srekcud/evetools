# #475 suite : phase verte, durée de recherche ME et TE

## Implémenté
- `ResearchJobDuration::of` : temps SDE x `ResearchLevels::costMultiplier(niveau)` x compétences x structure, un seul arrondi ; `RANK_ONE_BASE_TIME_SECONDS` supprimée (aucun usage restant dans `src/`).
- `SdeResearchBaseTimes` : blueprint de fabrication (activité 1) puis activité 4 (ME) ou 3 (TE) ; absent ou <= 0 : null. Constantes d'activité locales (deptrac interdit `App\Enum` depuis Industry/Infrastructure).
- `CachedCharacterSkillLevels` : une requête par personnage (`CachedCharacterSkillRepository::findLevelsByEveCharacterId`), mémoïsée dans le service, vidée par `ResetInterface` (kernel.reset) entre deux requêtes en mode worker.
- `OriginalBlueprintFinder::bestUsesOwnedBy` : `researcherCharacterId` = `getCharacter()?->getEveCharacterId()` (null pour la corporation). `IndustryProjectService::ownedBlueprintsOf` inchangé (l'objet est repassé tel quel).
- `ResearchProfitability` : `meResearch` (niveau ME+1), `teResearch` (TE/2+1, null à TE 20) ; temps de base absent et/ou chercheur null : durée inconnue (`research_base_time`, `researcher`) ; structure neutre ; verdict et `missingData` de la suggestion inchangés. `TimeEfficiency::MAXIMUM_LEVEL` et `LEVEL_STEP` passés publics.
- `ResearchRowPresenter` : clés `meResearchDuration`, `teResearchDuration` (null à TE 20) ; description de `ProjectResearchResource` mise à jour.
- `SyncCharacterSkillsProcessor::SKILLS_WRITTEN_AT_ZERO` : + Advanced Industry.
- Front : `ProjectResearch.vue` (testids `me-duration`, `te-duration`, `research-duration-notice`), locales en et fr (`duration.me/te`, `durationNotice`, reasons `research_base_time`, `researcher`).
- Docs : `docs/modules/industry.md` (carte du module).

## Gates
- `tests/Unit/Industry` 8 800 verts ; `tests/Unit/Service/Industry` 1 537 verts ; `tests/Unit/State` 2 179 verts (notices PHPUnit seulement, pas d'échec).
- `ProjectResearchApiTest` : 6 tests, 46 assertions, verts (base eve_app_test475 créée puis supprimée).
- PHPStan sur les 11 fichiers `src/` modifiés : 0 erreur. Deptrac : 0 violation.
- `npm run lint` : 0 erreur (225 warnings préexistants, aucun sur `ProjectResearch.vue`). Vitest `projectResearch`, `groupResearch`, `GroupIndustryDetail*` : 15 fichiers, 65 tests verts.

## Rappel prod
Aucune migration. Après déploiement : lancer « sync compétences » (Advanced Industry à 0, Metallurgy, Research), sinon durées « inconnues ». Pas de réimport SDE.
