# #358 : visibilité des hangars corpo réglée par le directeur

Maquette : `industry-project-v3.html#p=28` et `#p=29` (note « Hangars corpo »). Groupe `unified-industry`. Mode nuit : validée en autonomie.

## Contexte

- `CorpAssetVisibility` (une ligne par corporation) ne porte qu'une liste blanche `visibleDivisions`, vue par toute la corporation. `VisibleCorporationAssetsFinder`, `BlueprintLibraryReader`, `OriginalBlueprintFinder` et `ProjectContainerService` la lisent.
- `GET /me/corporation/assets/visibility` renvoie toujours `isDirector = false` : le panneau `CorpVisibilityPanel.vue` (réservé au directeur) ne s'ouvre jamais. Ce défaut est corrigé ici, sans quoi le réglage reste inatteignable.
- Glossaire (à compléter) : **Visibilité d'une division** : pour chaque division, `tous les membres` (liste `visibleDivisions`, sens inchangé), `liste de joueurs` (`restrictedDivisions`), ou `masquée` (dans aucune des deux). Un **joueur** est un `User` dont le personnage principal est dans la corporation.

## Règles

1. **Stockage** : colonne `restricted_divisions` (json, `[]` par défaut, migration) sur `corp_asset_visibility` : numéro de division (1 à 7) vers liste d'identifiants `User` (uuid, chaînes). Les corporations déjà réglées gardent leur comportement (zéro donnée à migrer). Une division est dans `visibleDivisions` (tous), dans `restrictedDivisions` (liste) ou dans aucune (masquée) ; jamais dans les deux.
2. **Entité** : `isVisibleTo(int $division, ?User $viewer): bool` (vrai si la division est dans `visibleDivisions`, ou si `$viewer` n'est pas nul et que son id figure dans la liste de cette division) ; `divisionsVisibleTo(?User $viewer): list<int>` (triées). Sans viewer : seulement les divisions « tous les membres » (comportement actuel).
3. **PUT `/me/corporation/assets/visibility`** (directeur seul, inchangé) : l'entrée garde `visibleDivisions` et gagne `restrictedDivisions` (objet numéro vers liste d'ids de joueurs, absent = vide). 400 si : un numéro hors 1 à 7 ; une division à la fois dans `visibleDivisions` et `restrictedDivisions` ; un id qui n'est pas un joueur de la corporation du directeur (inconnu ou autre corporation). Une division avec liste vide est enregistrée masquée (retirée de `restrictedDivisions`). Ids dédoublonnés. Rien n'est enregistré sur une erreur.
4. **GET** (tout membre) : la ressource garde ses champs et gagne :
   - `restrictedDivisions` : numéro vers liste de `{id, name}` (nom du personnage principal) ;
   - `accessibleDivisions` : `divisionsVisibleTo(utilisateur courant)` ;
   - `players` : liste `{id, name}` de tous les joueurs de la corporation, triés par nom, **pour un directeur seulement** (vide sinon) ;
   - `directorConnected` : un personnage de la corporation a un jeton valide avec le scope assets corpo (`CharacterRepository::findWithCorpAssetsAccess`) ;
   - `isDirector` vrai quand le personnage principal a le rôle Director (`CharacterService::canReadCorporationAssets`).
5. **Lecture des assets corpo** : `VisibleCorporationAssetsFinder::findVisibleAssets` gagne un paramètre `?User $viewer = null` et filtre sur `divisionsVisibleTo($viewer)` ; sans viewer le comportement actuel est inchangé (Minage ne bouge pas). `CorporationAssetsProvider` passe l'utilisateur courant. Contenu des containers : inchangé (suit la division racine).
6. **Bibliothèque de blueprints** : `BlueprintLibraryReader` et `OriginalBlueprintFinder` filtrent les blueprints corpo sur `divisionsVisibleTo($user)`. État `no_visibility` quand cette liste est vide.
7. **Containers de projet** (`ProjectContainerService::verifyContainer`) : le viewer est le propriétaire du projet. Sans directeur connecté (`findWithCorpAssetsAccess` nul), toutes les lignes sont `unknown` avec la raison `no_director`, jamais `missing` ni 0, y compris si des assets corpo étaient déjà en cache. Ce contrôle passe avant `corporation_assets_not_synced`. Le cas `no_visible_division` devient : `divisionsVisibleTo(propriétaire)` vide. Les messages de la raison `no_director` sont ajoutés où `no_visible_division` est traduit (FR et EN).
8. **Front** (`CorpVisibilityPanel.vue`, `Assets.vue`, `types/index.ts`) : pour le directeur, chaque division a un choix à trois états (Tous les membres · Liste · Masquée) ; « Liste » ouvre un sélecteur multiple des `players` (puces avec nom). L'enregistrement envoie `visibleDivisions` et `restrictedDivisions`. Non-directeur : vue en lecture seule (qui voit quoi, puces des joueurs de chaque liste) ; les divisions partagées listées dans `Assets.vue` viennent de `accessibleDivisions`. Quand `directorConnected` est faux : bandeau d'avertissement « Aucun directeur connecté : le contenu des containers corpo est inconnu, pas vide ». Noms longs tronqués avec titre. Direction HUD EVE, composants existants. FR et EN.
9. **Mercure** : aucune action asynchrone.

## Exemples

| # | État | Résultat |
|---|---|---|
| E1 | `visibleDivisions` [2], `restrictedDivisions` {1: [alice]} ; Alice | `divisionsVisibleTo` = [1, 2] |
| E2 | Même réglage ; Bob | [2] |
| E3 | Même réglage ; sans viewer | [2] |
| E4 | PUT avec `visibleDivisions` [1] et `restrictedDivisions` {1: [alice]} | 400, rien d'enregistré |
| E5 | PUT avec `restrictedDivisions` {3: [id d'un joueur d'une autre corporation]} | 400 |
| E6 | PUT avec `restrictedDivisions` {3: []} | division 3 masquée, `restrictedDivisions` sans clé 3 |
| E7 | Container de projet, aucun directeur connecté, assets corpo en cache | toutes les lignes `unknown`, raison `no_director` |
| E8 | Division 4 en liste [alice] qui contient le container du projet d'Alice | lignes vérifiées ; projet de Bob (non listé) : `unknown` / `no_visible_division` |

## Cas limites

- Corporation sans ligne `CorpAssetVisibility` : rien n'est visible (inchangé).
- Un joueur retiré de la corporation reste dans la liste mais ne passe plus le filtre `players` au prochain PUT ; la lecture ne vérifie que l'id du viewer.
- Le directeur n'a pas d'accès implicite : il se met dans une liste comme les autres.
- Échec ESI sur le rôle Director au GET : l'erreur remonte (pas de faux `isDirector`).

## Hors périmètre

- Page dédiée « Hangars corpo » de la maquette (tableau avec compteur de containers, tableau des rôles en jeu) : le réglage reste dans le panneau de la vue Assets. Décision de produit ouverte.
- Aucune modification du code Minage (`MiningFleetStorageVerifier` garde `findVisibleAssets` sans viewer).
- Liste de l'état « inconnu » dans la vue Assets corpo : inchangée (les assets en cache restent affichés selon la visibilité).
