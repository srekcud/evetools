# #806 : note de câblage (étape 1b)

Modèle : `acceptsOutsideFavoriteSystem` (projet, modèle, création, PATCH) et la proposition #804 (`ProjectT2BlueprintCopyProposal*`).

## 1. Production à créer ou modifier (back)
- `src/Entity/IndustryProject.php` : `bool $excludesInvention = false` + `isExcludesInvention()` / `setExcludesInvention(bool): static` (imite l.69 et 294-301).
- `src/Entity/IndustryProjectTemplate.php` : même champ (imite l.37, 147-154).
- `src/Entity/User.php` : `bool $industryExcludeInvention = false` + `isIndustryExcludeInvention()` / `setIndustryExcludeInvention(bool): static` (à côté de `industryBlacklistTypeIds`, l.40, 215-224).
- `migrations/Version20261113120000.php` (dernier : `Version20261113100000`) : `ALTER TABLE industry_projects ADD excludes_invention BOOLEAN DEFAULT false NOT NULL`, idem `industry_project_templates`, `users ADD industry_exclude_invention ...` ; `down` en DROP (imite `Version20261105170000`).
- `CreateProjectInput` : `?bool $excludesInvention = null` (l.69 est un `bool` non nul : ici nullable, D1). `UpdateProjectInput` : `?bool $excludesInvention = null` (imite l.38).
- `UpdateBlacklistInput` : `?bool $excludeInvention = null`. `BlacklistResource` : `bool $excludeInvention = false`. Attention : l'opération d'écriture est un `Put` (pas PATCH, la spec dit PATCH) ; garder `Put`, sémantique `null` = inchangé comme `groupIds`.
- `BlacklistProvider::provide` (l.34-37) et `UpdateBlacklistProcessor::process` (l.39-51) : écrire puis exposer `$user->isIndustryExcludeInvention()`.
- `CreateProjectData` : `bool $excludesInvention = false` (dernier paramètre nommé, défaut : aucun appelant cassé).
- `CreateProjectProcessor::process` (l.87-102) : `excludesInvention: $data->excludesInvention ?? $template?->isExcludesInvention() ?? $user->isIndustryExcludeInvention()` ; ordre explicite > modèle > réglages (règle 5). `IndustryProjectService::createProject` l.84 : `setExcludesInvention($data->excludesInvention)` avant `buildBom`.
- `CreateProjectTemplateProcessor` l.68 : `->setExcludesInvention($project->isExcludesInvention())`.
- `IndustryProjectResource` (l.129) et `IndustryProjectTemplateResource` (l.66, mapping l.92) : `bool $excludesInvention = false`. `ProjectResourceMapper::projectToResource` l.44.
- `UpdateProjectProcessor` : nouvelle `changeTheInventionExclusion($project, $data)` appelée après `changeTheBlacklist` (l.92) ; `null` : rien ; hors `Draft` : 400 ; même valeur : rien ; sinon set, `buildBom($project, $project->getOwner())`, `RuntimeException` : restaure + 400 (imite `changeTheSelectionPolicy` l.196-224). Accès existant : `assertAdminOrOwner` (l.71).
- `IndustryProjectService::buildBom` l.140 : passer `$project->isExcludesInvention()` à `replaceT2BlueprintCopiesByInventions` (5e paramètre `bool $excludesInvention`) ; dans la boucle l.743-749, si exclu : `unset(<produit>:copy)` + `continue` avant le test de probabilité, déclarations ignorées. Copie T1 et datacores partagés restent naturels (ils ne sont ajoutés que par les produits inventés).
- `T2BlueprintCopyProposal` : `STATE_INVENTION_EXCLUDED = 'invention_excluded'`. `T2BlueprintCopyProposer::proposalOf` : tester `isExcludesInvention()` en tête. `DeclareT2BlueprintCopiesProcessor` : rien à coder, l.81 répond déjà 400 si état ≠ `ready`.
- Nouveau : `src/ApiResource/Industry/Project/ProjectT2BlueprintsToProvideResource.php` (`shortName: 'ProjectT2BlueprintsToProvide'`, `Get` `/industry/projects/{projectId}/t2-blueprints-to-provide`, `uriVariables` Link vers `IndustryProjectResource`, `security ROLE_USER`), propriétés `string $id`, `bool $excludesInvention = false`, `array $lines = []` (`list<array{productTypeId:int,typeName:string,runs:int}>`). Provider `src/State/Provider/Industry/Project/ProjectT2BlueprintsToProvideProvider.php` copie de `ProjectT2BlueprintCopyProposalProvider` avec `assertAcceptedMember`.

