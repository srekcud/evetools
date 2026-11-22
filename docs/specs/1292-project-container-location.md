# #1292 — A project container has a location, one container per station

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype. Back-end seulement : l'écran qui montre ces containers par station est la page projet en flux (#1307) ; la lecture station par station du contenu est #1293.

## Contexte

`IndustryProjectContainer` n'a qu'un nom (unique par projet) ; `IndustryProject::setContainerNames()` les remplace par liste de noms et `ProjectContainerService` les retrouve dans les assets par nom, toutes stations confondues (#801). Le flux demande un container par station du plan. Un container de projet est dédié à un projet dans une station ; un container de stock perso en division corporation est une autre notion (#1324) : un container ne peut pas être les deux.

## Règles

R1. **Lieu.** `industry_project_containers.location_id` (bigint nullable) : identifiant EVE de la station ou de la structure, de même nature que `IndustryStructureConfig::$locationId` et que `CachedAsset::$locationId` d'un hangar. `null` = container sans lieu, comportement actuel inchangé (nom seul, recherche par nom dans toutes les stations) jusqu'à la migration #1320.

R2. **Un container par station.** Index unique `(project_id, location_id)` : deux containers du même projet ne partagent pas de lieu (les `null` ne se comparent pas). L'unicité `(project_id, name)` reste.

R3. **Stations du plan.** Pour un projet, `ProjectContainerStations::of($project)` renvoie une entrée par station distincte (par `locationId`) des structures du plan : structures de `IndustryProject::getWorkStations()` (clé `structureId`) et structures prévues des lignes de BOM (`plannedStructureId`, uuid valide), lues parmi les `IndustryStructureConfig` du **propriétaire du projet**. Un uuid qui ne désigne aucune configuration du propriétaire est ignoré (structure supprimée ou d'un autre utilisateur). Deux structures du plan au même `locationId` donnent une seule station. Une configuration sans `locationId` donne une entrée `locationId = null` (station inconnue, aucun container proposé ni créable), jamais une station par défaut.

R4. **Entrée d'une station.** `{structureId, structureName, locationId, solarSystemId, container: {id, name}|null, proposedName: string|null}`. `container` = le container du projet à ce `locationId`. `proposedName` (seulement sans container et avec `locationId`) = `<nom du projet> <nom de la structure>` (projet sans nom : le nom de la structure seul), rogné à 255 caractères, suffixé ` 2`, ` 3`… tant qu'un container du projet porte déjà ce nom (clé `IndustryProjectContainer::keyOf`).

R5. **Lecture.** `GET /industry/projects/{projectId}/container-stations` : tout membre accepté du projet (`assertAcceptedMember`). Calculée à chaque lecture, rien de persisté. Liste vide pour un projet sans structure de plan.

R6. **Création ou pose du lieu.** `POST /industry/projects/{projectId}/containers` avec `{locationId: int, name?: string}` : propriétaire ou administrateur (`assertAdminOrOwner`), projet non terminé (409 sinon, comme les autres écritures du projet). `locationId` doit être celui d'une station de R3 (422 `Location is not a station of the plan` sinon). Nom absent ou vide = `proposedName`. Effets :
- la station a déjà un container : 409 ;
- un container du projet de même nom (clé `keyOf`) existe sans lieu : il reçoit le lieu (pose du lieu sur un container existant, pas de doublon) ;
- un container du projet de même nom existe avec un autre lieu : 409 ;
- sinon : nouveau container avec nom et lieu.
Réponse 200 : l'entrée de la station (R4) avec son `container`.

R7. **Noms existants.** `IndustryProject::setContainerNames()` et ses appelants (création et mise à jour du projet, `getContainerNames()`, `PinnedContainerCatalog`, `ProjectContainerService`) ne changent pas. Un container qui a un lieu reste un nom pour eux. Retirer son nom de la liste le supprime, lieu compris. `setContainerNames` garde la ligne d'un nom qui reste, donc son lieu.

R8. **Aucun montant, aucune donnée ESI.** Ni sync, ni calcul d'argent, ni suppression de donnée : aucune action asynchrone, pas de topic Mercure.

## Exemples (tests rouges)

| Plan du projet | Containers du projet | Appel | Résultat |
|---|---|---|---|
| work_stations : final_products → A (loc 100), components → B (loc 200) | aucun | GET | 2 entrées, `container` null, `proposedName` « Ishtar A » / « Ishtar B » (projet « Ishtar », structures « A », « B ») |
| lignes de BOM prévues en A (x2) et A+B | aucun | GET | 2 entrées (A, B), A une seule fois |
| structures A et A' au même locationId 100 | aucun | GET | 1 entrée |
| structure C sans locationId | aucun | GET | entrée `locationId` null, `proposedName` null |
| uuid d'un autre utilisateur dans work_stations | aucun | GET | ignoré |
| plan en A (100) | « Ishtar A » à 100 | GET | `container` {id, « Ishtar A »}, `proposedName` null |
| plan en A (100) | « Ishtar A » sans lieu | GET | A : `container` null, `proposedName` « Ishtar A 2 » |
| plan en A (100) | aucun | POST {locationId: 100} | 200, container « Ishtar A » à 100 |
| plan en A (100) | aucun | POST {locationId: 100, name: « Mats »} | container « Mats » à 100 |
| plan en A (100) | « Mats » sans lieu | POST {locationId: 100, name: « mats »} | 200, le même container reçoit le lieu 100, toujours 1 container |
| plan en A (100) | « X » à 100 | POST {locationId: 100} | 409 |
| plan en A (100), B (200) | « Mats » à 200 | POST {locationId: 100, name: « Mats »} | 409 |
| plan en A (100) | aucun | POST {locationId: 999} | 422 |
| projet terminé | | POST | 409 |
| membre accepté non administrateur | | POST | 403 |
| utilisateur étranger au projet | | GET ou POST | 403 |

## Cas limites

- `name` plus long que 255 : 422. Nom blanc : traité comme absent.
- `locationId` absent ou non entier positif : 422.
- Deux POST concurrents sur la même station : l'index unique de R2 refuse le second ; le processeur répond 409, jamais 500.
- Plan jamais calculé (aucune ligne prévue, aucun choix) : liste vide.

## Accès et fraîcheur

- Lecture : membre accepté. Écriture : propriétaire ou administrateur. Un `locationId` n'est accepté que s'il vient d'une configuration de structure **du propriétaire du projet** présente dans le plan : un id de station hors plan ou d'un autre utilisateur est refusé (422), même pour un administrateur.
- Source des stations : `industry_structure_configs` du propriétaire (lue à chaque appel, pas de cache) ; le plan lui-même vient des colonnes `work_stations` et `planned_structure_id`, mises à jour par le plan, donc aussi fraîches que lui.
- Le lieu stocké est une copie : si la configuration de structure change de `locationId` plus tard, le container garde son lieu et n'est plus « de la station du plan » ; il apparaît alors sans entrée correspondante. Cas traité par la migration et la lecture station par station (#1293, #1320), pas ici.

## Tests existants modifiés

Aucun attendu : le constructeur `IndustryProjectContainer(project, name)` garde sa signature, le lieu s'ajoute par `locateAt(int)`.

## Hors périmètre

Lecture du contenu par station (#1293) ; containers de stock perso (#1324) ; migration des projets existants (#1320) ; écran de choix ou de proposition des containers (assistant #1289, page en flux #1307) ; renommage ou suppression d'un container isolé (par `containerNames` du projet comme aujourd'hui) ; vérification que le container existe dans les assets.

## Glossaire

Ajouts : **Station du plan** (Plan station), **Container de projet** (Project container, lieu et unicité par station). Le terme **Container** (asset) reste inchangé.

## Décisions

D1. Back-end seulement (aucun écran dans l'issue ; la proposition s'affiche dans le wizard et la page en flux).
D2. Le lieu est l'identifiant EVE de la station ou de la structure (`locationId`), pas l'uuid de `IndustryStructureConfig` : il est celui des assets et des jobs, et survit à la suppression de la configuration.
D3. Un seul index unique `(project_id, location_id)` ; l'unicité par nom reste (le nom proposé inclut la station pour ne pas entrer en collision).
D4. Les containers sans lieu restent valides ; poser le lieu sur un container existant passe par le même POST que la création (pas de PATCH séparé).
