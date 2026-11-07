# #335 — Atelier : file d'actions sur tous les projets

Issue #335 (circuit:m, group:unified-industry). Maquette : `industry-project-v3.html#p=1` (notes 2 et 3). Niveau de technicité : 3 (code Industry). Spec validée en autonomie (mode nuit).

## Objectif
La page d'accueil Industrie dit quoi faire maintenant, tous projets confondus (solo et groupe sont un seul projet) : une **file d'actions** triée, les prochaines fins de jobs, les projets en maître-détail avec colonne Équipe et filtre. La barre latérale affiche le nombre d'actions sur l'entrée Industrie.

## Vocabulaire (à ajouter au glossaire)
**Atelier** : page d'accueil Industrie (onglet par défaut de `/industry`). **File d'actions** : liste des prochaines actions du cockpit de chaque projet de la personne connectée, une ligne par projet, triée par impact.

## API (R1 à R5)
- **R1** `GET /industry/actions`, ressource `IndustryActionResource` (collection, sans pagination), `security: is_granted('ROLE_USER')`, 401 sans utilisateur.
- **R2** Projets considérés : ceux où la personne est propriétaire ou participante `accepted` (même source que `ProjectCollectionProvider`, `findAcceptedWithProjectsByUser`). Un participant `pending`, un projet d'un autre utilisateur ou de la même corporation non rejoint n'apparaît jamais.
- **R3** Une entrée par projet dont la prochaine action du cockpit (celle de la personne connectée, `viewerIsManager` inclus) n'est ni `project_completed` ni `nothing_to_do`. Champs : `projectId`, `projectName` (nullable, comme `IndustryProjectResource::$name`), `code`, `blocked`, `tab`, `targetStatus`, `bomLineId`, `typeId`, `typeName`, `quantity` (les mêmes valeurs que `ProjectCockpitResource::$nextAction`).
- **R4** Le calcul de la prochaine action est celui de `ProjectCockpitCalculator`, sans copie de règle. L'entrée est construite par une fabrique partagée avec `ProjectCockpitProvider` (extraction de `toCockpitBomLine`, `soldQuantityByTypeId`, `pendingContributionCount`, `isManager` vers une classe d'entrée de cockpit) ; `ProjectCockpitProviderTest` est adapté au nouveau constructeur sans changer ses assertions.
- **R5 Tri par impact** (classe pure, sans base, ex. `ActionQueueSorter` dans `src/Service/Industry/Project/`) : d'abord les entrées `blocked = true` (projet à l'arrêt), puis par rang de code : `record_sale`, `complete_project`, `launch_job`, `source_material`, `start_selling`, `review_contributions`, `start_project` (de la plus urgente à la moins urgente : l'argent à encaisser d'abord, la mise en route en dernier). Égalité : nom du projet croissant (nom absent en dernier), puis `projectId`. Un code inconnu lève une `\UnexpectedValueException` (jamais de rang par défaut).

| Projets de la personne | File renvoyée (ordre) |
|---|---|
| A `in_progress` matériau manquant (blocked), B `selling` ventes restantes, C `draft` géré | A, B, C |
| B `selling` restantes, D `selling` tout vendu (complete_project), E `published` couvert (start_project) | B, D, E |
| deux projets `launch_job` « Alpha » et « Bravo » | Alpha, Bravo |
| projet `completed`, projet `draft` d'un simple membre (`nothing_to_do`) | file vide |
| participant `pending` sur un projet en cours | file vide |

