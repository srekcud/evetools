# Spec #534 — Industrie en 4 pages (Atelier, Projets, Analyse, Production), Équipe à l'invitation

Lot 4 `group:industry-ia`, `circuit:l`. Frontend uniquement : aucun changement backend, aucune migration (le lien de notification `IndustryJobSyncService.php:401` vise `/industry` = Atelier, toujours valide). Cible : `.claude/tasks/ux-architecture-2026-11-09.md` §4-5 (C1, C2, C4, D4), maquette `docs/design/mockups/navigation.html`. Barre d'onglets : `ModuleTabBar` et `useModuleLastPage` de #531, pas de second composant. Rien n'est retiré : tout est déplacé ou fusionné (sauf le point D2).

## Preuves de l'issue vérifiées
- `Industry.vue` : 9 pastilles (`workshop`, `dashboard`, `margins`, `batch`, `buy-vs-build`, `pivot`, `slots`, `stockpile`, `blueprints`) ; `selectPill` efface `typeId` (l.52-59).
- `GroupIndustryJoin.vue:43-45` : sur « already a member » (409 sans id de projet) renvoie vers la liste.
- `GroupIndustryList.vue` : `handleProjectCreated` ferme le modal et recharge la liste. `ProjectTabBar.vue` affiche toujours `members` et `contributions` ; `copyShareLink` toujours visible.
- Absent, donc à créer : `IndustryModuleTabs`, routes `/industry/projects|analysis|production`. `/industry/projects/:id` existe déjà.

## Règles

**R1 — Routes.** `Industry.vue` reçoit une prop `page` (`workshop` | `analysis` | `production`) ; le même composant sert les trois routes.

| Page | Chemin | Nom | Sous-vues `?tab=` |
|---|---|---|---|
| Atelier | `/industry` | `industry` | — |
| Projets | `/industry/projects` | `industry-projects` | `my-projects` (défaut), `available` |
| Détail / invitation | `/industry/projects/:id`, `/industry/join/:code` | `industry-project`, `group-industry-join` | inchangés |
| Analyse | `/industry/analysis` | `industry-analysis` | `margins` (défaut), `batch`, `buy-vs-build`, `pivot` |
| Production | `/industry/production` | `industry-production` | `slots` (défaut), `stockpile`, `blueprints` |

**R2 — Anciennes URL (redirections ; query, notamment `typeId`, conservée).**

| Ancienne | Cible |
|---|---|
| `/group-industry` | `/industry/projects` (`?tab=available` conservé) |
| `/group-industry/:id` | `industry-project` (déjà en place) |
| `/industry?tab=margins\|batch\|buy-vs-build\|pivot` | `/industry/analysis?tab=<même>` |
| `/industry?tab=slots\|stockpile\|blueprints` | `/industry/production?tab=<même>` |
| `/industry?tab=workshop\|dashboard` | `/industry` sans `tab` |

`/industry` sans `tab` reste l'Atelier. Redirection par `beforeEnter` sur la route `industry` (précédent `formerLedgerMiningLocation`). Le nom de route `group-industry` disparaît ; toutes ses références passent à `industry-projects`.

**R3 — Barre d'onglets de module.** `IndustryModuleTabs` (`components/industry-module/`, sur le modèle de `MiningModuleTabs`) : Atelier, Projets, Analyse, Production (`miningModule` → clés `industryModule.tabs.*`, i18n fr + en). Montée sur les 3 pages `Industry.vue`, sur `GroupIndustryList`, `GroupIndustryDetail` (actif « Projets ») et `GroupIndustryJoin` non. `useModuleLastPage` mémorise un module `industry` : pages `/industry`, `/industry/projects`, `/industry/analysis`, `/industry/production` ; `/industry` ne correspond qu'à l'égalité exacte (jamais `/industry/skills` ni `/industry/join/…`), un détail de projet mémorise « Projets ». Le clic sur l'entrée Industrie de la sidebar rouvre la dernière page.

