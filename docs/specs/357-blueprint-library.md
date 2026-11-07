# Spec #357 — Bibliothèque de blueprints personnels et corpo (tranche 1)

Maquette : `industry-project-v3.html#p=30`. Circuit M, mode nuit. Issue partielle : la recherche ME/TE « seulement si rentable » est hors tranche (Blocage au journal : aucun modèle de coût ni de gain de recherche dans le code, règle de rentabilité à décider).

## Vocabulaire (glossaire)
**BPO** : blueprint original (ESI `quantity` ≠ −2). **BPC** : copie (`quantity` = −2, `runs` ≥ 1). **Bibliothèque** : l'ensemble des blueprints possédés vus par un utilisateur, personnels et corpo.

## Données
R1. Entité `OwnedBlueprint` (table `owned_blueprints`) : `itemId`, `typeId`, `locationId`, `locationFlag`, `quantity`, `materialEfficiency`, `timeEfficiency`, `runs` (−1 pour un BPO), `character` (nullable, suppression en cascade) ou `corporationId` (nullable) : exactement un des deux, `divisionNumber` (nullable, corpo seulement), `syncedAt`. Un BPO est `quantity !== -2`. Migration irréversible `Version20261107…`.
R2. Division d'un blueprint corpo : drapeau `CorpSAG1`…`CorpSAG7` donne 1…7. Sinon le blueprint est dans un conteneur : on lit le drapeau du conteneur dans les assets corpo en cache (`CachedAsset` d'`itemId` = `locationId`), un seul niveau. Division introuvable : `null`, blueprint masqué par R7 (jamais supposé visible).

## Lecture ESI
R3. `BlueprintsService` (`src/Service/ESI/`) : `GET /characters/{id}/blueprints/` et `GET /corporations/{id}/blueprints/`, toujours `EsiClient::getPaginated`. Une ligne ESI incomplète (clé absente) lève une `EsiApiException`, jamais une valeur par défaut.
R4. Personnel : chaque personnage de l'utilisateur dont le jeton porte `esi-characters.read_blueprints.v1`. Corpo : un personnage de la corporation dont le jeton porte `esi-corporations.read_blueprints.v1`, de préférence le directeur qui a réglé la visibilité des divisions (`CorpAssetVisibility::getConfiguredBy`), sinon n'importe quel personnage de la corporation avec ce scope. Un 403 de l'ESI (pas Director) n'efface rien : le cache précédent reste.
R5. Remplacement : les lignes d'un personnage (ou d'une corporation) ne sont supprimées qu'après une lecture ESI réussie, puis remplacées en un flush.

## Synchronisation
R6. `BlueprintSyncService::syncUserBlueprints(User)` : personnages puis corporation de chaque personnage de l'utilisateur éligible (une corporation une seule fois). Mercure : type `blueprints` (`syncStarted`, `syncProgress`, `syncCompleted` avec `characters`, `corporations` et `failedCharacters` au format de #197 par `CharacterSyncFailure`, `syncError` si tout échoue) ; topic `/user/{userId}/sync/blueprints` déclaré dans `MercurePublisherService::getTopicsForUser`. Planification : message `SyncBlueprints` toutes les heures (`SyncScheduler`), un `SyncUserBlueprints` par utilisateur actif (comme les jobs industrie), `SyncTracker` clé `blueprints`. `POST /api/industry/blueprints/refresh` (EmptyInput) met `SyncUserBlueprints` en file et répond `{status: 'pending'}`.

## API
R7. `GET /api/industry/blueprints` (ressource `BlueprintLibraryResource`, Provider, authentifié) renvoie les blueprints de l'utilisateur (tous ses personnages) et ceux de la corporation de son personnage principal **dont la division est dans `CorpAssetVisibility::getVisibleDivisions`** ; sans réglage de visibilité ou sans division visible : aucun blueprint corpo. Chaque ligne : `typeId`, `name` (SDE, nom de type jamais deviné : absent = `unknown_type`), `kind` (`bpo` ou `bpc`), `me`, `te`, `runs` (`null` pour un BPO), `scope` (`personal` ou `corporation`), `ownerName` (nom du personnage, ou de la corporation s'il est connu, sinon `null`), `locationId`, `divisionNumber` (`null` en personnel), `count` (`quantity` positive d'une pile de BPO neufs, sinon 1). Tri : nom, puis BPO avant BPC, puis ME décroissant.
R8. Champ `corporationState` : `known` (un personnage de la corporation a le scope), `no_director` (aucun personnage de la corporation avec le scope : les blueprints corpo sont inconnus, la liste personnelle reste servie), `no_visibility` (scope présent mais aucune division visible). Le main sans corporation n'existe pas : le main est requis (403 « No main character set »).
R9. Aucune donnée d'un autre utilisateur ni d'une autre corporation. Les personnels sont ceux des personnages de l'utilisateur uniquement.

## Projet
R10. `OriginalBlueprintFinder::typeIdsOwnedBy(User)` : typeIds des **BPO** (R1) de l'utilisateur, plus ceux de sa corporation dans une division visible (R7). `IndustryProjectService::copyLinesOf` n'ajoute pas la ligne de copie d'un produit dont le blueprint producteur est dans cet ensemble (le propriétaire du projet est l'utilisateur). Un BPC seul ne retire rien. Les autres lignes (jobs, matériaux) sont inchangées. Le plan ne change pas (plafonds de runs de BPC conservés, décision de la tranche).
R11. Le front du projet ne change pas : la ligne de copie disparaît de la BOM, donc de la chaîne et du cockpit existants.

## Exemples
| Cas | Résultat |
|---|---|
| Personnage : BPO Drake ME 10 TE 20 (`quantity −1`, `runs −1`) | `bpo`, `runs` null, me 10, te 20, `personal` |
| Corpo : BPC Hammerhead II (`quantity −2`, `runs 50`), `CorpSAG3`, division 3 visible | `bpc`, 50 runs, `corporation`, division 3 |
| Même BPC en `CorpSAG5`, division 5 non visible | absent de la réponse |
| Blueprint corpo dans un conteneur dont la division est inconnue | absent |
| Aucun personnage de la corporation avec le scope corpo | `corporationState: no_director`, liste personnelle seule |
| Produit dont le BPO est possédé | BOM sans ligne `copy` de ce produit |
| Produit dont seul un BPC est possédé | ligne `copy` conservée |

## Cas limites
- ESI en 403 pour la corporation : cache gardé, `failedCharacters` rempli, pas d'exception pour l'utilisateur.
- Jeton révoqué ou sans scope : personnage ignoré (pas d'erreur), pas listé comme échec.
- Pile de BPO neufs `quantity` > 0 : un BPO, `count` = quantité.

## Hors tranche
- Écran « Blueprints » (liste, filtres Tous/Personnels/Corpo, bannière sans directeur), proposition de recherche ME/TE rentable, ME/TE possédés injectés dans le plan, plafond de runs d'un BPO, état « utilisé par un job en cours ».

## Actions de production
Migration au déploiement ; ensuite un utilisateur doit se reconnecter si ses jetons n'ont pas les deux scopes `read_blueprints` (déjà dans `REQUIRED_SCOPES`). Le premier cycle de sync (1 h) ou `POST /industry/blueprints/refresh` remplit la bibliothèque.