## Front (R6 à R14)
- **R6** Nouvel onglet `workshop` (« Atelier ») en premier du groupe Vue d'ensemble de `Industry.vue`, **onglet par défaut** ; `dashboard` et tous les onglets d'analyse restent (aucun retrait). Composant `components/industry/IndustryWorkshop.vue`, store `stores/industry/actions.ts` (`fetchActions`, `actions`, `count`, erreur explicite si l'appel échoue, jamais une file vide silencieuse).
- **R7 File d'actions** : en tête de l'Atelier, titre, puces « N à traiter », une ligne par entrée : projet, phrase par `code` (FR/EN, nom du type et quantité insérés, quantité inconnue `null` dite « inconnue », jamais 0), pastille ambre si `blocked`, bouton d'action. Le bouton ouvre le cockpit `industry-project` du projet, avec `?tab=` = `detailTabOfNextAction(tab)` quand `tab` n'est pas nul. File vide : état vide « Rien à faire maintenant ». Échec : bandeau d'erreur (`ErrorBanner`).
- **R8 Jobs à venir** : carte « Prochaines fins de jobs » depuis `timeline` du store slots (`/industry/slots`, déjà chargé par l'onglet Slots), triée par `endDate` croissant, au plus 8 lignes (produit, personnage, « dans » durée). Vide : « Aucun job en cours ». Échec du chargement : message d'erreur.
- **R9 Projets en maître-détail** : tableau des projets de la personne (store `useGroupProjectStore().myProjects`) avec colonnes Produit (premier item ×runs, « + N » s'il y en a d'autres, nom du projet sinon), Équipe, Cycle de vie (libellé du statut + mini rail de 5 segments selon le statut : draft 1, published 2, in_progress 3, selling 4, completed 5), tri : projets non terminés d'abord, puis `createdAt` décroissant. Clic sur une ligne : sélectionne (aria-selected) ; panneau de droite : nom, statut, l'entrée de la file de ce projet (phrase + bouton) ou « Aucune action en attente », bouton « Ouvrir le cockpit ». Première ligne sélectionnée par défaut.
- **R10 Équipe** : `membersCount = 1` → pastille « Solo » ; sinon « Groupe · N » (N = `membersCount`) et le rôle `myRole` (owner, admin, membre). Les avatars des membres sont hors périmètre (aucun identifiant de membre dans la liste).
- **R11 Filtre** Tous / Solo / Groupe avec compteurs (Solo = `membersCount` 1, Groupe = plus). Le filtre ne change pas la file d'actions (elle couvre tous les projets). Si la ligne sélectionnée sort du filtre, la première ligne visible l'est à sa place ; liste filtrée vide : état vide.
- **R12 Barre latérale** : l'entrée `industry` (barre latérale et tiroir mobile, `MainLayout.vue`) affiche un compteur = `count` du store des actions, masqué à 0 et tant que la file n'est pas chargée ; une erreur n'affiche aucun compteur (jamais 0). Chargé une fois par `MainLayout` quand le module Industrie n'est pas masqué (`isProjectsHidden`).
- **R13 Actualisation** : l'Atelier et le compteur se rechargent sur l'événement Mercure existant `industry-project` (même abonnement que `groupProjectRefreshes.ts`/cockpit, aucun nouveau topic) et au retour sur l'onglet. Aucune action asynchrone nouvelle : donc pas de nouveau topic Mercure.
- **R14** Direction HUD EVE, mobile 375 px sans débordement horizontal, noms longs tronqués (`truncate` + `title`), clés i18n FR et EN, aucun commentaire de code.

## Cas limites
- Projet sans nom : « Sans nom » (clé i18n), tri en dernier à égalité.
- Projet dont la BOM est vide ou sans ligne de job : le calcul du cockpit s'applique tel quel (aucune règle ajoutée).
- Plusieurs milliers de lignes : aucune pagination ; une requête de projets, pas de requête par projet pour les participants (comptes via `countAcceptedByProject`).
- Une ligne de job de groupe inconnu dans un projet lève l'erreur existante du calculateur : la file renvoie 500 avec le projet fautif dans le message (pas d'entrée silencieuse ignorée).

## Accès et fraîcheur
- Seules les lignes de la personne connectée (R2) ; aucun identifiant reçu en entrée. La file est calculée à chaque lecture, rien n'est persisté.
- Le rôle gestionnaire est celui de `ProjectCockpitProvider::isManager` (`owner` ou `admin` accepté).

## Tests existants modifiés
- `ProjectCockpitProviderTest` : constructeur (fabrique d'entrée de cockpit), assertions inchangées.
- Tests exhaustifs voisins à relire : liste des onglets de `Industry.vue` et onglet par défaut (`frontend/tests`, grep `dashboard`/`useTabQuery`), entrées de la barre latérale (`sidebarNavigation`, tiroir mobile), routes API listées.

## Hors périmètre
- Avatars des membres (colonne Équipe) : demande un identifiant de membre dans la liste des projets.
- Profit par projet, alertes prix, cible de Stockpile < 25 %, santé des structures, ordres sous-cotés (notes 22, 31, 33 de la maquette) : aucune donnée de projet disponible, pas de valeur inventée.
- Cartes KPI de la maquette, bouton « Nouveau projet », liens Revenus, Compétences, Blueprints, Hangars corpo.
- Suppression de `GroupIndustryList.vue` ou de la route `/group-industry` (conservés).
