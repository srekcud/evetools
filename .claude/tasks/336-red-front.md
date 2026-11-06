# #336 front, phase rouge

## Tests nouveaux (15)
- tests/stores/groupProjectCockpit.spec.ts (2) : fetchCockpit remplit `cockpit` depuis `/industry/projects/{id}/cockpit` ; en erreur `cockpit` null, `cockpitError` posé, `error` intact.
- groupIndustryDetailCockpitRail.spec.ts (3) : rail (ordre, états, compteurs, raisons FR), cockpit en erreur (ni rail ni carte, page utilisable), noms longs FR et EN.
- groupIndustryDetailNextAction.spec.ts (4) : titres par code et marqueur ambre et bouton, statut cible (confirmation pour completed), onglet cible, modale préremplie.
- groupIndustryDetailTabs.spec.ts (2) : cinq onglets actifs (Équipe pour projet solo) + quatre « À venir » désactivés + défaut bom ; `?tab=distribution` Finances, `?tab=sales` Ventes + suggestions.
- groupIndustryDetailCockpitRefresh.spec.ts (1) : Mercure (sale_recorded recharge, member_joined non, progression industry-project oui).
- projectCockpitRoutes.spec.ts (1) : route `industry-project` et redirection `/group-industry/:id?tab=sales` avec params et query.
- groupIndustryJoinRoute.spec.ts (1) : Join remplace vers `industry-project`.
- MainLayoutNavigation.spec.ts : un cas ajouté à l'it.each (`/industry/projects/p1`) : Industrie active et titre. Comportement absent aujourd'hui (le préfixe actif est `/group-industry` seulement).

## Helper
tests/views/GroupIndustry/projectCockpitFixtures.ts : projet, BOM, cockpit, réponses API par chemin, montage de la vue.

## Contrat DOM à respecter en vert
- Rail : `data-testid="cockpit-rail"` ; cellules `data-testid="cockpit-phase-{prepare|supply|produce|sell|close}"` dans cet ordre, attribut `data-state="done|current|blocked|upcoming"` ; texte de la cellule : libellé (FR : Préparer, Approvisionner, Produire, Vendre, Clôturer), compteur `done / total` (espaces simples), raison traduite pour `blocked` (FR `3 matériaux à couvrir`). Aucun autre élément ne porte un testid commençant par `cockpit-phase-`.
- Carte : `data-testid="next-action-card"` avec `data-blocked="true|false"` ; titre `next-action-title` ; bouton `next-action-button` (absent pour nothing_to_do). Le texte de la carte contient nom du type et quantité (source_material : « Morphite », « 340 » ; record_sale : « 40 » ; review_contributions : « 2 »). Aucune clé brute (`groupIndustry.`) dans les titres ; cinq titres distincts en FR et en EN.
- Onglets : `data-testid="project-tab-{bom|contributions|distribution|sales|members|chain|invention|planning|journal}"` dans cet ordre, boutons `disabled` pour chain, invention, planning, journal avec le texte `À venir` en FR. `?tab=chain` retombe sur bom. `distribution` = Finances, `sales` = Ventes.
- Store : `cockpit`, `cockpitError`, `fetchCockpit(projectId)` ; `fetchCockpit` ne touche pas `error`.
- Vue : `fetchCockpit` appelé au montage (avec détail et BOM) et à chaque rafraîchissement `cockpit` ; la clé `cockpit` s'ajoute à `GroupProjectRefresh` (toutes les actions sauf member_*).
- Routes : `/industry/projects/:id` (`industry-project`) ; `/group-industry/:id` redirige en gardant params et query ; List et Join poussent `industry-project`. Sidebar : préfixe actif `/industry/projects` pour l'entrée Industrie (titre « Industry »).

## Specs existantes adaptées
- groupProjectRefreshes.spec.ts : clé `cockpit` (8 tests).
- groupIndustryListJoin.spec.ts : `industry-project`.
- groupIndustryDetailSalesFetch.spec.ts : onglet `sales` (ex-distribution), onglet trouvé par testid.
- groupIndustryDetailSaleSuggestions.spec.ts : `?tab=sales`, helper renommé `mountSalesTabOf`.
- groupIndustryDetailPrivacyLifecycle, UnknownSellerFees, LinkedJobCompletion, SalesFetch, SaleSuggestions et tests/a11y/iconButtonsHaveName.spec.ts : spy de `fetchCockpit` ajouté à côté de ceux de `fetchProjectDetail` et `fetchBom` (sinon la réponse `{}` des mocks deviendrait un faux cockpit en vert).
- tests/layouts/mainLayoutHarness.ts : route `/industry/projects/p1`.

## Non touché, à décider
- useCommandPaletteProjects.ts pousse `/group-industry/{id}` (la redirection le couvre) ; CommandPaletteProjects.spec.ts l'asserte : inchangé.
- sidebarNavigation.ts : `activePathPrefixes` doit recevoir `/industry/projects`.

## Squelettes (src/)
- types.ts : types ProjectCockpit, ProjectPhase, ProjectNextAction et unions associées.
- stores/group-industry/project.ts : `cockpit`, `cockpitError`, `fetchCockpit` vide.

## Rouge
Suite complète : 33 échecs sur 12 fichiers, tous attendus (assertions), 1635 verts. Un seul échec par exception : SaleSuggestions « shows a refused recording... » (`recordAsSaleButtons(wrapper)[0]` indéfini car l'onglet sales n'existe pas encore), pré-existant dans la forme du test.
Lint : 0 erreur (159 warnings préexistants). Build (vue-tsc) : OK.
