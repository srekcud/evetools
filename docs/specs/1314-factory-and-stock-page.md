# #1314 — Factory and stock page

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`#usine`). Prérequis sur la branche : #1299 (accès aux structures), #1324 (stock perso en division corpo, lecteur unique). Deux ressources de lecture et une page ; aucun calcul d'argent, aucune migration, aucune action asynchrone (pas de Mercure).

## Contexte

Les stations, les lignes, le stockpile et les blueprints vivent aujourd'hui dans des onglets de la page Production (`/industry/production`, `Industry.vue`) ou dans les réglages. Le prototype rassemble ce qui ne dépend pas d'un projet dans une page « Usine et stock » : tableau des stations, lignes par joueur, stockpile avec « Créer un projet », bibliothèque de blueprints, stock utilisé ou rendu par les projets. #1324 y place aussi le réglage des containers de stock perso. La page Production reste en place (aucun retrait sans accord de Sylvain).

## Règles back-end

R1. **Stations.** `GET /industry/factory/stations` (`FactoryStationResource`, collection, `paginationEnabled: false`, `skip_null_values: false`) : une entrée par `IndustryStructureConfig` de l'utilisateur (`findByUser`), ordre `name`. Champs : `id` (uuid de la configuration), `name`, `structureType`, `securityType`, `locationId`, `solarSystemId`, `solarSystemName`, `specialisations`, `manufacturingCostIndex`, `reactionCostIndex`, `access`, `linesInUse`, `projects`.

R2. **Spécialisation.** `specialisations` : liste ordonnée, `manufacturing` si un bonus de matériaux ou de temps de fabrication de la configuration est > 0, `reaction` si un bonus de matériaux ou de temps de réaction est > 0. Aucun bonus : liste vide (pas d'étiquette inventée).

R3. **Index.** `manufacturingCostIndex` et `reactionCostIndex` = `SystemCostIndices::costIndexOf(solarSystemId, ActivityKind::Manufacturing|Reaction)`. Système inconnu de la configuration ou index absent : `null`, jamais 0.

R4. **Accès.** `access` : une entrée par personnage de l'utilisateur (`characterId`, `characterName`, `status` = `granted|denied|unknown`), lue par `CharacterStructureAccessReader::statusOf` (une station NPC est `granted`). Configuration sans `locationId` : chaque personnage `unknown`. Seuls ses propres personnages : jamais ceux d'un autre membre.

R5. **Lignes en service.** `linesInUse` = nombre de jobs actifs (`STATUS_ACTIVE`, `STATUS_PAUSED`, `STATUS_READY`, comme `findActiveJobsByCharacter`) des personnages de l'utilisateur dont `stationId` = `locationId` de la configuration. Configuration sans `locationId` : `null` (inconnu), jamais 0.

R6. **Projets.** `projects` : `{id, name}` des projets non terminés dont l'utilisateur est participant accepté et dont au moins une ligne de BOM a `plannedStructureId` = `id` de la configuration ; ordre `name`. Un projet terminé n'y figure pas.

R7. **Stock des projets.** `GET /industry/factory/project-stock` (`FactoryProjectStockResource`, collection, `paginationEnabled: false`) : lignes `{id, projectId, projectName, projectStatus, typeId, typeName, state, quantity}`, `id` = `projectId:typeId:state`. Périmètre : projets où l'utilisateur est participant accepté, apports `material` approuvés (`ContributionStatus::Approved`) sur les lignes de BOM, somme par projet et par type. Le stock se lit au niveau du projet, pas par membre (aucune répartition inventée) ; l'origine du stock (perso, corpo, stockpile, achat) n'est pas enregistrée par les apports et n'est pas montrée.
- Projet non terminé : `state = reserved`, `quantity` = somme des apports approuvés.
- Projet terminé : `state = consumed`, `quantity = min(apporté, requis)` de la ligne ; `state = returned` pour le surplus de matières (`ProjectSurplusBuilder`, ligne de source apport) ; le surplus de production (source `Production`) n'est pas un stock rendu et est ignoré. Une quantité à 0 n'a pas de ligne.
- Une ligne dont deux lignes de BOM portent le même type s'additionne par type.

R8. **Accès.** Les deux ressources lisent seulement l'utilisateur connecté (`ROLE_USER`, 401 sans authentification) ; un projet n'apparaît que si l'utilisateur y est participant accepté (`findAcceptedWithProjectsByUser`). Lecture seule, aucune écriture, aucun appel ESI.

## Règles front

R9. **Page.** Route `/industry/factory` (`industry-factory`, `requiresAuth`), vue `IndustryFactory.vue`, entrée « Usine et stock » dans `IndustryModuleTabs` (page `factory`), sections empilées dans l'ordre du prototype (pas d'onglets) : Usine (stations), Stockpile, Stock utilisé ou rendu par les projets, Lignes par joueur, Blueprints, Mon stock perso. Libellés FR/EN (`industryFactory.*`), palette HUD du module.

