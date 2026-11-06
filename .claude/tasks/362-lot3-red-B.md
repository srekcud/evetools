# #362 lot 3, sous-lot B, phase rouge

## Contrat que le vert doit respecter (non deductible de la spec)
- Palette : une seule source `GET /industry/projects` (tableau de GroupProject), `loadProjects({ enabled })`. Le badge d'origine (« Industry » / « Group Industry ») disparait : le texte d'une option est le libelle seul (`groupProjectLabel`), suivi de « Completed » pour un projet termine. L'avis d'indisponibilite garde le texte « Group Industry projects unavailable » (cle `groupIndustryUnavailable`). Module `industry` masque : les projets restent visibles ; `group-industry` masque : ni entrees ni appel a `/industry/projects`.
- Industry.vue : sur l'intent `projects`, `router.push('/group-industry')` (chaine exacte, un seul appel) et l'intent reste dans le store. Plus de bouton « Projets » (texte exact `Projects`) ; `?tab=projects` affiche IndustryDashboard sans `router.replace`.
- GroupIndustryList : au montage, consomme `navigationIntent` (le remet a null). `openCreateModal` ouvre NewProjectModal vide (bouton « Create Project » present et desactive). `prefill` l'ouvre avec 1 item (typeId, typeName, ME, TE, runs) ; le POST `/industry/projects` porte `private: true`, `items: [{typeId:22456,typeName:'Sabre',meLevel:10,teLevel:20,runs:5}]` et `blacklistTypeIds: [11558]`.
- IndustryDashboard : charge lui-meme `GET /industry/projects` (store de groupe), compte « actifs » = statut different de `completed` (un `draft` compte), la liste des projets recents affiche le libelle `groupProjectLabel` (nom du projet). Le KPI profit estime et les alertes de profit negatif du solo n'ont pas de donnee dans GroupProject : leurs tests sont supprimes (hypothese : le vert retire ces blocs ou les rend « inconnus »).
- E15 : aucun litteral d'URL passe a apiRequest/authFetch/fetch ne contient `group-industry`, `/steps` ni `/projects/{id}/(shopping-list|cost-estimation|bpc-kit|copy-costs)`. `/api/shopping-list/search-structures` (module liste d'achats) reste legitime.

