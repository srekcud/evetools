# #355 : modèles de projet partagés avec la corpo

Maquette : `industry-project-v3.html#p=36` (note « Modèles »). Groupe `unified-industry`. Mode nuit : validée en autonomie.

## Contexte

- La production récurrente oblige à ressaisir items, ME/TE, liste noire et option de structure à chaque création (`NewProjectModal.vue`, `CreateProjectInput`).
- Un projet porte déjà ces données : `items` (`IndustryProjectProduct` : type, ME, TE, runs), `blacklistGroupIds`, `blacklistTypeIds`, `acceptsOutsideFavoriteSystem`, `minimumOutsideSaving`. Les structures de production elles-mêmes sont celles du **créateur du projet** (`IndustryStructureConfig`, jamais copiées dans un projet) : le modèle garde donc seulement la politique de choix de structure (option hors système préféré et économie minimale), pas de liste de structures.
- Glossaire (à ajouter) : **Modèle de projet** (Project template) : photo des paramètres de création d'un projet (items, ME/TE, runs, liste noire, option hors système préféré), enregistrée par un utilisateur, personnelle ou partagée avec la corporation de son personnage principal. Ni prix, ni stock, ni participants, ni BOM : tout est relu à la création du projet qui l'utilise.

## Règles

1. **Entité** `IndustryProjectTemplate` (table `industry_project_templates`, migration) : `id` uuid, `owner` (User), `name` (1 à 255 caractères), `items` (json : liste de `{typeId, typeName, meLevel, teLevel, runs}`), `blacklistGroupIds`, `blacklistTypeIds` (json, entiers), `acceptsOutsideFavoriteSystem`, `minimumOutsideSaving`, `sharedWithCorporation` (faux par défaut), `createdAt`, `updatedAt`. Supprimée avec son propriétaire (suppression de compte : `AccountDeletionService` la supprime).
2. **Enregistrer depuis un projet** : `POST /api/industry/project-templates` avec `{projectId, name, sharedWithCorporation}`. Droit : `assertAcceptedMember` du projet (404 projet inconnu avant tout contrôle, 403 sinon). Le modèle copie les items (ordre conservé), la liste noire (groupes et types) et les deux options du projet à cet instant ; il ne suit plus le projet ensuite. `name` vide ou absent : 422. Au plus **50 modèles par propriétaire** (`IndustryProjectTemplate::MAXIMUM_PER_OWNER`) : 422 au 51e.
3. **Lister** : `GET /api/industry/project-templates` renvoie les modèles visibles de l'utilisateur : les siens, plus ceux dont `sharedWithCorporation` est vrai et dont le propriétaire a la même corporation que lui (`User::getCorporationId()`, corporation du personnage principal, comme la découverte de projets ; sans corporation : seulement les siens). Chaque élément : `id`, `name`, `items`, `blacklistGroupIds`, `blacklistTypeIds`, `acceptsOutsideFavoriteSystem`, `minimumOutsideSaving`, `sharedWithCorporation`, `ownerName` (nom du personnage principal du propriétaire), `isOwn`, `createdAt`. Tri : `updatedAt` décroissant. Un modèle d'une autre corporation ou non partagé n'apparaît jamais.
4. **Modifier** : `PATCH /api/industry/project-templates/{id}` (`application/merge-patch+json`) : `name` et `sharedWithCorporation` seulement, propriétaire seul. Modèle non visible de l'utilisateur : 404 ; visible mais d'un autre : 403 « Only the template owner can perform this action ». `name` vide : 422.
5. **Supprimer** : `DELETE /api/industry/project-templates/{id}`, mêmes droits que R4 (provider qui renvoie la ressource). Les projets déjà créés avec le modèle ne sont pas touchés.
6. **Utiliser** : aucune route serveur. Le front préremplit `NewProjectModal` avec le modèle (items, ME/TE, runs, liste noire, options) ; l'utilisateur peut tout changer avant de créer, le projet naît par `POST /api/industry/projects` comme aujourd'hui, avec ses validations (1 à 10 items, blueprint existant…). Les groupes de la liste noire d'un modèle passent par `blacklistGroupIds` (le formulaire ne les affiche pas, il les renvoie tels quels). Le modèle ne mémorise ni nom de projet, ni conteneur, ni frais, ni location de ligne, ni visibilité.
7. **Front** : (a) bouton « Enregistrer comme modèle » dans le cockpit du projet (`GroupIndustryDetail.vue`), pour tout participant accepté : petite fenêtre (nom prérempli du nom du projet, case « Partager avec ma corporation ») ; (b) onglet « Depuis un modèle » dans `NewProjectModal` (à côté de l'ajout d'un produit et du collage en masse) : tableau Modèle (nom, « par … ») · Items · Partagé (puce « perso » ou « corpo ») · bouton « Utiliser » ; pour ses modèles : bascule de partage et suppression avec confirmation. État vide : « Aucun modèle : enregistre un projet comme modèle ». Noms longs tronqués avec titre complet. Direction HUD EVE, composants existants. FR et EN. Store `stores/group-industry/templates.ts` (liste, enregistrement, modification, suppression ; une erreur remonte, jamais une liste vide silencieuse).
8. **Mercure** : aucune action asynchrone.

