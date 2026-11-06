# Câblage #337 (écrit par le thread, 0 sous-agent)

Spec : `docs/specs/337-production-chain.md`. Carte : `docs/modules/industry.md`.

## Découpe

- **Back A, structure conseillée persistée (R9b, R9c, E15, E16)** : `ProductionPlanning::planWithStructuresOf(ProductionPlanRequest): PlannedProduction` (`src/Industry/Application/PlannedProduction.php` : `$plan`, `structureChoiceFor(int $productTypeId, ActivityKind $activity): ?StructureChoice`) ; `planOf` en dépend (`->plan`) et garde son contrat. Le journal des choix : un petit collecteur mutable passé à `StructureMaterialModifiers` (qui reste readonly : le collecteur est un objet) ou un second appel du sélecteur après le plan, au choix de l'implementer tant que `planOf` ne change pas de résultat. Le dernier choix par (produit, activité) gagne.
- `IndustryProjectBomLine` : `plannedStructureId` (`?string`, 36), `plannedStructureStatus` (`?string`, 40) ; getters et setters. Migration `Version20261106120000` (irréversible, `down()` lève, colonnes nullables, lignes gardées).
- `IndustryProjectService::planOf` privée appelle `planWithStructuresOf` ; `jobLinesOf` ajoute `plannedStructureId` et `plannedStructureStatus` aux lignes `final` et `component` seulement (copie, invention, matériaux : null) ; `buildBom` les copie sur la ligne ; `updateLineInPlace` les met à jour pour une ligne de job. Statuts : minuscules avec tiret bas, à partir de `StructureChoiceStatus` (table de correspondance exhaustive : `match` sans `default`).
- **Back B, chaîne (R1 à R11)** : `src/Service/Industry/Project/ProjectChainBuilder.php` (pur : aucune dépendance de dépôt ; entrée : `IndustryProject`, lignes de BOM avec liens et achats, apports approuvés, ventes, noms de structure déjà résolus par id ; sortie : `ProjectChain` readonly avec `list<ProjectChainNode>`). `ProjectChainResource` (`src/ApiResource/Industry/Project/`, `Get /industry/projects/{projectId}/chain`, `uriVariables` Link vers `IndustryProjectResource`, calquée sur `ProjectDistributionResource`) et `ProjectChainProvider` (`src/State/Provider/Industry/Project/`, calqué sur `ProjectBomProvider` : `assertAcceptedMember` ; dépôts `IndustryProjectBomLineRepository`, `IndustryProjectContributionRepository::findApprovedByProject`, `IndustryProjectSaleRepository::findByProjectForListing`, `IndustryStructureConfigRepository` pour le nom ; une seule lecture de chaque, jamais une requête par nœud).
- **Front (R12 à R14)** : `frontend/src/stores/group-industry/chain.ts` (+ types dans `types.ts`), `components/group-industry/ProductionChain.vue` (props `projectId`) et sous-composants `ProductionChainFlow.vue`, `ProductionChainJobTree.vue` ; i18n `groupIndustry.chain.*` dans `fr.json` et `en.json`. Aucun fichier du cockpit (#336) ni `GroupIndustryDetail.vue`, `groupProjectRefreshes.ts`.

## Harnais de test

- Plan avec structures : `tests/Unit/Industry/Application/ProductionPlanningStructureSelectionPolicyTest` et `StructureMaterialModifiersTest` donnent le montage (structures configurées, `FavoriteSystems`, catalogue en mémoire).
- BOM et service : `tests/Unit/Service/Industry/Project/GroupIndustryBomGoldenCharacterizationTest` (support `BuildsProjectOnGoldenSde`) et `IndustryProjectServiceTest` ; persistance : `tests/Integration/Service/Industry/Project/GroupIndustryBomPersistenceTest` (base `TEST_TOKEN=n337`).
- Fournisseur : `tests/Unit/State/Provider/Industry/Project/ProjectBomProviderTest` et `GroupProjectAccessProfiles` (profils d'accès) ; routes : `tests/Unit/.../IndustrySubResourceUriVariablesTest` (cas à ajouter) ; fonctionnel : `tests/Functional/Api/Industry/Project/` (un test par rôle : 403, 404, forme de la réponse).
- Migration : `tests/Integration/Migrations/NullableContributionValueMigrationTest` donne le montage (base avec une ligne, colonnes ajoutées, ligne gardée, `down()` lève).
- Front : `frontend/tests/components/group-industry/jobsTable*.spec.ts` et `financialSummaryUnknown*.spec.ts` (montage Pinia, i18n, cas « inconnu »).

## Tests existants appelés à changer

- Aucun comportement existant ne change : `planOf` garde son résultat ; la BOM expose les mêmes champs. Les golden de BOM (`GroupIndustryBomGoldenCharacterizationTest`) restent verts ; les ajouter à la recherche des formes figées si un constructeur de ligne ou de service change.
- Tests exhaustifs voisins : nombre de routes du projet (`debug:router` des gabarits `industry/projects`, `ProjectStepRoutesRemovedApiTest`, `IndustrySubResourceUriVariablesTest`) ; `docs/modules/industry.md` (routes et classes) mis à jour par le doc-writer ou par moi après le vert.
