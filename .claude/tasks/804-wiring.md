# #804 : note de câblage (BPC T2 déclarés)

Spec : `docs/specs/804-owned-t2-blueprint-copies.md`. Précédent : #708 (`IntermediateStockProposer`, `ProjectIntermediateStockProposalApiTest`, `intermediateStockProposal.spec.ts`).

## 1. Backend plan (lot A)

| Pièce | Cible | Dépendances | Harnais |
|---|---|---|---|
| Entité | `src/Entity/IndustryProjectDeclaredBlueprintCopy.php` (uuid, `project` ManyToOne `onDelete: CASCADE`, `productTypeId`, `runs`, unique (project_id, product_type_id)) | aucune ; pas de repository (lecture par la collection) | `new` direct dans les tests |
| Collection | `IndustryProject::$declaredBlueprintCopies` OneToMany `cascade: persist, remove`, `orphanRemoval: true` ; `getDeclaredBlueprintCopies()`, `replaceDeclaredBlueprintCopies(array)` ou add/remove, `declaredRunsByProductTypeId(): array<int,int>` | — | appel direct sur un `IndustryProject` construit en mémoire |
| Migration | `migrations/Version20261113xxxxxx.php` (après `Version20261112170000`), `up` + `down`, aucun commentaire | — | `tests/Integration/Migrations/MigrationsReplayTest` compare migrations et mapping : noms d'index/FK et contrainte unique doivent coller exactement à l'entité |
| Plan | `IndustryProjectService::buildBom` (l.116) passe `$project->declaredRunsByProductTypeId()` en 4e argument de `replaceT2BlueprintCopiesByInventions` (l.722) | aucune nouvelle dépendance de constructeur (donnée portée par le projet) | harnais existants inchangés : `IndustryProjectServiceTest` (`givenInventedProducts`, Hail L) et `BuildsProjectServiceOnGoldenSde` (stub `InventionService` l.119) ; le test ajoute la déclaration sur le projet |

Changement de `replaceT2BlueprintCopiesByInventions` : calculer `runsAInventer = runsFabriqués − min(déclarés, runsFabriqués)` **avant** le contrôle de probabilité ; si 0 : `unset(<produit>:copy)`, `continue` sans invention, copie T1, datacore ni `InventionProbabilityMissingException` (D4) ; sinon `attempts` sur `runsAInventer`. Un produit déclaré absent des produits inventés est ignoré (R5), rien à faire. Le `getInventionData` reste appelé pour tous (il donne `null` pour un T1).

## 2. Backend proposition + API (lot B, dépend de A)

| Pièce | Cible | Dépendances | Harnais |
|---|---|---|---|
| Stock container | `ProjectContainerStock` : 3e paramètre `public array $contentItemIds = []` (list<int>) ; `ProjectContainerService::containerStockOf` (l.170) le remplit avec les `itemId` de `$named['contents']` (sous-conteneurs compris) | — | défaut `[]` : les `new ProjectContainerStock(...)` existants (`IntermediateStockProposerTest` l.54, `ProjectContainerService`) compilent ; test unitaire de `containerStockOf` dans `ProjectContainerServiceTest` (668 l., ne pas lire en entier, chercher `containerStockOf`) |
| Proposeur | `src/Service/Industry/Project/T2BlueprintCopyProposer.php` (`final readonly`, `proposalOf(IndustryProject): T2BlueprintCopyProposal`) + `T2BlueprintCopyProposal` (STATE_READY/NOT_SOLO/ASSETS_UNKNOWN, `unknownReason`, `lines`) + `T2BlueprintCopyProposalLine` | `OwnedBlueprintRepository` (`findByCharacters($owner->getCharacters())`, `findByCorporationId`), `ProjectContainerService::containerStockOf`, `InventionService::getInventionData` (produits inventés), `IndustryActivityProductRepository::producerOf` (blueprint du produit) | stubs (`createStub`) des 4 classes, toutes non finales ; `IndustryProject` + `IndustryProjectBomLine` en mémoire comme `IntermediateStockProposerTest` |
| Ressource | `src/ApiResource/Industry/Project/ProjectT2BlueprintCopyProposalResource.php`, shortName `ProjectT2BlueprintCopyProposal`, `id` identifiant (= projectId) | — | — |
| GET | `Get` `/industry/projects/{projectId}/t2-blueprint-copy-proposal`, `uriVariables: ['projectId' => new Link(fromClass: IndustryProjectResource::class)]`, provider `src/State/Provider/Industry/Project/ProjectT2BlueprintCopyProposalProvider.php` | `Security`, `IndustryProjectRepository`, `ProjectAccessChecker::assertOwner`, `T2BlueprintCopyProposer`, `UuidOrNotFound` | fonctionnel `WebTestCase` sur le modèle de `ProjectIntermediateStockProposalApiTest` + traits `BuildsIshtarGroupProject` / `SeedsIshtarBlueprintsAndTheProjectProduct` (Ishtar = T2 inventé) ; `OwnedBlueprint` copie persistée en fixture |
| POST | même ressource, `Post` `/industry/projects/{projectId}/t2-blueprint-copies`, `uriVariables` Link, `status: 200`, `input: DeclareT2BlueprintCopiesInput::class` (`src/ApiResource/Input/Industry/Project/`, `copies: list<{productTypeId, runs}>`, `Assert`), processor `src/State/Processor/Industry/Project/DeclareT2BlueprintCopiesProcessor.php` | `Security`, `IndustryProjectRepository`, `ProjectAccessChecker`, `T2BlueprintCopyProposer`, `IndustryProjectService::buildBom`, `EntityManagerInterface`, provider (relecture) | unitaire : mocks des frontières (repository, EM, service) comme `UpdateProjectProcessorTest` ; fonctionnel : 403/400/200 |