## Fichiers supprimes (60)
- components/industry : bpcKitTab* (4), costEstimationTab* (5), projectDetail* (3), projectTable* (3), shopping* (10), stepHierarchyTree* (2), stepTree* (5), stepsTab* (4) : cible supprimee.
- components/industry/industryDashboardProfitTotal : KPI profit sur projets solo (IndustryProject), pas d'equivalent dans GroupProject.
- composables : useShoppingStockAnalysis, useStockAnalysis (orphelins apres ShoppingTab), useProjectTime (seul appelant : ProjectDetail ; le test ne couvrait que l'estimation du temps a partir d'etapes ; `formatDuration` reste couvert par useFormatters).
- utils/projectProfitTotal : appelants ProjectTable et dashboard solo seulement. A CONFIRMER : si le vert garde l'util, le test peut etre restaure.
- stores : projectsBpcKitOfAnotherProject, projectsCostEstimationFailure, projectsCreateProject, projectsCreateProjectOutsideFavoriteSystem, projectsFailedProductsAfterPatch, projectsMatchJobsFailedCharacters (le matching vit dans le store de groupe, couvert au sous-lot A), projectsMaxJobDuration (formulaire solo), stepInStockFromServer, stepsAddChildJob, stepsDeleteStep, stepsFixedRuns, stepsPatchListResponse, stepsSurplusJobs.
- views : Industry.spec (uniquement formulaire de creation solo et prefill de ce formulaire ; le prefill est desormais teste cote GroupIndustryList), IndustryCreateProjectOutsideFavoriteSystem, IndustryProjectAndTypeQuery (reecrit en IndustryTypeQuery, partie « type in the URL » conservee a l'identique), IndustryProjectIdValidation.
- Non supprimes : components/shopping/missingVolume* (utilises par Appraisal, StockpileDashboard, StockpileImportPanel).

## Fichiers adaptes (cas retires et raison)
- views/IndustryDataFreshness : cas `projects` retire de W2 (onglet supprime) ; test « project detail opened from the margins tab » retire (ProjectDetail supprime).
- views/IndustryAnalyzeProductIntent : source `projects` retiree du it.each (onglet supprime).
- views/IndustryOpenedTypeDefaults : source `projects` retiree du it.each.
- views/IndustryPillClearsTypeQuery : parametre residuel `projectTab: 'shopping'` remplace par `origin: 'dashboard'` (meme assertion : les autres parametres sont conserves).
- views/IndustryTabQuery, IndustryAnalyzeProductIntent, IndustryOpenedTypeDefaults, IndustryPillClearsTypeQuery : stubs ProjectTable/ProjectDetail et spy `fetchProjects` retires (le store n'a plus l'action).
- stores/industryStoreFailureMessagesLocale : ne garde que `analyzeMargin` (fallback en/fr, message preserve) ; les 8 actions projet et 11 actions d'etapes retirees (actions supprimees). Le test « keeps the message » vise analyzeMargin/marginError au lieu de fetchProjects.
- components/industry/timeEfficiencyInputStep : ne garde que le cas de l'analyse de marge (StepTree, ProjectTable et formulaire solo supprimes).
- a11y/iconButtonsHaveName : cas ProjectTable et ProjectDetail retires (cibles supprimees), plus les helpers/fixtures associes.
- components/common/commandPaletteHarness : une seule API `projects` (GroupProject[] complet), fixtures `RAVEN_BATCH`, `UNNAMED_COMPLETED_PROJECT`, `CORP_TITAN` toutes en GroupProject ; `industryProject` supprime.
- components/common/CommandPaletteProjects : reecrit sur une source ; retires : libelle « Industry », badge d'origine, libelle d'un projet Industry sans nom de type (IndustryProject disparait), avis d'indisponibilite de l'origine industry, test « industry masque retire les projets » (inverse : les projets restent), test « Group Industry indisponible mais projets Industry listes » (une source).
- components/common/CommandPaletteTypes : « Raven batch Industry » -> « Raven batch » (badge supprime).
- components/common/CommandPalette : stub `/industry/projects` -> `[]` ; entree « Industry › Projects » retiree des listes attendues.
- composables/useNavigationEntries, views/NavigationEntryTabsContract, utils/commandPaletteSearch : onglet `projects` retire (catalogue de test inclus ; le test « cuts at ten » gagne « Group Industry » en dixieme).
- layouts/mainLayoutHarness : stub `/api/industry/projects` -> `[]`, stub group-industry supprime.
- buyVsBuildCreateProject : inchange (il n'asserte que l'intent `projects` + prefill, deja vrai).

## Nouveaux tests (8)
- CommandPaletteProjects : « reads the projects once from the unified list and opens each one on its project page » (E9).
- groupIndustryListNavigationIntent (2) : prefill avec blacklistTypeIds + intent consomme ; openCreateModal -> modale vide (E10).
- industryDashboardProjects (1) : 3 projets dont 1 completed -> 2 actifs (E11).
- IndustryProjectsModuleRemoved (3) : pas de pastille Projects, `?tab=projects` -> dashboard (E14), intent projects -> `router.push('/group-industry')` et intent laisse dans le store (E10, cote Industry).
- architecture/removedApiRoutes (1) : E15.
- Non teste (budget) : « nouveau projet » du dashboard pose `{openCreateModal:true}` (deja vrai), vue d'un projet -> `/group-industry/{id}` (a verifier a la main ou ajouter si l'implementation passe par un emit).

## Rouges attendus avant le vert (10 fichiers)
- Nouveaux : tous les tests ci-dessus echouent sur l'URL (`/group-industry/projects` appelee), l'absence de la source unique, la pastille Projects presente, `router.push` non appele, intent non consomme, compte 0.
- Adaptes dependants du vert : CommandPaletteProjects, CommandPalette (2), CommandPaletteTypes (14 : la source de projets est indisponible tant que la palette lit encore l'ancienne route), useNavigationEntries (2), NavigationEntryTabsContract (1), IndustryDataFreshness W3 (le `fetchProjects` reel du solo echoue sur une reponse nulle et fait apparaitre la bannière d'erreur ; disparait avec l'action).
- Tout le reste vert ; lint 0 erreur ; `npm run build` (vue-tsc) passe.
