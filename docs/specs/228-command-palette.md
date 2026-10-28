# Spec #228 — Palette de commandes Ctrl+K : navigation, types et projets

## Contexte

Atteindre un écran ou un objet précis demande plusieurs clics. La sidebar a un groupe repliable (« Production », `MainLayout.vue:123-140`), Industry a 8 onglets en 3 groupes (`Industry.vue:105-129`), et chaque onglet d'analyse a sa propre recherche de produit. Aucun raccourci clavier global n'existe ; les seuls écouteurs `keydown` globaux sont `/` dans la recherche Market (`MarketSearch.vue:66`) et `Escape` dans les escalations (`EscalationsTab.vue:305`, sur `document`).

Ce qui existe et que la palette réutilise, sans nouvel endpoint ni nouvelle dépendance :

- **Écrans** : les entrées de la sidebar (`allNavEntries`, `MainLayout.vue:123`), le lien Personnages du menu utilisateur (`/characters`) et l'entrée Admin (`adminStore.checkAccess()`). Routes : `router/index.ts`.
- **Onglets lus depuis l'URL au montage** : Industry (`?tab=`, 8 valeurs, `Industry.vue:82`), Revenus (`Ledger.vue:23`), Inventaire (`Assets.vue:29`), Market (`MarketBrowser.vue:31`). La lecture n'a lieu qu'au montage : une navigation vers le même écran avec un autre `?tab=` ne change pas l'onglet. C'est l'objet de #221.
- **Modules masqués** : `hiddenModules`, ref locale de `MainLayout.vue:160`, persistée dans `localStorage['evetools_hidden_modules']` (tableau d'identifiants de modules) ; valeur illisible : aucun module masqué.
- **Types** : `GET /api/industry/search?q=` (`ProductSearchProvider`) : produits publiés fabricables ou réactionnables, 20 au plus, triés par nom, `{typeId, typeName, isT2}`, liste vide sous 2 caractères. `GET /api/market/search` existe aussi, mais charge pour chaque type les prix Jita, les volumes et la variation à 30 jours.
- **Noms de types** : le SDE n'importe que le nom anglais (`SdeImportTrait.php:60`, `name['en']`). Il n'y a pas de nom de type français.
- **Projets** : `GET /api/industry/projects` (`useProjectsStore().fetchProjects()`) et `GET /api/group-industry/projects` (`fetchMyProjects()`, la liste « Mes projets »). Un projet Group Industry a une route (`/group-industry/:id`) ; un projet Industry n'en a pas : son détail est un état local (`viewingProjectId`, `Industry.vue:78`).
- **Présélection d'un type dans un onglet d'analyse** : uniquement par `store.navigationIntent`, observé par un `watch` non immédiat dans `Industry.vue:144`. Un intent posé avant le montage d'Industry n'est jamais lu : la palette ne peut pas s'en servir depuis un autre écran.

## Règles

### Ouverture et fermeture

- **R1 — Raccourci.** `Ctrl+K` ou `Cmd+K` (`metaKey`), sans `Shift` ni `Alt`, ouvre la palette sur tout écran rendu dans `MainLayout`. L'action par défaut du navigateur est annulée. Le raccourci fonctionne aussi quand le focus est dans un champ de formulaire ; la touche `k` seule n'ouvre jamais rien.
- **R2 — Bouton.** Un bouton du header ouvre la palette ; son libellé accessible porte le raccourci.
- **R3 — État à l'ouverture.** La saisie est vide et prend le focus ; la première ligne est active.
- **R4 — Fermeture.** `Escape`, un clic hors de la palette ou `Ctrl+K`/`Cmd+K` quand elle est ouverte la ferment, et rendent le focus à l'élément qui l'avait avant l'ouverture (le bouton du header s'il l'a ouverte). L'événement `Escape` ne se propage pas : les écouteurs `Escape` de l'écran (modales des escalations) ne le reçoivent pas.
- **R5 — Au-dessus de tout.** La palette s'ouvre aussi quand une modale de l'écran est ouverte, et s'affiche au-dessus. La fermer laisse la modale intacte.

### Saisie et correspondance

- **R6 — Normalisation.** La saisie et les libellés sont comparés en minuscules, sans accents, espaces de bord retirés et espaces internes réduits à un seul.
- **R7 — Correspondance.** Une entrée correspond si chaque mot de la saisie est une sous-chaîne de son libellé complet. Le libellé complet d'un écran est son nom (« Industry ») ; celui d'un onglet est `Écran › Onglet` (« Industry › Buy vs Build »). Le groupe de sidebar « Production » n'en fait pas partie.
- **R8 — Libellés dans la langue de l'interface, alias anglais.** Écrans et onglets sont affichés dans la langue i18n courante. En français, une entrée correspond aussi sur son libellé anglais (« buy » trouve « Industrie › Achat vs Prod ») ; l'affichage reste en français.
- **R9 — Noms de types en anglais.** Les types sont cherchés et affichés sous leur nom SDE anglais, dans les deux langues.

### Groupes et ordre

- **R10 — Trois groupes, ordre fixe.** Navigation, puis Projets, puis Types. Un groupe sans ligne n'est pas affiché (sauf R19). Les types arrivent après le réseau et s'ajoutent en bas, sans déplacer la ligne active.
- **R11 — Classement dans Navigation.** Rang 0 : libellé complet qui commence par la saisie ; rang 1 : un mot du libellé complet commence par le premier mot de la saisie ; rang 2 : le reste. À rang égal, ordre de déclaration (ordre de la sidebar, chaque écran suivi de ses onglets). En français, le rang se calcule sur le libellé français, puis sur l'alias anglais si le français ne correspond pas.
- **R12 — Limites.** Navigation : 10 lignes au plus ; Projets : 5 ; Types : 8 (dans l'ordre de l'API).
- **R13 — Saisie vide.** Seuls les écrans (sans onglets) sont proposés, dans l'ordre de la sidebar ; ni projet, ni type.

### Entrées de navigation

- **R14 — Catalogue.** Chaque écran visible, plus un onglet par valeur de `?tab=` hors onglet par défaut (l'écran lui-même mène à l'onglet par défaut) :

| Module | Écran (route) | Onglets (`?tab=`) |
|---|---|---|
| `dashboard` | Dashboard (`/dashboard`) | — |
| `ledger` | Revenue (`/ledger`) | PVE (`pve`), Mining (`mining`), Escalations (`escalations`), Settings (`settings`) |
| `industry` | Industry (`/industry`) | Projects (`projects`), Margins (`margins`), Batch Scan (`batch`), Buy vs Build (`buy-vs-build`), Pivot (`pivot`), Slots (`slots`), Stockpile (`stockpile`) |
| `group-industry` | Group Industry (`/group-industry`) | Available (`available`, lu une fois #221 livrée) |
| `appraisal` | Appraisal (`/appraisal`) | — |
| `planetary` | PI (`/planetary`) | — |
| `market` | Market (`/market`) | Alerts (`alerts`) |
| `assets` | Inventory (`/assets`) | Contracts (`contracts`) |
| — | Characters (`/characters`) | — |
| — | Admin (`/admin`), administrateur seulement | — |

  Libellés : clés i18n existantes (`nav.*`, `industry.tabs.*`, `ledger.tabs.*`, `market.tabs.*`, `assets.tabs.*`, `groupIndustry.tabs.*`). L'onglet Market `detail` n'est pas une entrée : il exige un type.
- **R15 — Modules masqués.** Un module masqué retire son écran et ses onglets, lu dans `localStorage['evetools_hidden_modules']` à chaque ouverture (un changement dans les paramètres est pris en compte à l'ouverture suivante). Characters et Admin ne sont pas masquables. Tant que l'accès admin n'est pas connu, Admin n'est pas proposé.
- **R16 — Exécution.** `Entrée` ou un clic sur une entrée de navigation ferme la palette et navigue vers sa route (`router.push`). Le focus n'est pas restauré (l'écran change).

### Projets

- **R17 — Source.** Les projets Industry de l'utilisateur, puis ses projets Group Industry (« Mes projets »), chacun dans l'ordre de son API ; les projets terminés (`completed`) viennent après les autres, avec un badge « Terminé ». Les deux listes sont chargées à la première ouverture de la palette sur l'écran courant, et relues à chaque ouverture suivante.
- **R18 — Libellé.** Projet Industry : `projectDisplayName()` (`useTypeNames`), qui affiche « Nom inconnu (#typeId) » pour un type sans nom. Projet Group Industry : son nom, sinon `<runs>x <type>` pour un seul item, sinon `<n> items` (règle de `ProjectCard.vue:29`). Un badge indique l'origine (Industry, Group Industry). La correspondance (R7) porte sur ce libellé.
- **R19 — Projets inconnus.** Pendant le chargement, le groupe affiche « Chargement… ». Si une des deux lectures échoue, le groupe affiche « Projets indisponibles » (non sélectionnable) pour cette source, jamais « aucun projet » : une liste illisible n'est pas une liste vide.
- **R20 — Exécution.** Projet Group Industry : `/group-industry/<id>`. Projet Industry : `/industry?tab=projects&project=<id>`, qui ouvre son détail (lecture de `project` à ajouter dans `Industry.vue`, voir Dépendances).
- **R21 — Modules masqués.** `industry` masqué retire les projets Industry ; `group-industry` masqué retire les projets Group Industry.

### Types

- **R22 — Recherche.** À partir de 2 caractères, la saisie est envoyée à `GET /api/industry/search?q=` 250 ms après la dernière frappe. Seule la réponse de la dernière saisie est affichée ; une réponse arrivée pour une saisie dépassée est ignorée. Sous 2 caractères, aucun appel.
- **R23 — Actions.** Une ligne par type, avec trois actions :
  - `Entrée` ou clic sur la ligne : Analyser la marge → `/industry?tab=margins&typeId=<id>` ;
  - `Shift+Entrée` : Buy vs Build → `/industry?tab=buy-vs-build&typeId=<id>` ;
  - `Ctrl+Entrée` / `Cmd+Entrée` : Ouvrir dans Market → `/market?tab=detail&typeId=<id>`.

  Sur la ligne active, les trois actions sont affichées avec leur touche et cliquables.
- **R24 — Types inconnus.** Pendant la requête : « Recherche… ». Si elle échoue : « Recherche de types indisponible », distinct de l'absence de type.
- **R25 — Modules masqués.** `industry` masqué retire les deux actions Industry ; `market` masqué retire l'action Market ; sans action restante, le groupe Types n'est pas affiché et l'API n'est pas appelée. Si seule l'action Market reste, elle passe sur `Entrée`.

### Clavier et accessibilité

- **R26 — Flèches.** `ArrowDown` / `ArrowUp` déplacent la ligne active à travers les groupes, en boucle (dernière → première, première → dernière). Le survol à la souris rend une ligne active.
- **R27 — Ligne active.** Toute modification de la saisie remet la première ligne active ; l'arrivée des types ne la change pas (R10).
- **R28 — Entrée sans ligne.** `Entrée` sans aucun résultat ne fait rien ; la palette reste ouverte.
- **R29 — Focus piégé.** Tant que la palette est ouverte, `Tab` et `Shift+Tab` gardent le focus dans la saisie.
- **R30 — ARIA.** Conteneur `role="dialog"` et `aria-modal="true"` ; saisie `role="combobox"`, `aria-expanded`, `aria-controls` vers la liste, `aria-activedescendant` vers la ligne active ; liste `role="listbox"`, chaque groupe `role="group"` étiqueté, chaque ligne `role="option"` avec `aria-selected`.
- **R31 — Aucun résultat.** Saisie sans correspondance, projets lus et types reçus vides : « Aucun résultat pour « <saisie> » ».

## Exemples chiffrés

Fixtures communes : locale `en` sauf mention ; utilisateur non admin ; aucun module masqué ; projets Industry `P1 {id: 'p1', displayName: 'Raven batch', status: 'active'}`, `P2 {id: 'p2', displayName: null, rootProducts: [], productTypeName: null, productTypeId: 99000001, status: 'completed'}` ; projet Group Industry `G1 {id: 'g1', name: 'Corp Titan', status: 'in_progress'}` ; `/industry/search` simulé : `rav` → `[{typeId: 638, typeName: 'Raven'}, {typeId: 17636, typeName: 'Raven Navy Issue'}]`, toute autre saisie → `[]`.

### Correspondance et ordre

| # | Contexte | Saisie | Navigation attendue (dans l'ordre) | Projets | Types |
|---|---|---|---|---|---|
| E1 | | (vide) | Dashboard, Revenue, Industry, Group Industry, Appraisal, PI, Market, Inventory, Characters | — | — (aucun appel) |
| E2 | admin | (vide) | E1 + Admin (10 lignes) | — | — |
| E3 | | `buy` | Industry › Buy vs Build | — | — |
| E4 | | `  BUY  ` | Industry › Buy vs Build | — | — |
| E5 | | `ind buy` | Industry › Buy vs Build | — | — |
| E6 | | `ind` | Industry, Industry › Projects, Industry › Margins, Industry › Batch Scan, Industry › Buy vs Build, Industry › Pivot, Industry › Slots, Industry › Stockpile, Group Industry, Group Industry › Available | — | — |
| E7 | locale `fr` | `buy` | Industrie › Achat vs Prod | — | — |
| E8 | locale `fr` | `marché` | Marche, Marche › Alertes | — | — |
| E9 | locale `fr` | `contracts` | Inventaire › Contrats | — | — |
| E10 | `industry` masqué | `buy` | — | — | — ; « Aucun résultat pour « buy » » |
| E11 | `industry` masqué | `raven` | — | — (P1 retiré) | — (réponse `[]` ; l'API reste appelée pour l'action Market) |
| E12 | | `raven` | — | Raven batch (Industry) | — (réponse `[]`) |
| E13 | | `rav` | — | Raven batch (Industry) | Raven, Raven Navy Issue |
| E14 | | `titan` | — | Corp Titan (Group Industry) | — |
| E15 | | `unknown` | — | Unknown name (#99000001) (Industry, Terminé) | — |
| E16 | | `a` | toutes les entrées contenant « a », rang puis ordre | P1, G1 (5 au plus) | — (aucun appel, 1 caractère) |
| E17 | | `admin` | — (non admin) | — | — |
| E18 | | `zzz` | — | — | — ; « Aucun résultat pour « zzz » » |

### Exécution

| # | Saisie, ligne active | Touche | Attendu |
|---|---|---|---|
| E19 | `buy`, Industry › Buy vs Build | `Entrée` | `router.push('/industry?tab=buy-vs-build')`, palette fermée |
| E20 | `rav`, Raven | `Entrée` / `Shift+Entrée` / `Ctrl+Entrée` | `/industry?tab=margins&typeId=638` / `/industry?tab=buy-vs-build&typeId=638` / `/market?tab=detail&typeId=638` |
| E21 | `industry` masqué, `rav`, Raven | `Entrée` | `/market?tab=detail&typeId=638` |
| E22 | `raven`, Raven batch | `Entrée` | `/industry?tab=projects&project=p1` |
| E23 | `titan`, Corp Titan | `Entrée` | `/group-industry/g1` |
| E24 | `zzz` | `Entrée` | aucune navigation, palette ouverte |

### Clavier, raccourci et focus

| # | État de départ | Action | Attendu |
|---|---|---|---|
| E25 | fermée, focus sur `body` | `keydown` `Ctrl+K` | ouverte, focus dans la saisie, `defaultPrevented` |
| E26 | fermée | `Cmd+K` (`metaKey`) | ouverte |
| E27 | fermée, focus dans un `<input>` contenant `abc` | `Ctrl+K` | ouverte ; l'`<input>` contient toujours `abc` |
| E28 | fermée, focus dans un `<input>` | `k` | fermée ; `k` saisi dans l'`<input>` |
| E29 | fermée | `Ctrl+Shift+K` | fermée |
| E30 | ouverte, saisie `ind`, ligne 1 active | `ArrowDown` ×2 | ligne 3 (Industry › Margins) active, `aria-activedescendant` à jour |
| E31 | ouverte, saisie `ind`, ligne 1 active | `ArrowUp` | ligne 10 (Group Industry › Available) active |
| E32 | ouverte, saisie `ind`, ligne 10 active | `ArrowDown` | ligne 1 active |
| E33 | ouverte, saisie `ind`, ligne 3 active | saisie `indu` | ligne 1 active |
| E34 | ouverte, saisie `rav`, ligne 1 (Raven batch) active | réponse des types reçue | Raven batch toujours active |
| E35 | ouverte depuis un `<input>` focalisé | `Escape` | fermée, focus rendu à l'`<input>` ; un écouteur `keydown` sur `document` ne reçoit pas `Escape` |
| E36 | ouverte par le bouton du header | `Escape` | fermée, focus sur le bouton |
| E37 | ouverte | `Ctrl+K` | fermée, focus restauré |
| E38 | ouverte, saisie `buy` | clic hors palette | fermée |
| E39 | fermée après saisie `buy` | `Ctrl+K` | ouverte, saisie vide, ligne 1 active |
| E40 | ouverte | `Tab` | focus toujours dans la saisie |

### Requêtes et données inconnues

| # | Contexte | Saisie | Attendu |
|---|---|---|---|
| E41 | horloge simulée | `r`, `ra`, `rav` à 100 ms d'écart | un seul appel `/industry/search?q=rav`, 250 ms après la dernière frappe |
| E42 | réponse de `ra` retardée, arrivée après celle de `rav` | `ra` puis `rav` | seuls les types de `rav` affichés |
| E43 | `/industry/projects` en erreur | `raven` | groupe Projets : « Projets indisponibles » pour Industry ; pas « aucun projet » |
| E44 | `/group-industry/projects` en erreur | `titan` | « Projets indisponibles » pour Group Industry ; P1/P2 filtrés normalement |
| E45 | `/industry/search` en erreur | `rav` | « Recherche de types indisponible » ; Raven batch affiché |
| E46 | `/industry/search` en attente | `rav` | « Recherche… » dans le groupe Types |
| E47 | `localStorage['evetools_hidden_modules'] = 'pas du json'` | `buy` | Industry › Buy vs Build (aucun module masqué) |

## Cas limites

- **Aucun résultat** : message unique de R31, `Entrée` inopérante (E18, E24). Un groupe en erreur (R19, R24) n'est pas un « aucun résultat ».
- **Saisie dans un champ de formulaire** : `Ctrl+K`/`Cmd+K` ouvrent quand même et n'insèrent rien (E27) ; `k` seule ne fait rien (E28). Le navigateur ne reçoit pas `Ctrl+K` (sa barre de recherche reste fermée).
- **Modale ouverte** : la palette s'affiche au-dessus (R5) ; son `Escape` ne ferme pas la modale (E35). Naviguer depuis la palette quitte l'écran et donc la modale, comme un clic dans la sidebar.
- **Langue** : écrans et onglets dans la langue courante avec alias anglais en français (E7, E9) ; types toujours en anglais (R9) : `corbeau` ne trouve pas Raven.
- **Données manquantes** : nom de type de projet absent → « Nom inconnu (#typeId) » (E15) ; liste de projets ou recherche de types illisible → état « indisponible » (E43 à E45), jamais une liste vide présentée comme vraie.
- **Même écran** : naviguer vers l'onglet d'un écran déjà affiché (`/industry` → `/industry?tab=margins`) n'a d'effet que si l'écran réagit au changement de `?tab=` (#221).
- **Accès admin non encore connu** : Admin absent jusqu'à la réponse de `checkAccess()`.
- **Pages hors `MainLayout`** (login, appraisal partagée) : pas de palette ni de raccourci.

## Dépendances

- **#221** : Industry, Revenus, Inventaire et Group Industry (liste) suivent `?tab=` quand il change sur un écran déjà monté ; Industry lit `?typeId=` pour Margins, Buy vs Build et Pivot (proposé dans #221). Sans elle, E19 ne marche que depuis un autre écran, et E20 n'ouvre pas le type.
- **Dans ce lot** : `Industry.vue` lit `?project=<id>` (ouvre le détail, R20) ; `MarketBrowser.vue` lit `?typeId=` avec `tab=detail` (charge la fiche, R23).
- **#232** (modifie `MainLayout.vue` en parallèle) : le montage de la palette, le bouton du header et la mise en commun de la définition des entrées de sidebar et des modules masqués (aujourd'hui locaux à `MainLayout.vue`) se font après sa fusion, pour ne pas dupliquer `allNavEntries`.

## Hors périmètre

- Nouvel endpoint backend, index local des types, nouvelle dépendance (ni fuzzy search, ni bibliothèque de combobox).
- Tolérance aux fautes de frappe ; historique des entrées récentes ou fréquentes.
- Noms de types français (le SDE importé n'a que l'anglais).
- Actions autres que la navigation : créer un projet, lancer une sync, changer de personnage ou de langue.
- Recherche de personnages, d'assets, de systèmes, de structures, de groupes de marché.
- Onglets internes d'un détail (projet Industry, projet Group Industry) et types non fabricables (minerais, matériaux PI) dans la palette.
- Raccourci configurable ; palette sur mobile au-delà du bouton du header.
- Maquette : non produite dans ce lot (consigne de n'écrire que la spec) ; à faire avant le frontend.

## Glossaire (ajoutés au glossaire)

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Palette de commandes** | Command palette | Fenêtre ouverte par `Ctrl+K`/`Cmd+K` ou le bouton du header, qui cherche dans les écrans, les onglets, les projets et les types, et y navigue au clavier (spec #228). | à créer (`CommandPalette.vue`) |
| **Module masqué** | Hidden module | Module de la sidebar que l'utilisateur a masqué dans les paramètres ; ni lui, ni ses onglets, ni ses projets, ni ses actions ne sont proposés (spec #228). | `hiddenModules` (`MainLayout.vue`), `localStorage['evetools_hidden_modules']` |

## Décisions validées (gate 🚦)

Validée le 2026-10-27 : Sylvain a retenu la recommandation de chaque question ci-dessous.

1. **Périmètre v1 : navigation seule, ou navigation + projets + types ?** Recommandation : toute la spec, livrée en trois lots dans cet ordre : navigation et clavier (E1 à E19, E25 à E40), puis projets (E12, E14, E15, E22, E23, E43, E44), puis types après #221 (`typeId`). Si un lot doit partir avant #221, ce sera la navigation seule.
2. **Source des types : `/api/industry/search` ou `/api/market/search` ?** Recommandation : `/industry/search`. Il ne renvoie que des types fabricables, les seuls pour lesquels Marge et Buy vs Build ont un sens, et il ne fait qu'une requête SQL. `/market/search` charge prix, volumes et variation à 30 jours pour chaque résultat, à chaque frappe. Le prix à payer : un minerai ou un matériau PI ne s'ouvre pas dans Market depuis la palette.
3. **Appel API par frappe ou index local ?** Recommandation : appel par frappe, 250 ms après la dernière touche, 2 caractères minimum, réponses dépassées ignorées (R22). Un index local demanderait un nouvel endpoint qui liste tous les types fabricables, sans gain de langue puisque les noms sont anglais seulement.
