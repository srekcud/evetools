# #341 : portage de la politique de sélection sur le projet unifié, phase rouge

Branche `fix/night-341`. Spec : `docs/specs/341-structure-total-cost.md` (règles 7, 8, 10, 11 relues pour le projet unifié de #362).

## Bilan d'exécution

Suite complète : 15779 tests, 16 en échec, tous nouveaux (5 erreurs, 11 échecs). Base sans mes tests : 15761 tests, verte (le 1 incomplet et les 178 notices PHPUnit existaient déjà). 18 tests ajoutés : 16 rouges, 2 garde-fous déjà verts (seuil 0 valide, défaut du service). Les warnings et dépréciations de la suite viennent des propriétés encore absentes (lecture d'une propriété non définie, propriété dynamique) et disparaîtront en vert.

## Tests et raison de l'échec

| Fichier | Test | Échec actuel |
|---|---|---|
| `tests/Unit/ApiResource/Input/Industry/Project/CreateProjectInputOutsideFavoriteSystemTest.php` (nouveau) | `testInputRefusesAStructureOutsideTheFavoriteSystemWithTheDefaultMinimumOutsideSavingWhenNothingIsSent` | `[null, null]` au lieu de `[false, 500000.0]` : propriétés absentes |
| idem | `testNegativeMinimumOutsideSavingIsRejectedOnTheMinimumOutsideSaving` | aucune violation (pas de `PositiveOrZero`) |
| idem | `testInputAcceptingAStructureOutsideTheFavoriteSystemWithAMinimumOutsideSavingOfZeroIsValid` | vert (garde-fou : 0 doit rester valide) |
| `tests/Unit/State/Processor/Industry/Project/CreateProjectProcessorTest.php` | `testSelectionPolicySentAtCreationIsPassedToTheService`, `testSelectionPolicyAbsentAtCreationIsPassedToTheService…DefaultMinimumOutsideSaving` | `CreateProjectData::$acceptsOutsideFavoriteSystem` et `$minimumOutsideSaving` absentes |
| `tests/Unit/Service/Industry/Project/IndustryProjectServiceTest.php` | `testCreateProjectTakesTheSelectionPolicyOfTheData` | `Unknown named parameter $acceptsOutsideFavoriteSystem` (`CreateProjectData`) |
| idem | `testCreateProjectRefusesAStructureOutsideTheFavoriteSystemWithTheDefaultMinimumOutsideSavingWhenTheDataDoNotSayOtherwise` | vert (garde-fou : le défaut de `CreateProjectData` doit rester `IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING`) |
| `tests/Unit/State/Provider/Industry/Project/ProjectResourceMapperProjectTest.php` | `testSelectionPolicyOfTheProjectIsCopiedAsItIs`, `testProjectWithoutSelectionPolicyRefuses…DefaultMinimumOutsideSaving` | `IndustryProjectResource::$acceptsOutsideFavoriteSystem` et `$minimumOutsideSaving` absentes |
| `tests/Unit/Industry/Application/ProductionPlanningStructureSelectionPolicyTest.php` (nouveau, exemples chiffrés de la spec : A atelier à rig neutre, C riggé à 3 sauts, 10 runs) | 4 cas : seuil 100 000 → C (90 000 matériaux), seuil 150 000 = économie → C, seuil 500 000 → A (100 000), politique inactive → A | `Unknown named parameter $selectionPolicy` (`ProductionPlanRequest`) |
| `tests/Functional/Api/Industry/ProjectCreationApiTest.php` | `testCreationAcceptingAStructureOutsideTheFavoriteSystemKeepsTheSelectionPolicyOnTheProject` | clés absentes du `GET /api/industry/projects/{id}` |
| idem | `testCreationWithANegativeMinimumOutsideSavingCreatesNoProject` | 201 au lieu de 422 sur `minimumOutsideSaving` |
| `tests/Integration/Service/Industry/Project/ProjectBomStructureChoiceByTotalCostTest.php` (nouveau) | accepte, seuil 100 000 → platings 941 (structure voisine, 1 saut) | 928 : structure taxée au plus petit multiplicateur (aucun système favori, aucune politique) |
| idem | refuse → 970 (structure du système favori) | 928 |
| idem | accepte, seuil 1 000 000 → 970 | 928 |

Données du test d'intégration (frégate 10 runs ME 0, 100 platings par run, rig catégorie `basic_small_ship`, Raitaru 0,99, nullsec ×2,1, indice 4 %, prix des platings 5 000) :

| Structure | Système | Rig | Taxe | Platings | Coût total |
|---|---|---|---|---|---|
| Favorite Raitaru | favori | -1,0 % | 1 % | 970 | 5 050 000 |
| Neighbour Raitaru | 1 saut | -2,4 % | 0,5 % | 941 | 4 886 650 (transport 1 650) |
| Taxed Raitaru | 1 saut | -3,0 % | 10 % | 928 | 5 201 650 |

Économie Neighbour contre Favorite : 163 350 ISK, vérifiée avec `StructureChoiceByTotalCost` sur ces mêmes données (Neighbour retenue à 100 000 et 163 000, référence gardée à 163 400 et 1 000 000). Alliages attendus : 2 795 (Neighbour), 2 881 (Favorite). La Favorite porte un rig de la catégorie : sans lui, le filtre souple l'écarte même sans la politique.

## Ce que l'implémenteur doit faire

1. `CreateProjectInput` : `public bool $acceptsOutsideFavoriteSystem = false;` et `#[Assert\PositiveOrZero] public float $minimumOutsideSaving = IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING;`.
2. `CreateProjectData` : deux propriétés mêmes noms, mêmes défauts, à la fin de la liste ; `CreateProjectProcessor` les transmet ; `IndustryProjectService::createProject` les pose sur le projet (`setAcceptsOutsideFavoriteSystem`, `setMinimumOutsideSaving`).
3. `IndustryProjectResource` : `public bool $acceptsOutsideFavoriteSystem = false;`, `public float $minimumOutsideSaving` ; `ProjectResourceMapper::projectToResource` les copie.
4. `ProductionPlanRequest` : nouveau paramètre nommé `selectionPolicy` (`StructureSelectionPolicy`, défaut `favoriteSystemOnly()`, comme `ProductionCostRequest`).
5. `ProductionPlanning` : 4e paramètre du constructeur `?StructureTotalCostComparator $totalCostComparator = null` (même forme que `ProductionCostCalculator`, les 9 constructions existantes restent à 3 arguments) ; `planOf` passe le comparateur et `$request->selectionPolicy` au `StructureSelector`, et des runs de référence à `StructureMaterialModifiers` (les runs de la cible, règle 6 ; un seul cible dans les tests).
6. `IndustryProjectService` : **9e paramètre du constructeur `IndustryUserSettingsRepository`** (déjà passé en dernier argument par `IndustryProjectServiceTest`, `GroupIndustryBomGoldenCharacterizationTest` et `GroupIndustryBomPersistenceTest`, ignoré aujourd'hui). `planOf` lit les systèmes favoris du propriétaire (`FavoriteSystems` depuis `IndustryUserSettings`, `none()` sans réglages) au lieu de `FavoriteSystems::none()`, et passe `new StructureSelectionPolicy($project->isAcceptsOutsideFavoriteSystem(), new Isk($project->getMinimumOutsideSaving()))`.
7. `StructureChoiceByTotalCost` (`src/Service/Industry/`) n'a plus d'appelant (retiré du conteneur) : à supprimer ou à réutiliser.

## Points à faire valider par Sylvain

- **Changement de comportement voulu** : le plan du projet unifié lit désormais le système favori du propriétaire (filtre souple). Jusqu'ici `IndustryProjectService::planOf` passait `FavoriteSystems::none()` : la BOM prenait la structure au plus petit multiplicateur, où qu'elle soit. Le test « refuse → structure du système favori » l'exige ; sans ça, la règle 8 de la spec ne s'active jamais (elle demande un système favori). Les projets de groupe sont concernés aussi (BOM sur les structures du propriétaire). Aucun test existant ne casse : leurs propriétaires n'ont pas de réglages.
- Valeur du statut 400 de la spec pour un seuil négatif : l'API renvoie 422 (validation API Platform), comme les autres champs de `CreateProjectInput`. Le test fige 422.
- Frontend non touché (lot 3 de #362).
