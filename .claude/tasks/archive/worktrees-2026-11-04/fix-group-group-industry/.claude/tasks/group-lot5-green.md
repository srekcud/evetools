# Lot 5 Group Industry : phase verte (#154, #170 frais, #164/#257 borne)

Aucun test modifié. Rien commité.

- #154 : `GroupIndustryContributionService` à 2 arguments, `calculateMaterialValue()` et la dépendance Jita supprimées ; `resolveMaterialValue()` lit le prix BOM de la ligne (refus R2 si valeur reçue, R3 sans prix BOM). Front : `ContributeModal.vue` n'envoie plus `estimatedValue` pour un matériau à prix BOM, libellés et date de figeage via 4 clés i18n `groupIndustry.contribute.*` (en/fr).
- #170 frais : `EveConstants::DEFAULT_BROKER_FEE_PERCENT` / `DEFAULT_SALES_TAX_PERCENT` (3.6) remplacent les littéraux de `CreateProjectData`, `GroupIndustryProject`, `GroupIndustryProjectResource` ; `CreateGroupProjectProcessor` lit `IndustryUserSettingsRepository` (7e argument), taux x 100 arrondi à 4 décimales, repli sur `EveConstants::DEFAULT_*_RATE`.
- #164/#257 : `GroupIndustryProjectService::MAX_BOM_LINES = 1000`, `\RuntimeException` dans `buildBom()` après le remplacement des copies et avant la lecture des prix. Le message ne termine pas par un point après la limite (les tests refusent `1000.`).
- Glossaire : Prix BOM, Frais de courtage / Taxe de vente ajoutés.

Résultats : Unit 13207 tests OK (242 notices PHPUnit, 1 notice, 1 incomplet préexistants) ; deptrac 0 violation ; PHPStan sur les 7 fichiers touchés : aucune erreur ; vitest 941 OK (exit 0) ; lint 0 erreur ; build OK, dist et tsbuildinfo restaurés.