## 2. Calcul sans duplication, accès
- Extraction minimale : `src/Service/Industry/Project/InventedProductsReader.php` (classe non finale, constructeur `InventionService`), méthode publique `inventedProductsOf(IndustryProject): array<int, array{typeName: string, manufacturingRuns: int}>`, corps déplacé tel quel depuis `T2BlueprintCopyProposer::inventedProductsOf`. Le proposer remplace son 3e paramètre `InventionService` par `InventedProductsReader` (il n'utilise `InventionService` que là).
- Le provider « à fournir » : option inactive `lines = []` ; sinon lignes de `inventedProductsOf`, `runs = manufacturingRuns`, tri `typeName`. La BOM d'un projet exclu garde ses lignes de fabrication T2, donc la même lecture convient.
- Accès lecture : `ProjectAccessChecker::assertAcceptedMember(User, IndustryProject)` (celui de `ProjectProvider` l.47 et `ProjectChainProvider` l.60) ; 404 via `UuidOrNotFound::of` + `find` null.

## 3. Harnais et tests touchés
- Unit : `InventedProductsReader` avec `InventionService` stubbé ; provider avec `Security`, `IndustryProjectRepository`, `ProjectAccessChecker` stubbés (modèle : tests du provider de proposition). `User` : `new User()` (Blacklist tests) ou `createStub(User::class)` qui rend `false` pour un `bool` (CreateProjectProcessorTest l.656) : pas de casse.
- Functional : `tests/Functional/Api/Industry/Project/ProjectT2BlueprintCopyApiTest.php` (traits `BuildsIshtarGroupProject`, `SeedsIshtarBlueprintsAndTheProjectProduct`, `seedTheInventionOfTheIshtar`) sert de base au GET « à fournir », au plan sans invention et au POST 400. Création/modèle/PATCH : `ProjectCreationApiTest`, `ProjectTemplateApiTest`, `ProjectStructurePolicySwitchApiTest`.
- Vitest : `tests/components/group-industry/{productionChain,t2BlueprintCopyProposal,projectBlacklistEditor,newProjectModalOutsideFavoriteSystem,newProjectModalFromTemplate}.spec.ts`, `tests/components/industry/blacklistConfigFailure.spec.ts`, `tests/stores/blacklistFailure.spec.ts` (mock `apiRequest`).
- À adapter au rouge (tests/ verrouillé ensuite) : `tests/Unit/Service/Industry/Project/T2BlueprintCopyProposerTest.php` l.89 (`new InventedProductsReader($inventionService)`). Aucun autre constructeur cassé : `CreateProjectData`, `T2BlueprintCopyProposal`, ressources gardent leurs signatures (ajouts à défaut).
- Front, vue-tsc inclut `tests/**/*.ts` : si `excludesInvention` devient obligatoire dans `GroupProject` / `ProjectTemplate` et `excludeInvention` dans `BlacklistConfig`, environ 32 fichiers de tests (`grep -rl acceptsOutsideFavoriteSystem frontend/tests`, dont `projectCockpitFixtures.ts`, `projectTemplateFixtures.ts`) et 5 fixtures blacklist échouent au typage, pas à vitest. Proposition : champ obligatoire, fixtures partagées complétées au rouge.

## 4. Squelettes avant le rouge
- Champs entité + getters/setters, colonnes et migration (sinon schéma de test KO, pas d'assertion).
- `T2BlueprintCopyProposal::STATE_INVENTION_EXCLUDED`, propriétés des inputs/ressources (défauts ci-dessus), `CreateProjectData::$excludesInvention`.
- `InventedProductsReader` (méthode qui lève `\LogicException('not implemented')`) et constructeur du proposer déjà basculé.
- Ressource + provider « à fournir » renvoyant une ressource vide (`lines = []`, `excludesInvention = false`), pour échouer sur l'assertion et non en 404 de route.
- Front : `stores/group-industry/t2BlueprintsToProvide.ts` (`fetchToProvide(projectId)`, `toProvide`, `error`) ; `stores/industry/blacklist.ts` : `updateExcludeInvention(value: boolean)` envoyant `{ excludeInvention }` en `PUT` ; types `T2BlueprintCopyProposalState` + `'invention_excluded'`, `T2BlueprintsToProvide`.
- Vues : `ProductionChain.vue` (bloc au-dessus de `ProjectReactionSteps`, testid `chain-t2-to-provide`), interrupteur à côté de `ProjectBlacklistEditor` dans `GroupIndustryDetail.vue` (l.631, props `status`, `canEdit`, nouveau composant `ProjectInventionExclusionToggle.vue`), `NewProjectModal.vue` (ref initialisée depuis `blacklistStore.blacklist?.excludeInvention`, reprise dans `applyTemplate` l.338, envoyée l.461), `BlacklistConfig.vue`, `T2BlueprintCopyProposal.vue`. i18n : `groupIndustry.chain.*`, `groupIndustry.t2BlueprintCopyProposal.*`, `groupIndustry.blacklistEditor.*`, `industry.blacklist.*` dans `frontend/src/i18n/locales/{fr,en}.json`.

## 5. Points d'attention
- Les 3 chemins de création (formulaire, modèle, Buy vs Build via `MarginBridgeActions` puis le modal) arrivent tous à `POST /industry/projects` et donc à `CreateProjectProcessor` : un seul point de résolution `null`.
- `ProjectBuyOrBuildSwitch` et autres appelants de `buildBom` lisent le projet : l'option s'applique sans changement.
- PATCH : draft seulement, même si seuls les types de la blacklist sont envoyés hors draft aujourd'hui ; test de restauration avec un `buildBom` qui lève.
- Tests exhaustifs voisins : `ProjectResourceMapperProjectTest` et `BuildsProjectTemplates` vérifient champ par champ, sans liste fermée trouvée ; aucun snapshot vitest relevé.

## Questions de spec ouvertes
- Q1 : priorité des états de proposition : projet de groupe ou terminé qui exclut l'invention : `invention_excluded` ou `not_solo` ? Proposition : `invention_excluded` en premier (règle 9 sans condition).
- Q2 : la spec dit `PATCH /industry/blacklist`, le code expose `PUT` : garder `PUT`.
- Q3 : modèle sans valeur explicite mais réglages actifs : le modèle prime (règle 5 dernière phrase), confirmé dans l'ordre ci-dessus.
