# #362 lot 3, sous-lot C, phase rouge (liens de job, achats, formulaire de creation, duree max)

## Contrat que le vert doit respecter (non deductible de la spec)

### Store `useGroupProjectStore` (actions a ajouter, types `AvailableJob`, `PurchaseSuggestion`, `PurchaseSuggestions` a ajouter a types.ts)
- `fetchAvailableJobs(projectId)` : `apiRequest('/industry/projects/{id}/available-jobs')` (un seul argument), renvoie le tableau.
- `linkJob(projectId, lineId, esiJobId)` : `apiRequest('/industry/projects/{id}/bom/{lineId}/link-job', { method: 'POST', body: JSON.stringify({ esiJobId }) })` puis `fetchBom(projectId)`.
- `unlinkJob(projectId, matchId)` : `{ method: 'DELETE' }` sur `/industry/projects/{id}/job-matches/{matchId}` puis `fetchBom`.
- `fetchPurchaseSuggestions(projectId)` : GET `/industry/projects/{id}/purchase-suggestions` (un seul argument), renvoie `{suggestions, totalCount}`.
- `createPurchase(projectId, lineId, input)` : `input` = `{ transactionId }` (UUID `transactionUuid`) ou `{ typeId, quantity, unitPrice }` ; corps `JSON.stringify(input)` tel quel (ordre des cles typeId, quantity, unitPrice) ; POST `/industry/projects/{id}/bom/{lineId}/purchases` puis `fetchBom`.
- `deletePurchase(projectId, purchaseId)` : `{ method: 'DELETE' }` sur `/industry/projects/{id}/purchases/{purchaseId}` puis `fetchBom`.
- Chaque action qui change l'etat recharge la BOM elle-meme (comme `matchJobs`) : l'ecran n'ajoute pas un second GET. Les specs de store typent le store par un cast local (`jobLinksAndPurchases()` dans groupProjectUrls.spec.ts) : a retirer au vert si les signatures sont typees.
- Une erreur d'API est relancee (comme `matchJobs`) : les ecrans affichent `error.message`.

### JobsTable (proprietaire seul = `currentProject.myRole === 'owner'` ; modales/panneaux possibles, les specs stubent `teleport`)
- `link-job-open` : bouton « Link a job » (FR « Lier un job »), un par ligne de job ; au clic, un GET `available-jobs` (une fois).
- `available-job` : une ligne par job dispo, texte contenant `characterName` ; `available-job-link` : bouton « Link » dans la ligne ; `available-job-linked` : repere present seulement si `linkedToBomLineId` est non null, texte contenant `linkedToBomLineName`.
- `job-match-unlink` : bouton « Unlink » (FR « Delier ») dans chaque `job-match`, proprietaire seul.
- `job-link-error` : `role="alert"`, texte exact = message de l'erreur (lien ou suppression de lien), absent sinon.
- Achats sur une ligne de composant (`jobGroup === 'component'`) : `purchases-open` : bouton « Purchases » (FR « Achats »), proprietaire seul, un par ligne de composant ; au clic, un GET `purchase-suggestions` (une fois). Pas de bouton sur les lignes `final` ni `blueprint`.
- `purchase-suggestion` : une ligne par suggestion dont `typeId` = typeId de la ligne (les autres types sont filtres), texte contenant `characterName` ; `purchase-suggestion-link` : bouton « Link » ; `purchase-suggestion-linked` : repere seulement si `alreadyLinked`.
- `manual-purchase-quantity`, `manual-purchase-unit-price` : champs numeriques de la saisie manuelle (valeurs envoyees en nombres) ; `manual-purchase-submit` : bouton « Add purchase » (FR « Ajouter un achat ») ; le `typeId` envoye est celui de la ligne.
- `purchase` : une ligne par achat de `item.purchases`, visible sur la ligne sans ouvrir le panneau, une seule fois ; `purchase-delete` : bouton « Delete » dans la ligne, proprietaire seul.
- `purchase-error` : `role="alert"`, texte exact = message de l'erreur d'achat (lien, saisie, suppression), absent sinon.