**R4 — Sous-vues dans `Industry.vue`.** Les pastilles `margins/batch/buy-vs-build/pivot` deviennent des sous-onglets (`useTabQuery`) de la page Analyse, `slots/stockpile/blueprints` de Production. Changer de sous-onglet conserve toute la query, `typeId` compris (plus d'effacement). Le panneau de configuration (engrenage, frais, skills, structures, blacklist, durée max) reste monté sur les 3 pages. Le bandeau de fraîcheur des données reste sur Analyse. `navigationIntent` : `margins|batch|buy-vs-build|pivot` → `router.push` vers `/industry/analysis?tab=…` (avec `typeId` s'il existe) ; `slots|stockpile` → `/industry/production?tab=…` ; `projects` → `/industry/projects` (l'intention reste pour la liste).

**R5 — Atelier fusionné.** Une seule page `/industry` (page `workshop`), dans cet ordre : blocs de tête de `IndustryDashboard` (KPI, alertes, actions rapides dont **Nouveau projet** qui porte `openCreateModal`), puis `IndustryWorkshop` (file d'actions, prochains jobs, projets filtrés Tous/Solo/Groupe). Seul le mini-tableau « Projets récents » du Dashboard n'est plus rendu : il double la liste filtrée de l'Atelier (même ouverture de projet, statut et progression déjà portés par les lignes). Le lien « Voir tous les projets » devient un lien vers Projets.

**R6 — « Industrie de groupe » devient « Projets ».** Titre, fil, libellés, `groupIndustry.join.moduleLabel`, `backToGroupIndustry` : plus aucun « Industrie de groupe » / « Group industry » visible (fr et en). `PROJECTS_HOME` = `{ name: 'industry-projects' }` (retour du détail après suppression).

**R7 — Palette (`useNavigationEntries`, `useCommandPaletteProjects`, `commandPaletteTypeActions`).** Entrées : Industrie (= Atelier), Industrie › Projets (+ onglet `available`), Industrie › Analyse (+ 4 sous-onglets), Industrie › Production (+ 3 sous-onglets) ; plus d'entrée `?tab=workshop|dashboard` ni `?tab=` sur `/industry`. Les entrées projet pointent `/industry/projects/:id`. Les actions de type : marges `/industry/analysis?tab=margins&typeId=N`, achat vs prod `/industry/analysis?tab=buy-vs-build&typeId=N`.

**R8 — Équipe conditionnelle (C4).** Dans le détail, `Contributions` et `Équipe` ne sont affichés que si l'équipe existe : `membersCount ≥ 2`, ou au moins un membre `pending` (invitation en attente), ou `pendingContributionCount > 0` (jamais masquer ce qui attend une revue). L'état « seul » n'est conclu que si la liste des membres est chargée (`fetchMembers` au chargement du projet, rafraîchie sur `member_joined|accepted|left`) ; chargement ou échec = onglets affichés (inconnu ≠ seul). Projet seul : le bouton « Partager le lien » devient **Inviter** (même action, copie du lien) ; avec équipe, il reste « Partager le lien ». Un `?tab=members|contributions` sur un projet seul retombe sur `bom`. Le badge de l'onglet reste celui d'aujourd'hui.

**R9 — `/join/:code` d'un membre existant.** Sur « already a member » (409), recharger `fetchMyProjects`, trouver le projet par `shortLinkCode` et `router.replace` vers `industry-project` ; introuvable : liste Projets. Un lien « Retour » de la page d'attente et d'erreur va à `industry-projects`.

**R10 — Création depuis le modal.** `NewProjectModal` émet déjà la création ; `handleProjectCreated` ouvre le projet créé (`router.push` vers `industry-project` avec son id) au lieu de recharger la liste. `NewProjectModal` émet `created` avec le `GroupProject` renvoyé par `createProject` (aucun autre changement).

## Exemples
| Entrée | Résultat |
|---|---|
| `/industry?tab=buy-vs-build&typeId=587` | `/industry/analysis?tab=buy-vs-build&typeId=587` |
| Analyse › Marges avec `typeId=587`, clic Scan puis Marges | `typeId=587` toujours dans l'URL |
| Projet : 1 accepté, 0 pending, `pendingContributionCount` 0, membres chargés | ni Contributions ni Équipe ; bouton « Inviter » |
| Même projet, 1 membre `pending` | Contributions + Équipe, « Partager le lien » |
| Même projet, liste des membres en erreur | onglets affichés |
| `/industry/join/ABC`, déjà membre accepté | ouvre `/industry/projects/<id>` |

## Cas limites
- `/industry/skills` ne reçoit pas la barre d'onglets et ne change pas la dernière page.
- `?tab=` inconnu sur Analyse/Production : sous-onglet par défaut (comportement `useTabQuery`).
- Noms de projet longs : tronqués avec infobulle dans les lignes déjà existantes (aucune nouvelle ligne de liste).

## Hors périmètre
Réorganisation de `router/index.ts`, `sidebarNavigation.ts`, fichiers i18n (lot #536) ; page Paramètres (le panneau de configuration reste) ; ponts Marges → projet et BOM → multibuy (`group:workflow-bridges`) ; règle de prix commune ; lien job → projet de Production ; backend.

## Décisions (prises la nuit, à confirmer)
1. Mini-tableau « Projets récents » du Dashboard non rendu dans l'Atelier (doublon de la liste filtrée) : seul élément retiré, à confirmer par Sylvain.
2. `blueprints` (Bibliothèque de blueprints) rejoint Production (absent de la maquette, rien n'est retiré).
3. « Invitation en attente » = demande d'adhésion `pending` ; il n'existe pas d'autre objet « invitation » côté projet.
4. Pas de changement backend : le 409 « déjà membre » ne porte pas d'id, le front retrouve le projet par `shortLinkCode`.
