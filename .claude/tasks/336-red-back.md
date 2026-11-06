# #336 — phase rouge, lot backend

## Tests créés ou modifiés
- `tests/Unit/Service/Industry/Project/ProjectCockpitCalculatorTest.php` : 16 tests, `#[Group('issue-336')]`.
  - Exemples chiffrés de la spec : supply bloquée ×2 et `source_material` du plus grand reste (Fusion Thruster 50, à la place du PAU, dont l'id de type n'existe nulle part dans le dépôt) ; `produce` 1/6 et `launch_job` blueprint d'abord ; `selling` 60/100 (gestionnaire `record_sale` 40, membre `nothing_to_do`) ; 100/100 (`complete_project`) ; job non livré en `selling` (`jobs_unfinished` ×1, avec en plus `materials_missing` ×1) ; `draft` 0/2 ; `completed` avec un matériau non couvert ; 2 contributions `pending` avant `source_material`.
  - Cas limites : zéro matériau (0/0, jamais bloqué), runs `null` (quantité `null`), vente d'un type non final ignorée, ventes au-delà du requis, ordre component avant final, égalité de reste (nom croissant), rôle gestionnaire et membre pour `draft`, `published`, `in_progress` jobs livrés, `selling`.
- `tests/Unit/State/Provider/Industry/Project/ProjectCockpitProviderTest.php` : 5 tests (401, 404, 403 pour un étranger et pour un collègue de corpo non participant, mapping complet d'un projet réel, owner et admin gestionnaires et membre non). Utilise le trait `GroupProjectAccessProfiles`.
- `tests/Functional/Api/Industry/Project/ProjectCockpitApiTest.php` : 3 tests (participant accepté 200 avec 5 phases et `source_material` Tritanium 12 000 bloquée, étranger 403, projet privé d'un autre : 403 sans `phases`, sans `nextAction`, sans nom de type).
- `tests/Functional/Api/Industry/Project/GroupProjectReadApiTest.php` : `/cockpit` ajouté à `projectReadRoutes` (corpmate 403, pending 403, owner et membre 200) et à `projectRoutes` (identifiant non UUID 404).

## Sortie rouge
- Calculateur : `Tests: 16, Assertions: 16, Failures: 16`, toutes « Failed asserting that two arrays are identical » ou « null is identical to Array ».
- Provider : `Tests: 5, Failures: 5` (`'granted'` au lieu de 401, 404, 403 ; tableaux différents).
- Fonctionnel (base `eve_app_testn336`) : `Tests: 47, Assertions: 80, Failures: 6` sur `ProjectCockpitApiTest|GroupProjectReadApiTest`. Les deux cas `owner/accepted member cockpit` (200 attendu) passent déjà : le squelette répond 200, ils ne peuvent pas être rouges.
- Reste vert : `tests/Unit/Service/Industry/Project` (267 tests, seuls les 16 nouveaux échouent), `tests/Unit/State/Provider/Industry/Project` (242 tests, seuls les 5 nouveaux échouent), `tests/Unit/ApiResource` OK (367 tests, dont `IndustrySubResourceUriVariablesTest` qui découvre la nouvelle ressource tout seul).

## Squelettes de production (exception TDD rouge)
- `src/Service/Industry/Project/` : `ProjectCockpitCalculator::calculate(ProjectCockpitInput): ProjectCockpit` (renvoie phases vides et une action au code vide) ; objets de valeur `ProjectCockpitInput` (status, productCount, bomLines, soldQuantityByTypeId, pendingContributionCount, viewerIsManager), `CockpitBomLine` (id, typeId, typeName, requiredQuantity, fulfilledQuantity, isJob, jobGroup, runs, matchedRuns), `ProjectCockpit`, `CockpitPhase` (key, state, counterDone, counterTotal, blockedReason), `CockpitBlockedReason` (code, count), `CockpitNextAction` (code, blocked, tab, targetStatus, bomLineId, typeId, typeName, quantity).
- `src/ApiResource/Industry/Project/ProjectCockpitResource.php` : `GET /industry/projects/{projectId}/cockpit`, `Link` vers `IndustryProjectResource`, propriétés `projectId` (identifiant), `status`, `phases`, `nextAction`.
- `src/State/Provider/Industry/Project/ProjectCockpitProvider.php` : constructeur (`Security`, `IndustryProjectRepository`, `ProjectAccessChecker`, `ProjectCockpitCalculator`), `provide` renvoie une ressource vide.

## Choix à valider en phase verte
- Le provider lit le projet par ses collections (`getItems`, `getBomItems`, `getSales`, `getContributions`) : les tests unitaires ne bouchonnent aucun dépôt de lignes, de ventes ni de contributions.
- `nextAction` expose toujours les 8 clés, `null` quand elles ne s'appliquent pas. `tab` vaut `null` pour les actions à `targetStatus` (`start_project`, `start_selling`, `complete_project`) et pour `project_completed` : la spec ne leur donne pas d'onglet (R14 : `targetStatus` lance le flux de statut).
- `record_sale` ne porte ni `typeId` ni `bomLineId` (le reste à vendre couvre tous les produits finaux).
- Ventes au-delà du requis : seule l'action est figée (`complete_project`). Le compteur `sell` (5/3 ou plafonné à 3/3) n'est pas tranché par la spec, donc pas testé.
- Ids de type synthétiques : `Ishtar Blueprint` 12006, `Hammerhead II Blueprint` 2186, `Sabre Blueprint` 22457.