### BomTable (lignes de materiau)
- Memes `purchases-open`, `purchase`, `purchase-delete`, `purchase-suggestion*`, `manual-purchase-*`, `purchase-error` que ci-dessus (composant partage conseille), proprietaire seul pour les boutons. Seul le comptage `purchases-open` = 1 et `purchase-delete` = 1 (un achat) est teste sur BomTable.

### NewProjectModal
- `project-outside-favorite` : case « Accept structures outside favorite systems » (FR « Accepter les structures hors systemes favoris »), decochee par defaut, jamais dans une des dix categories de blacklist.
- `project-minimum-outside-saving` : champ numerique, valeur initiale 500000, `disabled` tant que la case est decochee ; libelle « Minimum saving » (FR « Economie minimale »).
- Corps du POST : `acceptsOutsideFavoriteSystem` (bool) et `minimumOutsideSaving` (nombre) toujours envoyes, valeur par defaut 500000 case decochee.
- `DEFAULT_MINIMUM_OUTSIDE_SAVING = 500000` doit etre reexporte de `stores/industry/projects.ts` (supprime au lot B ; les specs utilisent le litteral 500000).

### Industry.vue / `useProjectsStore` (stores/industry/projects.ts)
- Restaurer `defaultMaxJobDurationDays` (ref, 2.0 par defaut), `setDefaultMaxJobDurationDays(days)` = `Math.max(days, 0.5)` (constante `MINIMUM_MAX_JOB_DURATION_DAYS = 0.5`) et l'exporter.
- Panneau de config : restaurer le bloc de l'ancien Industry.vue (origin/main, lignes ~500-516) : `<label for>` « Max job duration » + `input type=number min=0.5 step=0.1`, valeur affichee `toFixed(1)` (« 2.0 »), mise a jour sur l'evenement `change` (`parseFloat(...) || 2.0`) ; le spec declenche `input` puis `change`.
- i18n a restaurer en en.json ET fr.json (cles supprimees avec le formulaire solo) : `industry.config.maxJobDuration` (« Max job duration » / « Duree max des jobs ») et `industry.config.maxJobDurationHint` (« Jobs exceeding this duration will be split automatically » / « Les jobs depassant cette duree seront decoupes automatiquement »).
- Cles `groupIndustry.*` pour les libelles ci-dessus : au choix du vert, en en.json et fr.json.

## Fichiers (12 nouveaux tests)
- components/group-industry/jobsTableManualLink.spec.ts (3) : E16 liste + job deja lie + lien 9001 ; suppression de lien + erreur d'API ; non-proprietaire.
- components/group-industry/jobsTablePurchases.spec.ts (4) : E17 suggestions + `alreadyLinked` + lien par UUID ; saisie manuelle + erreur d'API ; suppression ; non-proprietaire (JobsTable composant + BomTable materiau).
- stores/groupProjectUrls.spec.ts (+2) : URL et corps des 6 actions.
- components/group-industry/newProjectModalOutsideFavoriteSystem.spec.ts (2) : E18.
- views/IndustryMaxJobDuration.spec.ts (1) : E19.
- Modifie : newProjectModalBlacklist.spec.ts (le corps attendu gagne `acceptsOutsideFavoriteSystem: false`, `minimumOutsideSaving: 500000` ; le test des dix categories exclut aussi `project-outside-favorite`). groupIndustryListNavigationIntent utilise `toMatchObject` : inchange.

## Rouges attendus (13 = 12 nouveaux + 1 adapte)
- Store (2) : `store.fetchAvailableJobs` / `fetchPurchaseSuggestions` is not a function.
- jobsTableManualLink (3) et jobsTablePurchases (4) : `link-job-open`, `purchases-open`, `job-match-unlink`, `purchase`, `purchase-delete` absents.
- newProjectModalOutsideFavoriteSystem (2) : `project-outside-favorite` absent.
- IndustryMaxJobDuration (1) : `defaultMaxJobDurationDays` indefini (store sans le champ).
- newProjectModalBlacklist (1) : corps sans les deux nouvelles cles.
- Reste de la suite verte (1634), lint 0 erreur, build vue-tsc OK.
