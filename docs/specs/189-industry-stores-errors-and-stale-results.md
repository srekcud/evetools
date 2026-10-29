# Spec #189 — Stores industrie : erreurs affichées et plus de résultat périmé après un échec

**Statut : validée en autonomie en mode nuit le 2026-10-29, à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage). Frontend seulement, aucun changement backend. Tests Vitest (stores et composants, `en.json`/`fr.json`), aucun test PHP. Les lectures annexes avalées citées par l'issue (`projects.ts:34-36`, `scanner.ts:146-148`, `structures.ts:127`) et leurs voisines sont décrites dans « Blocage : options » et ne sont pas à implémenter.

## Contexte

Les numéros de ligne de l'issue (audit du 2026-10-22) datent d'avant `fc7cf815` (retrait d'actions inutilisées, 2026-10-22), `d4d7c072` (remise à zéro du kit BPC, 2026-10-23), #193 et #197 (2026-10-29). Tout est revérifié ci-dessous dans le code actuel (`4daeb49a`). Aucun des trois points n'est déjà corrigé.

**Écran commun.** `Industry.vue:371` affiche `projectsStore.error` dans un `ErrorBanner` rouge refermable, en haut de la page, au-dessus de tous les onglets (projets, marges, scanner, détail de projet). Le store des projets y range les erreurs de toutes ses actions. Les stores `scanner`, `structures` et `blacklist` ne passent pas par ce bandeau.

**Point 1 — Estimation des coûts : branche d'erreur jamais atteinte.**

- `fetchCostEstimation()` (`projects.ts:166-177`) attrape l'erreur, la range dans `error` (texte brut ou `'Failed to fetch cost estimation'`) et rend `null` (`:173-176`).
- `CostEstimationTab.loadCostEstimation()` (`CostEstimationTab.vue:112-124`) reçoit `null`, pose `costData = null` et `lastFetchedAt = maintenant` (`:117-118`). Son `catch` (`:119-120`) n'est jamais atteint. La carte d'erreur de l'onglet, avec son bouton « Réessayer » (`:187-205`), ne s'affiche donc jamais.
- À l'écran : l'onglet est vide (ni `loading`, ni `error`, ni `costData`, `:180`, `:188`, `:208`). Seul le bandeau rouge du haut de page affiche le texte brut. Après un rechargement réussi puis un « Rafraîchir les prix » en échec, le résultat disparaît sans explication dans l'onglet.
- `fetchCopyCosts()` (`projects.ts:196-207`) suit le même schéma (`:203-206`). `loadCopyCosts()` (`CostEstimationTab.vue:153-162`) a un `catch {}` vide (`:157-159`, « Non-critical, just skip »), lui aussi jamais atteint. En cas d'échec, la section des coûts de copie (`:412`) disparaît : un projet qui demande des copies semble n'en demander aucune. C'est le « `catch {}` vide » de l'issue. Au `projects.ts:152-154` qu'elle cite, à sa date, il n'y avait que la construction des paramètres de `fetchShoppingList`, sans `catch`.
- `fetchCostEstimation` et `fetchCopyCosts` n'ont pas d'autre appelant que cet onglet. Les champs `costEstimation` et `copyCosts` du store ne sont lus par aucun composant.

**Point 2 — Résultat périmé après un échec.**

- `analyzeMargin()` (`projects.ts:213-245`) ne touche pas `marginResult` en cas d'échec (`:239-241`). L'erreur va dans `error`, le bandeau du haut de page. `ProfitMarginTab` lit `store.marginResult` (`ProfitMarginTab.vue:56`) et affiche le résultat dès qu'il existe (`:520`), même pendant un rechargement (ligne « Chargement » dans le résultat, `:523`). `analyzeType()` pose `lastFetchedAt = maintenant` même après un échec (`:343`).
- **Constat** : marge de l'Ishtar affichée, puis analyse du Vexor en échec. L'écran garde le résultat de l'Ishtar sous le produit Vexor, comme s'il était à jour. L'erreur n'apparaît qu'en haut de page.
- Scanner (`scanner.ts`) :
  - `fetchBuyVsBuild()` (`:76-102`) et `fetchPivotAnalysis()` (`:109-131`) effacent leur erreur au départ (`:84`, `:115`) mais gardent `buyVsBuildResult` / `pivotResult` en cas d'échec (`:97-98`, `:126-127`).
  - `BuyVsBuildTab` affiche l'erreur (`BuyVsBuildTab.vue:372-375`) **et** l'ancien résultat (`:386`, `v-else-if="result"`).
  - `PivotAdvisorTab` affiche l'erreur (`PivotAdvisorTab.vue:191-194`) **et** l'ancien résultat (`:205`, `result && !loading`).
- `fetchBatchScan()` (`scanner.ts:46-74`, non cité par l'issue, même store, même défaut) garde `scanResults` en cas d'échec (`:69-70`). Exemple : on change les filtres, on relance, l'appel échoue. `BatchScanTab` affiche alors l'erreur (`BatchScanTab.vue:444-447`) au-dessus des résultats des anciens filtres (`:458`). Même chose quand un prix de BPC enregistré relance le scan (`scanner.ts:164`, `:174`).
- **Requêtes concurrentes.** Aucune de ces quatre actions n'ignore une réponse dépassée. Les boutons « Analyser » et « Rafraîchir » sont désactivés pendant le chargement. Mais la palette de commandes change `initialTypeId` (`ProfitMarginTab.vue:352-361`, `BuyVsBuildTab.vue:59-63`, `PivotAdvisorTab.vue:72-76`) et relance une analyse pendant que la précédente tourne. Les filtres du scan restent modifiables pendant un scan. La réponse la plus lente gagne : le résultat d'un autre produit s'affiche.
- `fetchBpcKit()` est déjà remis à zéro au départ (`projects.ts:180`, `d4d7c072`) : hors de ce lot.

**Point 3 — `error` des stores blacklist et structures jamais affiché.**

- `blacklist.ts:8` : `error` est rempli par `fetchBlacklist()` (`:13-15`) et `updateBlacklist()` (`:24-26`), jamais remis à `null`, lu par personne (ni façade `stores/industry.ts`, ni composant).
  - `BlacklistConfig.vue` appelle `fetchBlacklist()` au montage (`:18-20`) et affiche « Chargement… » tant que `store.blacklist` est `null` (`:93-95`) : un échec laisse « Chargement… » indéfiniment.
  - Une modification en échec (`toggleCategory`, `removeItem`, `addItem`, `:38-67`) ne change rien à l'écran. La case revient d'elle-même à l'état du store, sans message.
- `structures.ts:21` : `error` est rempli par `fetchStructures()` (`:31-33`, sans relance), puis par `createStructure()` (`:64`), `updateStructure()` (`:81`), `deleteStructure()` (`:91`), `updateUserSettings()` (`:140`), `syncCharacterSkills()` (`:164`) et `updateCharacterSkill()` (`:184`). Toutes ces actions sauf la première relancent l'exception. `error` n'est jamais remis à `null` et n'est lu par personne. Il est partagé par `StructureConfig`, `FeesConfig`, `FavoriteSystemsConfig` et `SkillsConfig`, tous dans le même panneau de configuration.
- `StructureConfig.vue` :
  - Échec de `fetchStructures()` (`:71`) : le message « Aucune structure configurée » s'affiche (`:956-962`), ce qui est faux.
  - Échec de la sauvegarde (`saveStructure()`, `:329-364`) : seul un 409 donne un message (`saveError`, `:358-361`, paragraphe ambre `:850`). Tout autre échec tombe sur « Other errors are handled by the store » (`:362`) : le formulaire reste ouvert, sans message.
  - Échec de la suppression (`confirmDelete()`, `:388-394`) : l'exception de `deleteStructure()` n'est pas attrapée (rejet non géré). La modale reste ouverte, sans message.
  - C'est le `StructureConfig.vue:361` de l'issue.

**Déjà traité ailleurs, à ne pas refaire.**

- #200 : `FeesConfig.save()` affiche l'échec de `updateUserSettings()` (`FeesConfig.vue:30-49`, `:114-116`, clés `industry.config.feesErrors.*`).
- #197 : les personnages en échec de « Matcher les jobs » et de la sync des compétences.
- #215 : les erreurs Mercure de sync des jobs (`SyncErrorAlert.vue`).
- #194 : la liste d'achats en échec (`ShoppingTab.vue:329-345`).

Ce lot ne touche à aucun de ces chemins.

## Règles

- **R1 — L'échec d'une analyse efface son résultat.**
  - Concerne `analyzeMargin`, `fetchBuyVsBuild`, `fetchPivotAnalysis` et `fetchBatchScan`.
  - Un appel qui échoue remet le résultat à vide : `marginResult = null`, `buyVsBuildResult = null`, `pivotResult = null`, `scanResults = []`. Il range son message dans l'erreur propre à l'analyse (R3).
  - Un appel réussi remplace le résultat et laisse l'erreur à `null`.
  - Le résultat n'est **pas** effacé au départ de l'appel (D2). Pendant le chargement, les écrans gardent leur comportement actuel : `ProfitMarginTab` laisse le résultat précédent sous la ligne « Chargement » ; les trois écrans du scanner le masquent derrière leur indicateur.
- **R2 — La dernière demande gagne.**
  - Pour ces quatre analyses, chaque appel reçoit un numéro de demande. La réponse (succès ou échec) d'une demande dépassée par une plus récente est ignorée : elle ne touche ni au résultat, ni à l'erreur, ni à l'indicateur de chargement.
  - L'indicateur de chargement reste vrai jusqu'à la réponse de la dernière demande.
  - `analyzeMargin` rend `null` pour une demande dépassée. `ProfitMarginTab` en tient déjà compte (`:355`). Il ne pose `lastFetchedAt` que pour un résultat non `null` (R4).
  - Même mécanisme que `previewRequestId` (`StructureConfig.vue:488-489`).
  - Aucune annulation HTTP : `apiRequest` ne prend pas de `signal` (D4).
- **R3 — Erreur d'analyse affichée dans l'écran de l'analyse.**
  - Le store des projets reçoit `marginError` (`string | null`), remis à `null` au départ de `analyzeMargin`. `analyzeMargin` n'écrit plus dans `error` : l'échec ne s'affiche plus dans le bandeau du haut de page.
  - `ProfitMarginTab` affiche `marginError` dans la boîte rouge de `BuyVsBuildTab` (`p-4 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400`, `data-testid="margin-error"`), juste avant l'état vide. Le texte est rangé tel quel, comme dans les onglets du scanner.
  - L'état vide « Recherchez un item… » (`:504`) est masqué quand `marginError` est posé, comme dans `PivotAdvisorTab.vue:578`.
  - `buyVsBuildError`, `pivotError` et `scanError` gardent leur affichage actuel.
- **R4 — Date de mise à jour seulement après succès.**
  - `ProfitMarginTab.analyzeType()` ne pose `lastFetchedAt` que si l'analyse rend un résultat.
  - `CostEstimationTab.loadCostEstimation()` ne pose `lastFetchedAt` qu'après une estimation reçue.
- **R5 — Estimation des coûts : l'échec atteint l'onglet.**
  - `fetchCostEstimation()` relance l'exception, n'écrit plus dans `error` et remet `costEstimation` à `null`. Type de retour : `Promise<CostEstimation>`.
  - `loadCostEstimation()` attrape l'échec, pose `costData = null` et `error` (message de l'exception, sinon `industry.costEstimation.error`). L'onglet affiche sa carte d'erreur existante : titre `industry.costEstimation.error`, message, bouton `common.actions.retry` qui relance `loadCostEstimation`.
  - Le bandeau du haut de page ne s'affiche pas (`projectsStore.error` reste `null`).
  - `costData = null` assure qu'une réactivation de l'onglet recharge l'estimation (`activate()`, `:165-172`).
- **R6 — Coûts de copie : échec signalé, pas masqué.**
  - `fetchCopyCosts()` relance l'exception, n'écrit plus dans `error` et remet `copyCosts` à `null`. Type de retour : `Promise<CopyCosts>`.
  - `loadCopyCosts()` remplace son `catch {}` : `copyCostsData = null` et `copyCostsFailed = true` (remis à `false` au départ de chaque chargement).
  - Dans la zone de la section des coûts de copie, l'onglet affiche une ligne d'alerte (`role="alert"`, `data-testid="copy-costs-error"`, `text-sm text-red-400`, dans une `eve-card p-4`) au texte `industry.costEstimation.copyCostsError`. Le reste de l'estimation reste affiché.
  - Un coût de copie inconnu n'est pas « aucune copie » : règle « inconnu, jamais zéro ».
- **R7 — Blacklist : chargement et modification en échec affichés.**
  - `fetchBlacklist()` et `updateBlacklist()` relancent l'exception. Le champ `error` du store disparaît.
  - `BlacklistConfig` garde deux erreurs locales :
    - `loadFailed` : posé par l'échec du chargement au montage. « Chargement… » est remplacé par `industry.blacklist.loadError` (`role="alert"`, `data-testid="blacklist-load-error"`, `text-sm text-red-400`).
    - `saveFailed` : posé par l'échec de `toggleCategory`, `removeItem` ou `addItem`, remis à `false` au départ de chaque modification. `industry.blacklist.saveError` s'affiche au-dessus des catégories (`role="alert"`, `data-testid="blacklist-save-error"`).
  - La blacklist affichée reste celle du store, donc l'état d'avant la modification refusée.
  - `addItem` en échec garde la recherche et le menu ouverts : l'utilisateur peut réessayer.
- **R8 — Structures : chargement, sauvegarde et suppression en échec affichés.**
  - `fetchStructures()` relance l'exception. Le champ `error` du store et ses sept écritures disparaissent. Les six autres actions relancent déjà : leurs appelants ne perdent rien, le champ n'était lu nulle part.
  - Chargement : `StructureConfig` attrape l'échec de `fetchStructures()` au montage et pose `loadFailed`.
    - Liste vide : `industry.structures.loadError` (`role="alert"`, `data-testid="structures-load-error"`, `text-sm text-red-400`) remplace « Aucune structure configurée ».
    - Liste non vide (store déjà rempli par un montage précédent) : le message s'affiche au-dessus de la liste, qui reste affichée (D6).
    - `fetchCorporationStructures()` est toujours appelé.
  - Sauvegarde : un échec autre que 409 pose `saveError = t('industry.structures.saveError')`, dans le paragraphe existant (`:850`, auquel on ajoute `role="alert"` et `data-testid="structure-save-error"`). Le formulaire reste ouvert avec sa saisie. Le 409 garde `industry.structures.alreadyImported`. Le commentaire `:362` disparaît.
  - Suppression : `confirmDelete()` attrape l'échec. La modale reste ouverte et affiche `industry.structures.deleteError` (`role="alert"`, `data-testid="structure-delete-error"`, `text-sm text-red-400`) sous le texte de confirmation. La structure reste dans la liste.
    - « Supprimer » réessaie et efface le message au départ.
    - « Annuler » ferme la modale et efface le message.
    - Une suppression réussie ferme la modale comme aujourd'hui.

## Exemples chiffrés

Données fictives. Projet `project-189`. Types : Ishtar `12005`, Vexor `626`, Rifter `587`, Merlin `603`, Tristan `593`. « Rejet 503 » = `apiRequest` rejette `new ApiError('API error: 503', 503)`. Un message suivi de « (fictif) » est une `Error` au texte donné.

**Estimation des coûts** (`CostEstimationTab`, `activate()` puis bouton `industry.costEstimation.refreshPrices`)

| # | Situation | Attendu |
|---|---|---|
| C1 | 1er chargement : estimation `materialCost` 48 200 000, `jobInstallCost` 1 350 000, `totalCost` 49 550 000 ; coûts de copie : 2 copies, total 412 000 | carte « Total production cost » : `49,550,000` ; section des coûts de copie présente ; pas de `copy-costs-error` |
| C2 | C1 puis « Refresh prices » : `cost-estimation` rejet 503 | carte d'erreur : titre « Failed to load cost estimation », message `API error: 503`, bouton « Retry » ; ni `49,550,000` ni « Last updated » ; `useProjectsStore().error` = `null` |
| C3 | C2 puis « Retry » : estimation `totalCost` 51 020 000 | carte d'erreur absente ; `51,020,000` affiché |
| C4 | 1er chargement : `cost-estimation` rejette `new Error('Network down (fictif)')` | carte d'erreur, message `Network down (fictif)` ; `store.error` `null` |
| C5 | 1er chargement : estimation C1, `copy-costs` rejet 503 | `49,550,000` affiché ; `copy-costs-error` : « BPC copy costs could not be loaded. » (FR : « Les coûts de copie des BPC n'ont pas pu être chargés. ») ; pas de tableau des copies ; `store.error` `null` |
| C6 | C5 puis réactivation de l'onglet (`activate()`), `copy-costs` total 412 000 | `copy-costs-error` absent ; total `412,000` (format de `formatIskFull`) |

**Marges** (`ProfitMarginTab`, analyses lancées par `setProps({ initialTypeId })`, `GET /industry/settings` résolu)

| # | Situation | Attendu |
|---|---|---|
| M1 | Ishtar réussie, puis Vexor : rejet `Price data unavailable (fictif)` | `margin-error` : `Price data unavailable (fictif)` ; bloc résultat absent (plus rien de l'Ishtar, ni « Last updated ») ; état vide `industry.margins.selectItem` absent ; `useProjectsStore().marginResult` `null` ; `useProjectsStore().error` `null` |
| M2 | M1 puis Ishtar réussie | `margin-error` absent ; résultat de l'Ishtar affiché |
| M3 | Ishtar en attente (promesse non résolue), Vexor réussie, puis Ishtar résolue | résultat du Vexor ; `marginLoading` faux |
| M4 | Ishtar en attente, Vexor réussie, puis Ishtar rejetée | résultat du Vexor, pas de `margin-error` |
| M5 | Ishtar en attente, Vexor en attente, Ishtar résolue | `marginLoading` toujours vrai ; `marginResult` inchangé (vide ou résultat d'avant) |
| M6 | Ishtar réussie, puis Vexor en attente | pendant l'attente : résultat Ishtar et ligne « Loading » (inchangé, D2) |

**Buy vs Build et Pivot** (`BuyVsBuildTab`, `PivotAdvisorTab`, même déclenchement par `initialTypeId`)

| # | Situation | Attendu |
|---|---|---|
| B1 | Buy vs Build : Ishtar réussie, puis Vexor : rejet `Blueprint not found (fictif)` | boîte rouge `Blueprint not found (fictif)` ; bloc résultat absent ; `buyVsBuildResult` `null` |
| B2 | Buy vs Build : Ishtar en attente, Vexor réussie, Ishtar résolue | résultat du Vexor ; pas d'erreur |
| P1 | Pivot : Ishtar réussie, puis Vexor : rejet 503 | boîte rouge `API error: 503` ; bloc résultat absent ; `pivotResult` `null` |
| P2 | Pivot : Ishtar en attente, Vexor rejet 503, Ishtar résolue | boîte rouge `API error: 503` ; `pivotResult` `null` |

**Scan** (`useScannerStore().fetchBatchScan` direct, ou bouton « Scan » de `BatchScanTab`)

| # | Situation | Attendu |
|---|---|---|
| S1 | 1er scan (catégorie `all`) : Rifter, Merlin, Tristan ; 2e scan (`category: 'ships'`, `minMargin: 15`) rejette `Scan timeout (fictif)` | `scanError` `Scan timeout (fictif)` ; `scanResults` `[]` ; écran : boîte rouge, aucune ligne Rifter/Merlin/Tristan ; `lastScanAt` = date du 1er scan |
| S2 | 1er scan en attente, 2e scan résolu (Merlin seul), 1er résolu (Rifter, Merlin, Tristan) | `scanResults` = [Merlin] ; `scanLoading` faux |

**Blacklist** (`BlacklistConfig`, catégorie « Fuel blocks » `fuel_blocks`, `groupIds` [1136], non cochée)

| # | Situation | Attendu |
|---|---|---|
| L1 | `GET /industry/blacklist` rejet 503 | `blacklist-load-error` : « The blacklist could not be loaded. » (FR : « La blacklist n'a pas pu être chargée. ») ; pas de « Loading... » |
| L2 | chargement OK ; clic sur la case « Fuel blocks », `PUT` rejet 503 | `blacklist-save-error` : « The blacklist change was not saved. » ; case « Fuel blocks » décochée |
| L3 | L2 puis nouveau clic, `PUT` rend « Fuel blocks » cochée | `blacklist-save-error` absent ; case cochée |

**Structures** (`StructureConfig`, `FavoriteSystemsConfig` remplacé par un stub, `GET /industry/corporation-structures` → `{ structures: [] }`)

| # | Situation | Attendu |
|---|---|---|
| T1 | `GET /industry/structures` rejet 503, store vide | `structures-load-error` : « Your structures could not be loaded. » (FR : « Vos structures n'ont pas pu être chargées. ») ; pas de « No structures configured » ; `corporation-structures` appelé |
| T2 | liste « Raitaru Perimeter » (`struct-1`) chargée ; suppression confirmée, `DELETE /industry/structures/struct-1` rejet 500 | modale ouverte ; `structure-delete-error` : « The structure was not deleted. » ; « Raitaru Perimeter » toujours listée ; aucun rejet non géré |
| T3 | T2 puis « Annuler » | modale fermée ; message absent à la réouverture |
| T4 | T2 puis « Supprimer », `DELETE` réussi | modale fermée ; « Raitaru Perimeter » retirée |
| T5 | ajout « Azbel Kador » (nom saisi), `POST /industry/structures` rejet 500 | formulaire ouvert, nom conservé ; `structure-save-error` : « The structure was not saved. » (FR : « La structure n'a pas été enregistrée. ») |
| T6 | ajout d'une structure importée déjà présente, `POST` rejette `ApiError('Conflict', 409)` | `industry.structures.alreadyImported` avec le nom existant (inchangé) |

## Clés i18n

| Clé | EN | FR |
|---|---|---|
| `industry.costEstimation.copyCostsError` | `BPC copy costs could not be loaded.` | `Les coûts de copie des BPC n'ont pas pu être chargés.` |
| `industry.blacklist.loadError` | `The blacklist could not be loaded.` | `La blacklist n'a pas pu être chargée.` |
| `industry.blacklist.saveError` | `The blacklist change was not saved.` | `La modification de la blacklist n'a pas été enregistrée.` |
| `industry.structures.loadError` | `Your structures could not be loaded.` | `Vos structures n'ont pas pu être chargées.` |
| `industry.structures.saveError` | `The structure was not saved.` | `La structure n'a pas été enregistrée.` |
| `industry.structures.deleteError` | `The structure was not deleted.` | `La structure n'a pas été supprimée.` |

Réutilisées sans changement : `industry.costEstimation.error`, `common.actions.retry`, `industry.margins.selectItem`, `industry.structures.alreadyImported`, `industry.structures.noStructures`. Aucune clé pour l'erreur des marges : texte brut, comme Buy vs Build et Pivot (R3).

## Cas limites

- **Annulation.** Pas d'annulation HTTP. Une réponse dépassée arrive quand même et est ignorée (R2). Un onglet démonté pendant un appel : le store reçoit la réponse et la garde. Si c'est la dernière demande, l'onglet la retrouve au retour, comme aujourd'hui.
- **Deux analyses, la première en échec après la seconde.** Ignorée (M4) : pas d'erreur affichée pour un produit qui n'est plus demandé.
- **Rechargement de l'estimation ou de la blacklist en concurrence.** Non protégés par R2. Le bouton de rafraîchissement de l'estimation est caché pendant le chargement (`:180`). Deux clics rapides sur deux cases de la blacklist envoient deux `PUT`, chacun calculé sur l'état du store au clic : le second peut annuler le premier. Défaut existant, non traité ici (Hors périmètre).
- **Message brut en français ou en anglais.** `apiRequest` produit des textes français en dur (401, 420/429, `services/api.ts:184-203`) et `API error: <statut>` sinon. R3 et R5 les affichent tels quels, comme aujourd'hui dans le bandeau du haut de page. R6, R7 et R8 affichent une phrase traduite sans le texte brut, comme `FeesConfig` (#200 R9).
- **Échec de l'estimation puis réactivation de l'onglet.** `costData` est `null`, donc `activate()` recharge. La carte d'erreur disparaît pendant le chargement (`v-if="loading"` passe avant).
- **Scan en échec puis retour sur l'onglet.** `scanResults` est vide : `BatchScanTab` relance un scan au montage (`:308-310`). C'est voulu : les anciens résultats ne correspondaient plus à la demande.
- **Prix de BPC enregistré, rescan en échec.** Le prix est enregistré (`PUT` réussi) ; la liste se vide et l'erreur du scan s'affiche (R1). Le prix saisi reste dans `bpcPrices`.
- **Structures déjà en mémoire et chargement en échec.** La liste d'un montage précédent reste affichée sous le message (R8, D6).
- **Échec de suppression puis fermeture du panneau de configuration.** La modale disparaît avec le composant ; rien n'est supprimé.

## Hors périmètre

- Lectures annexes avalées et écritures non attrapées hors `StructureConfig` / `BlacklistConfig` : voir « Blocage : options ».
- `fetchShoppingList`, `fetchBpcKit`, `matchJobs` et les actions CRUD des projets : ils gardent leur passage par `projectsStore.error`. #194 et `d4d7c072` couvrent déjà la liste d'achats et le kit BPC.
- `clearBuyVsBuild()` et `clearPivot()` (`scanner.ts:104-107`, `:133-136`) : appelés nulle part (code mort). Non retirés ici.
- Champs `costEstimation` et `copyCosts` du store des projets : lus par aucun composant. Non retirés ici, seulement remis à `null` en cas d'échec (R5, R6).
- « Rafraîchir les prix » de l'estimation ne recharge pas les coûts de copie. Comportement inchangé.
- Protection de la blacklist contre deux modifications concurrentes.
- Textes de repli anglais en dur des stores (`'Analysis failed'`, `'Scan failed'`, `'Failed to analyze profit margin'`) : inchangés.

## Blocage : options (lectures annexes, à trancher par Sylvain)

**Constat.** Plusieurs lectures et écritures avalent encore leur échec. Ce sont celles de la dernière ligne de preuve de l'issue, plus leurs voisines du même schéma :

| Action | Écran touché | Effet d'un échec aujourd'hui |
|---|---|---|
| `searchProducts` (`projects.ts:34-36`) | `ProductSearch` (marges, Buy vs Build, Pivot, création de projet), `NewProjectModal` | liste vide, comme « aucun résultat » |
| `fetchBpcPrices` (`scanner.ts:143-145`), `fetchFavorites` (`:182-184`) | `BatchScanTab` | aucun prix de BPC ni favori, comme s'il n'y en avait pas |
| `fetchUserSettings` (`structures.ts:127-129`) | `FeesConfig`, `FavoriteSystemsConfig`, `ProfitMarginTab` | `FeesConfig` affiche ses valeurs initiales (3,6 % / 3,6 % / 1200, `FeesConfig.vue:11-13`) comme si c'étaient les réglages ; un « Enregistrer » les écrase |
| `fetchCorporationStructures` (`structures.ts:42-44`), `fetchCharacterSkills` (`:150-152`) | `StructureConfig`, `SkillsConfig` | liste vide |
| `searchBlacklistItems` (`blacklist.ts:36-38`) | `BlacklistConfig` | « aucun résultat » |
| `updateUserSettings` depuis `FavoriteSystemsConfig` (`:77-100`) | systèmes favoris | rejet non géré, aucun message |
| `syncAll` et `updateCharacterSkill` de `SkillsConfig` (`:18-28`, `:38`) | compétences | `catch` vide (« error handled by store ») ou rejet non géré |

**Option A — Tout traiter dans ce lot.** Une alerte par écran, au modèle de R7/R8, et pour `FeesConfig` un blocage de la sauvegarde tant que les réglages n'ont pas été lus. Cela fait 7 composants de plus, autant de jeux de tests et environ 8 clés. Le lot double et mélange deux sujets : résultats d'analyse et configuration.

**Option B — Issue séparée « Lectures annexes industrie avalées ».** On la découpe par priorité :
1. `FeesConfig` : sauvegarde possible sur des valeurs non lues, donc risque d'écraser les vrais réglages.
2. `FavoriteSystemsConfig` et `SkillsConfig` : rejets non gérés.
3. Recherches et listes de confort.

**Recommandation : option B.** Le point 1 de B est le seul risque de perte de données. Il mérite sa propre règle (formulaire désactivé ou valeurs « inconnues » tant que la lecture n'a pas réussi), qui sort du sujet de cette issue. Ce lot reste centré sur le test de l'issue : une analyse réussie, puis une analyse en échec, donc l'erreur à l'écran et plus l'ancien résultat.

## Décisions retenues en autonomie

- **D1 — Erreur des marges dans l'onglet, plus dans le bandeau du haut de page (R3).** Les trois autres analyses (Buy vs Build, Pivot, Scan) affichent déjà la leur dans l'onglet, avec la même boîte rouge. Le bandeau du haut de page est partagé avec la liste des projets et reste affiché après un changement d'onglet.
- **D2 — Résultat effacé à l'échec, pas au départ (R1).** `ProfitMarginTab` affiche volontairement le résultat précédent avec une ligne « Chargement » pendant un rafraîchissement (`:523`). L'effacer au départ supprimerait ce comportement sans gain : les trois écrans du scanner masquent déjà le résultat pendant le chargement. L'issue demande que l'ancien résultat ne survive pas à un échec ; R1 le garantit.
- **D3 — Le scan en lot entre dans le lot.** Il n'est pas cité par l'issue, mais il est dans le même store et a le même défaut (anciens filtres affichés sous une erreur). Le coût est une ligne et deux exemples.
- **D4 — Dernière demande gagne, sans annulation HTTP (R2).** Un numéro de demande par analyse suffit, avec un précédent dans le code (`previewRequestId`). Une annulation par `AbortController` toucherait `apiRequest`, partagé par toute l'application.
- **D5 — Les stores relancent, les composants affichent (R5 à R8).** C'est le schéma de #200 (`FeesConfig`) et de #197. Un `error` de store partagé par quatre composants du même panneau ne peut pas être affiché par l'un d'eux sans montrer les erreurs des autres. Le champ disparaît donc des stores `structures` et `blacklist`.
- **D6 — Structures déjà chargées gardées sous une erreur de rechargement (R8).** Ce sont des réglages de l'utilisateur, modifiés par ce seul écran. La liste en mémoire est celle qu'il a vue ou enregistrée en dernier. Une liste vidée suggérerait une perte de configuration.
- **D7 — Phrases traduites sans texte brut pour la configuration (R6 à R8), texte brut pour les analyses (R3, R5).** Chaque écran suit son voisin le plus proche : `FeesConfig` (#200) pour le panneau de configuration ; Buy vs Build, Pivot et la carte d'erreur existante de l'estimation pour les analyses.
- **D8 — Pas de maquette.** Aucun composant nouveau : boîte rouge des onglets du scanner, carte d'erreur existante de l'estimation, ligne d'alerte de `FeesConfig`.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `frontend/src/stores/industry/projects.ts` | `fetchCostEstimation` et `fetchCopyCosts` relancent, n'écrivent plus `error`, remettent leur champ à `null` en échec (R5, R6) ; `analyzeMargin` : numéro de demande, `marginError` remis à `null` au départ, `marginResult = null` en échec, plus d'écriture dans `error` (R1 à R3) ; export de `marginError` |
| `frontend/src/stores/industry.ts` | getter `marginError` dans la façade |
| `frontend/src/stores/industry/scanner.ts` | `fetchBuyVsBuild`, `fetchPivotAnalysis`, `fetchBatchScan` : numéro de demande, résultat vidé en échec (R1, R2) |
| `frontend/src/stores/industry/blacklist.ts` | `fetchBlacklist`, `updateBlacklist` relancent ; retrait de `error` (R7) |
| `frontend/src/stores/industry/structures.ts` | `fetchStructures` relance ; retrait de `error` et de ses écritures (R8) |
| `frontend/src/components/industry/CostEstimationTab.vue` | `loadCostEstimation` : `costData = null` et `lastFetchedAt` seulement en succès (R4, R5) ; `loadCopyCosts` : `copyCostsFailed` et alerte `copy-costs-error` (R6) |
| `frontend/src/components/industry/ProfitMarginTab.vue` | boîte `margin-error`, état vide masqué en erreur (R3) ; `lastFetchedAt` seulement pour un résultat (R4) |
| `frontend/src/components/industry/BlacklistConfig.vue` | `loadFailed`, `saveFailed`, alertes `blacklist-load-error` et `blacklist-save-error` (R7) |
| `frontend/src/components/industry/StructureConfig.vue` | `loadFailed` et alerte `structures-load-error` ; `saveError` pour tout échec non 409, `role="alert"` et `data-testid="structure-save-error"` ; erreur dans la modale de suppression `structure-delete-error` ; retrait du commentaire `:362` (R8) |
| `frontend/src/i18n/locales/fr.json`, `en.json` | les six clés ci-dessus |

`BuyVsBuildTab.vue`, `PivotAdvisorTab.vue` et `BatchScanTab.vue` ne changent pas : leur affichage suit le store. Aucun fichier backend, aucune migration, aucune commande à lancer en production.

## Câblage des tests (Vitest)

**Tests existants appelés à casser** : aucun attendu.

- Les specs de `ProfitMarginTab`, `BuyVsBuildTab`, `PivotAdvisorTab` et `BatchScanTab` posent le résultat directement dans le store (`useProjectsStore().marginResult = …`, `useScannerStore().buyVsBuildResult = …`), sans `initialTypeId` ni appel en échec.
- `costEstimationTab*.spec.ts` ne mockent que des succès.
- `structuresSyncCharacterSkillsFailedCharacters.spec.ts:48` attend un rejet de `syncCharacterSkills`, qui ne change pas.
- `grep -rn "\.error" frontend/tests` ne trouve aucune lecture de `error` sur ces stores.
- `tests/a11y/iconButtonsHaveName.spec.ts:342-345` monte `StructureConfig` avec des succès : les nouvelles alertes n'apparaissent pas.

**Tests rouges attendus** (`describe` contenant `issue 189`). `apiRequest` est mocké par URL (`vi.mock(import('@/services/api'), …)`, comme `projectsBpcKitOfAnotherProject.spec.ts`). Les demandes en attente utilisent des promesses contrôlées (`let resolve; new Promise(r => { resolve = r })`).

- `frontend/tests/stores/projectsAnalyzeMarginFailure.spec.ts` : M1 (store), M3, M4, M5.
- `frontend/tests/stores/scannerAnalysesFailure.spec.ts` : B1, B2, P1, P2, S1, S2 côté store.
- `frontend/tests/components/industry/profitMarginTabFailure.spec.ts` : M1, M2, M6. Harnais de `profitMarginTabScrollContainer.spec.ts`, analyses par `setProps({ initialTypeId })` et `flushPromises()`. C'est le test de l'issue.
- `frontend/tests/components/industry/buyVsBuildFailure.spec.ts` et `pivotAdvisorFailure.spec.ts` : B1 et P1 à l'écran.
- `frontend/tests/components/industry/batchScanTabFailure.spec.ts` : S1 à l'écran. Harnais de `batchScanTabUnknownTransportCost.spec.ts`, clic sur le bouton « Scan ».
- `frontend/tests/components/industry/costEstimationTabFailure.spec.ts` : C1 à C6. Harnais de `costEstimationTabSellPricePerUnit.spec.ts` (`activate()` exposé) ; C5 en FR aussi.
- `frontend/tests/components/industry/blacklistConfigFailure.spec.ts` : L1 à L3 (EN, plus L1 en FR).
- `frontend/tests/components/industry/structureConfigFailure.spec.ts` : T1 à T6. Montage de `iconButtonsHaveName.spec.ts:342-345` (`teleport` et `FavoriteSystemsConfig` remplacés). T2 : `process.on('unhandledRejection')` ou `vi.fn` pour vérifier l'absence de rejet non géré. T1 et T5 en FR aussi.

## Glossaire

Aucun terme nouveau. « Inconnu, jamais zéro » (CLAUDE.md, glossaire « Coût inconnu ») justifie R6.