Tout ou rien : `buildBom` ne fait ni `persist` ni `flush` (vérifié, l.116-187 et `reconcileBomLines`). Le processor suit `UpdateProjectProcessor::changeTheBlacklist` (l.233-265) : garder l'ensemble précédent, remplacer la collection, `buildBom($project, $owner, refuseToDropWorkedLines: status !== Draft)` dans un `try`, sur `\RuntimeException` restaurer la collection et lever `BadRequestHttpException`, `flush` seulement en cas de succès. Contrôles avant mutation : état ≠ `ready` → 400 ; entrée hors lignes ou `runs` hors `[1, proposedRuns]` → 400. Pas de `wrapInTransaction` (rien à annuler côté base, et l'EM resterait utilisable). Test du refus : `UnitOfWork` jamais flushé, `count(getDeclaredBlueprintCopies())` inchangé.

## 3. Front (lot C, dépend de B)

- Types : `frontend/src/stores/group-industry/types.ts` (après l.1130) : `T2BlueprintCopyProposal`, `...Line`, `...State`, `...UnknownReason` (ajoute `blueprints_not_synced`), `T2BlueprintCopySelection`.
- Store : `frontend/src/stores/group-industry/t2BlueprintCopyProposal.ts` (modèle `intermediateStockProposal.ts` : `latestRequest`, `openedProjectId`) ; `declare(projectId, selections)` = un seul `POST` puis `fetchProposal` et `useGroupProjectStore` (rechargement BOM) ; erreur 400 → `validationError` = message serveur.
- Composant : `frontend/src/components/group-industry/T2BlueprintCopyProposal.vue` (modèle `IntermediateStockProposal.vue`, props `projectId`, `projectStatus`, emit `declared`).
- Branchement : `frontend/src/views/GroupIndustry/GroupIndustryDetail.vue` l.657, juste après `<IntermediateStockProposal>`, même `v-if="isOwner && !isCompleted"`, `@declared` → même rechargement que `onContributionSubmitted`.
- i18n : `frontend/src/i18n/locales/fr.json` et `en.json`, bloc frère de `intermediateStockProposal` (l.3062).
- Tests : `frontend/tests/components/group-industry/t2BlueprintCopyProposal.spec.ts` (`vi.mock('@/services/api')`, `mockImplementation` par chemin comme `intermediateStockProposal.spec.ts` l.89).

## 4. Tests existants touchés

- Aucun attendu ne change (rien déclaré = plan identique) ; à relancer au rouge : `IndustryProjectServiceTest` (l.855, l.914), `IndustryProjectServiceOwnedBlueprintTest`, `IndustryProjectServiceOwnedEfficiencyTest`, `GroupIndustryBomGoldenCharacterizationTest`, `GroupIndustryBomPersistenceTest` (Integration).
- `MigrationsReplayTest` : casse si la migration diffère du mapping.
- `IntermediateStockProposerTest` : construit `ProjectContainerStock` en positionnel, sûr tant que le nouveau paramètre est en 3e position avec défaut.
- `AccountDeletionServiceTest` : compte des tables liées à `industry_projects.owner_id` ; la FK `ON DELETE CASCADE` suffit, à vérifier si le service supprime par SQL brut table par table.
- Vues `frontend/tests/views/GroupIndustry/groupIndustryDetail*.spec.ts` (17 fichiers) : `apiRequest` = `vi.fn()` nu, il renvoie `undefined` pour la nouvelle URL ; le composant doit tolérer `proposal` absent (`proposal?.lines`), sinon ces tests cassent.
- Aucune liste exhaustive de routes ni test OpenAPI trouvé dans `tests/`.

## 5. Lots et ordre

A (entité, migration, plan) → B (container `contentItemIds`, proposeur, GET, POST) → C (front). Doc : ligne #804 dans `docs/modules/industry.md` (tableau l.146-147) au lot B.

## Questions ouvertes

- Q1 : « produit T2 inventé du plan » pour le proposeur : après couverture complète, la ligne `invention` disparaît ; le proposeur doit partir des lignes `manufacturing` dont `getInventionData` ≠ `null`, pas des lignes `invention` (sinon la proposition se vide après validation, contraire à R9).
- Q2 : copies corporatives : `findByCorporationId($owner->getCorporationId())` filtré par `contentItemIds` ; aucune vérification de rôle supplémentaire, la visibilité vient de `containerStockOf`.