## Exemples

| # | État | Résultat |
|---|---|---|
| E1 | Projet de 2 items (Hammerhead II ME 10 TE 20 runs 50, Warrior II ME 5 TE 10 runs 20), liste noire groupe 18 et type 34, `acceptsOutsideFavoriteSystem` vrai, économie 5 000 000 | modèle avec les 2 items dans l'ordre, groupes [18], types [34], option vraie, 5 000 000, non partagé |
| E2 | Alice (corporation 98000001) partage un modèle ; Bob (même corporation) liste | Bob le voit, `isOwn` faux, `ownerName` = personnage principal d'Alice |
| E3 | Même modèle, Carol (corporation 98000002) | absent |
| E4 | Modèle d'Alice non partagé, Bob même corporation | absent |
| E5 | Bob sans personnage principal | ne voit que les siens |
| E6 | Alice passe `sharedWithCorporation` à faux | Bob ne le voit plus |
| E7 | Bob PATCH le modèle partagé d'Alice | 403 ; Carol (invisible) : 404 |
| E8 | Alice a 50 modèles et en crée un 51e | 422, aucun modèle ajouté |
| E9 | Utilisateur sans accès au projet (non participant) | 403, aucun modèle créé |
| E10 | Alice supprime son modèle, un projet créé avec lui | projet intact, modèle absent de la liste de Bob |

## Cas limites

- Projet avec liste noire vide : tableaux vides, jamais null. Items d'un projet : copiés tels que persistés (typeName du projet, ou résolu depuis le SDE si le projet n'en garde pas).
- Corporation de Bob changée depuis la connexion : la visibilité suit `Character::$corporationId` à jour de la dernière connexion. Défaut préexistant de la découverte de projets, repris tel quel.
- Un modèle dont un type n'a plus de blueprint au SDE : la création du projet le refuse (400 existant), le modèle reste listé.

## Accès et fraîcheur

- Aucun identifiant d'un autre périmètre n'est accepté en écriture hors `projectId` (contrôlé par `assertAcceptedMember`). `id` d'un modèle : visibilité vérifiée côté serveur avant tout, jamais déduite du front.
- Un modèle partagé expose items, ME/TE, runs et liste noire à la corporation du propriétaire : le propriétaire le choisit explicitement (partage faux par défaut). Pas de nom de projet, de code d'invitation ni de participant dans le modèle.

## Tests existants contredits

Aucun. À vérifier par le test-writer : tests qui énumèrent les ressources API Industry ou les tables supprimées par `AccountDeletionService` (grep avant le vert).

## Hors périmètre

Compteur d'utilisations (colonne « Utilisé » de la maquette : pas de lien modèle-projet), modèle partagé avec des personnes précises, modification des items d'un modèle (on enregistre à nouveau depuis un projet), version, import de doctrine, structures du créateur.

## Décisions ouvertes (pour Sylvain)

1. Colonne « Utilisé N× » de la maquette : ajoutée plus tard avec un `templateId` sur le projet ?
2. « Structures » du modèle = politique de choix seulement, pas d'identifiants de structures (propres à chaque pilote) : à confirmer.
