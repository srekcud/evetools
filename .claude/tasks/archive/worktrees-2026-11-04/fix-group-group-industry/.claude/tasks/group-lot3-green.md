# Lot 3 Group Industry : phase verte (#146, #156)

Rien n'est commité. `tests/` et `frontend/tests/` intacts.

## Backend
- `GroupIndustryDistributionService`, `DistributionResult`, `MemberDistribution`, `GroupIndustryDistributionResource` : marge, part, part de profit et payout `?float`, `null` quand le coût total approuvé vaut 0 (R1).
- `GroupIndustryResourceMapper::projectToResource` : 4e paramètre `?float $approvedContributionValue` ; `totalBomValue` null dès qu'un matériau n'a pas de prix (R3) ; avancement = moyenne des `GroupIndustryBomItem::getFulfillmentPercent()` (R4) ; valeur approuvée calculée depuis `getContributions()` sinon (R7).
- `GroupIndustryContributionRepository::sumApprovedValueByProject` : une requête groupée, tous les projets donnés présents (0.0 par défaut), `[]` pour `[]`.
- `GroupProjectCollectionProvider` (4e arg) et `AvailableGroupProjectsProvider` (5e arg) : injectent le dépôt et passent la valeur approuvée.
- `GroupIndustryProjectResource::$totalBomValue` : `?float`.

## Frontend
- `DistributionTable.vue` : bandeau `groupIndustry.distribution.undistributable`, `—` pour part, profit, payout, totaux et légende, anneau sans segment pour une part inconnue, pas d'alerte de perte si marge null.
- `FinancialSummary.vue` : marge `<UnknownValue>` (plus de `?? 0`).
- `JobsTable.vue` : total du pied inconnu dès qu'un job n'a pas de coût. `BomTable.vue` : total et avancement lus du projet courant. `ProjectCard.vue` : `—` si valeur BOM null.
- `ProjectKpiCards.vue` : contributions = somme de `approvedContributionValue` ; pipeline = somme des valeurs connues + mention `groupIndustry.kpis.unknownValueExcluded` ; `pipelineDesc` corrigé. Clés ajoutées dans `en.json` et `fr.json`.

## Vérifications
- PHPUnit `--testsuite Unit` : 13115 tests, 0 échec (251 notices et 1 incomplet préexistants).
- PHPStan sur les fichiers touchés : seul le bruit `Uuid|null` connu.
- Deptrac : 0 violation.
- Vitest complet : 124 fichiers, 894 tests verts. Build : OK (dist et tsbuildinfo restaurés).
- Lint : 3 erreurs `no-irregular-whitespace`, toutes dans des specs verrouillées de la phase rouge (`distributionTableUndistributable.spec.ts:74`, `financialSummaryUnknownMargin.spec.ts:81`, `projectKpiCards.spec.ts:50`, regex avec espace insécable littérale). Aucune dans `src/`. À corriger en phase rouge.
- Intégrations non lancées (pas de base) : constructeurs des providers relus contre les tests (4e et 5e argument), requête groupée = 1 requête supplémentaire (`MAX_QUERIES` 5).

## Non fait
- Glossaire : « Répartition impossible » et « Avancement de la BOM » à ajouter après validation de la spec.
- Hors lot : #154 (`ContributeModal.vue`, valorisation des contributions).
