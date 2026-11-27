# Note de câblage #1452 (écrite par l'orchestrateur, architect non lancé : économie de quota, câblage déjà établi par lecture)

## Lot 1 : Planificateur (front, aucune dépendance backend)
- `frontend/src/utils/planetaryPlanets.ts` : `buildPlanetPlan(..., planetMode = 'perTier')`. Entrées déjà disponibles : `catalog.planetTypes` (CC, launchpad, installations), `indexCatalog().typeById[...].volume`, arbres de `buildPlan()` (`TreeNode.factories`, `children`, `perDay`, `tier`), `factoriesOfTier()`. Aucune donnée SDE nouvelle, aucun appel API.
- `frontend/src/stores/planetaryPlanner.ts` : état `planetMode` ('auto'), action `setPlanetMode`, `planetPlan` computed passe le mode. `evaluateTemplate(template, catalog, account, overrides, planetMode?)` (`planetaryTemplates.ts`), appelé par `PiPlannerTemplates.vue` avec `store.planetMode`.
- Harnais de test : `frontend/tests/planetaryPlannerFixtures.ts` fournit `PLANNER_CATALOG` (Barren 25 415/19 000, Lava sans highTech, Temperate 24 000/18 000, launchpad 3 600/700/10 000, advanced 500/700, highTech 1 100/400, volumes P1 0,38 / P2 1,5 / P3 3 / P4 100), `PLANNER_ACCOUNT`, `withTypeVolume`, `withPlanetTypes`. Aucune fixture nouvelle à créer pour les cas de la spec ; un `MADE_P1` ou un choix `buy` s'écrit dans le test. Spec front : `frontend/tests/utils/planetaryPlanetPlan.spec.ts` est le modèle (helper `plan(...)`).
- Clés i18n : `frontend/src/i18n/locales/{fr,en}.json` sous `pi.planner.planets` ; `frontend/tests/i18n/planetaryPlannerKeys.spec.ts` vérifie la parité FR/EN.

## Lot 2 : Approvisionnement (backend)
- `src/Service/Planetary/PlanetaryInputNeedsCalculator.php` : `launchpadRows()` appelle `feedRatesByLaunchpad($colony, $flows, ...)` ; le plafond par `dailyNetImport` se calcule dans `launchpadRows()` à partir de `$flows` (`required`, `extraction`, `factoryOutput`, `unknownExtraction`), même formule que `colonyRow()` (produit localement = extraction + sortie d'usines). Aucune injection nouvelle.
- Harnais : `tests/Unit/Service/Planetary/PlanetaryInputNeedsCalculatorTest.php` (helpers `colony()`, `factoryPin()`, `launchpadPin()`, `route()`, `feedOf()`, `launchpadOf()`). Un schéma P2 -> P3 doit exister dans le stub du dépôt de schémas ; l'implementer lit le test existant pour les identifiants.

## Tests existants qui peuvent casser
- Aucun volontaire. Garde à vérifier : tests de débit de launchpad existants (colonies sans production locale du type routé) restent inchangés ; `planetaryPlanetPlan*.spec.ts` appelés sans 7e paramètre restent en `perTier`.