R10. **Stations.** Tableau Station (nom, système, type), Spécialisation (libellés), Index (pourcentage, « inconnu » si `null`), Accès (une pastille ✓ / ✕ / ? par personnage), Lignes occupées (« inconnu » si `null`), Projets (noms, liens vers le projet). Aucun zéro affiché pour un inconnu.

R11. **Stockpile.** Lit `stockpile-status` (store existant). Une ligne par cible du niveau produit final avec niveau (`stock / cible`, barre), station la plus fournie (`locations[0]`), et si `suggestedProjectRuns !== null` : état « sous la cible : N à refaire » et bouton « Créer un projet » qui pose `navigationIntent = {target: 'projects', prefill: {typeId, typeName, runs: suggestedProjectRuns}}` puis navigue vers `industry-projects`.

R12. **Stock des projets.** Tableau Article, Quantité, Projet (lien), État (`réservé · projet` / `consommé` / `rendu au stock`), depuis R7. Vide : message « Aucun stock engagé dans un projet ».

R13. **Lignes par joueur.** Depuis `/industry/slots` (store existant) : Joueur (personnage), lignes occupées et max par activité (fabrication, réaction, science), libres ; un personnage aux compétences inconnues (`skillsUnknown`) affiche « inconnu » pour le max et les libres, jamais 0.

R14. **Blueprints.** Tableau compact depuis `/industry/blueprints` (store existant) : Blueprint, Type (BPO/BPC/BPR), ME/TE, Où (`locationId`, division), Utilisé par (« en job » si `usedByRunningJob`). Filtre de recherche par nom. L'état de la corporation (`corporationState`) est affiché quand il n'est pas lisible.

R15. **Mon stock perso (#1324).** Liste des containers déclarés (`/industry/settings/personal-stock-containers` : nom, division, station, état `known`/`unknown` avec raison, nombre d'articles), bouton Retirer (DELETE), champ « Item id du container » et bouton Déclarer (POST) ; les refus 404/409/422 de l'API sont montrés tels quels. Un état `unknown` n'affiche pas de nombre d'articles.

## Exemples (tests rouges)

Utilisateur U avec deux personnages A et B ; configurations S1 (Raitaru, locationId 100, système 30000142, rig manufacturing +4,2 % matériaux, rig reaction 0) et S2 (sans locationId).

| Cas | Résultat |
|---|---|
| S1, rig manufacturing seul | `specialisations = ['manufacturing']` |
| S1, rigs manufacturing et réaction | `['manufacturing', 'reaction']` |
| S1, aucun bonus | `[]` |
| index de manufacturing absent pour le système | `manufacturingCostIndex = null` |
| S2 | `locationId = null`, accès `unknown` pour A et B, `linesInUse = null` |
| A a 2 jobs actifs en 100, B 1 job `ready` en 100, 1 job `delivered` en 100, 1 job actif en 200 | `linesInUse = 3` |
| S1 avec un job `delivered` seul | `linesInUse = 0` (connu) |
| accès : A `granted`, B sans ligne ni job | `access` A `granted`, B `unknown` |
| projet P publié, plan en S1, U participant accepté | `projects` de S1 contient P |
| P terminé, plan en S1 | `projects` de S1 ne contient pas P |
| projet d'un autre utilisateur plan en S1, U non participant | absent |
| P en cours, ligne Tritanium requise 100, apports approuvés 60 | `reserved` 60 |
| P en cours, apport `pending` ou `rejected` de 40 | aucune ligne |
| P terminé, requis 100, apports 130 | `consumed` 100 et `returned` 30 |
| P terminé, requis 100, apports 100 | `consumed` 100, pas de `returned` |
| P terminé, surplus de production 10 | pas de ligne `returned` |
| non authentifié | 401 sur les deux ressources |

Front : un `unknown` d'index ou de lignes affiche le libellé d'inconnu ; « Créer un projet » pose l'intention de projet avec les runs suggérés ; un container `unknown` n'affiche pas de compte ; une déclaration refusée en 409 montre le message de l'API.

## Hors périmètre

Choisir ou verrouiller des stations (#1289, #1290) ; accès des autres membres ; stock corpo et stockpile comme origine d'un apport ; réservation de stock hors apport ; compétences et hangars partagés (pages existantes) ; vue par structure (#1313) ; liste de containers à déclarer (picker : l'item id se saisit à la main) ; retrait de la page Production.

## Glossaire

Ajout : **Usine** (Factory), **Lignes en service** (Lines in use), **Stock des projets** (Project stock : réservé, consommé, rendu).

## Décisions

D1. Page Production conservée ; Usine et stock est une nouvelle page (aucun retrait sans accord).
D2. Stock des projets au niveau du projet et des apports approuvés : le modèle n'enregistre ni l'origine d'un apport ni sa réservation physique ; écart au prototype (qui distingue stock perso, corpo, stockpile, BPO).
D3. Accès par personnage de l'utilisateur seulement : les comptes de station des autres membres restent réservés au propriétaire et à l'admin (#1299).
D4. Pas d'index ni de ligne inventés : `null` donne « inconnu ».
D5. Déclaration d'un container par item id, faute d'une liste de containers visibles côté API.
