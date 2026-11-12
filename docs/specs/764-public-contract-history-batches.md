# #764 — Historique des contrats publics : relevé par lots

Mode nuit : spec validée en autonomie (circuit:m).

## Contexte

En prod, le worker meurt sur `Allowed memory size of 469762048 bytes exhausted` (Doctrine `UnitOfWork`,
`RawValuePropertyAccessor`) pendant `SyncPublicContracts` (3 crashs, puis rejet après 3 retries).

Cause, vérifiée dans le code : `PublicContractHistoryRecorder::record()` (appelé par
`SyncPublicContractsHandler::recordHistory()`, une fois par région) :
1. charge **toutes** les `PublicContractListing` non conclues de la région (`findUnconcludedInRegion`) comme
   entités gérées, avec leurs `items` JSON ;
2. persiste toutes les nouvelles annonces ;
3. ne fait **qu'un seul** `flush()` : le `UnitOfWork` garde donc toutes les entités et calcule le changeset de toutes.

Le `SyncTracker` et `DbalSyncRunLog` n'utilisent ni entité ni `EntityManager` : aucune référence à protéger.

## Règles

1. `record()` garde exactement le même résultat en base qu'aujourd'hui (voir tableau), mais le traite par lots de
   `BATCH_SIZE` annonces (constante `1000`, aussi valeur par défaut du paramètre de constructeur `int $batchSize`,
   injectable pour les tests).
2. Annonces suivies (non conclues de la région) :
   - nouvelle méthode `PublicContractListingRepository::findUnconcludedContractIdsInRegion(int $regionId): list<int>`
     (requête scalaire sur `contractId`, aucune entité hydratée) ;
   - les ids sont lus une fois en tête de `record()`, puis relus par lots de `BATCH_SIZE` avec
     `findByContractIds()` ; pour chaque annonce : `seenAgain($now)` si son id est dans `$listedContractIds`,
     sinon `missed($now)` ;
   - `flush()` puis `clear()` de l'`EntityManager` après **chaque** lot.
3. Contrats lisibles non suivis (absents de la liste des ids suivis) : par lots de `BATCH_SIZE` contrats, avec
   pour chaque lot : recherche des annonces déjà connues (conclues) via `findByContractIds()` → `seenAgain($now)` ;
   sinon, si `price > 0` et `items` non vide, `persist(PublicContractListing::firstSeen(...))` ; puis `flush()` et
   `clear()` après chaque lot.
4. Aucune entité n'est gardée par référence d'un lot à l'autre : seuls des ids (`int`) et les `ObservedContract`
   (objets valeur) traversent un `clear()`.
5. Le nombre d'entités gérées, mesuré à chaque `flush`, ne dépasse jamais `BATCH_SIZE`.
6. `findUnconcludedInRegion()` reste en place (testée par `PublicContractListingRepositoryTest`) : non retirée.
7. `purgeExpiredHistory()` et `SyncPublicContractsHandler` ne changent pas.

## Exemples chiffrés (région 10000002, `batchSize` 10 dans le test d'intégration)

| Situation de départ | Contrats listés (lisibles) | Résultat en base |
|---|---|---|
| 25 annonces suivies, toutes listées | les 25 | 25 annonces : `lastSeenAt = now`, `missedPolls` = 0 |
| 25 annonces suivies, 5 absentes de la liste | 20 | 20 vues à nouveau, 5 avec `missedPolls` + 1 (une 2e absence consécutive la conclut) |
| aucune annonce, 35 contrats lisibles (`price > 0`, objets présents) | 35 | 35 nouvelles annonces `firstSeen` |
| 1 annonce conclue + 1 contrat lisible du même id | 1 | l'annonce conclue passe en `seenAgain`, pas de doublon |
| 35 contrats lisibles dont 1 à `price = 0` et 1 sans objets | 35 | 33 nouvelles annonces |
| 25 suivies + 35 nouveaux | 60 | 60 annonces au total, 6 flushs au plus par phase (4 nouveaux lots) |

Mesure : sur 25 suivies + 35 nouveaux avec `batchSize` 10, le maximum de `UnitOfWork::size()` observé à
`onFlush` est ≤ 10, alors qu'avant le correctif il valait 60.

## Cas limites

- Liste de contrats vide, aucune annonce suivie : aucun flush nécessaire, aucune erreur.
- Annonce suivie dont l'id est dans la liste mais dont le contrat n'est pas lisible (items non lus) : `seenAgain`
  (comportement actuel, `$listedContractIds` couvre tous les contrats listés).
- Une annonce suivie disparue entre la lecture des ids et le `findByContractIds()` d'un lot (purge) : ignorée.
- Dernier lot incomplet : flush et clear aussi.

## Tests existants modifiés

- `tests/Unit/Service/Industry/ContractSales/PublicContractHistoryRecorderTest.php` : son stub de dépôt fournit
  `findUnconcludedInRegion`, le recorder lira désormais `findUnconcludedContractIdsInRegion` + `findByContractIds` ;
  adapter le stub (annonces suivies et conclues servies par `findByContractIds`), assertions métier inchangées.
- `tests/Integration/Repository/PublicContractListingRepositoryTest.php` : ajouter un cas pour la nouvelle méthode
  (région filtrée, annonces conclues exclues, ids entiers) ; le cas existant ne change pas.

## Tests à écrire

- Unit : `record()` appelle `clear()` après chaque `flush()` ; le nombre de `persist` entre deux `flush` ≤ `batchSize` ;
  garde : le résultat reste celui des tests existants.
- Integration (`tests/Integration/Service/Industry/ContractSales/`) : le scénario « 25 suivies + 35 nouveaux »
  ci-dessus avec un `batchSize` de 10, base réelle, écouteur `onFlush` qui relève `UnitOfWork::size()` ; vérifier les
  comptes de lignes du tableau et le maximum ≤ 10.

## Hors périmètre

- `AssetsSyncService::syncCorporationAssets` : même motif (persist de tous les `CachedAsset`, un seul flush) mais
  aucune preuve de crash, et un lot flush+clear y change deux garanties à trancher par Sylvain (l'atomicité du
  remplacement après le `deleteByCorporationId`, et le `clear()` qui détache le `Character` du appelant). Ligne
  « Décisions » du journal : issue séparée proposée.
- Mémoire des tableaux ESI bruts du handler (`pollRegion`, `fetchContractItems`) : non touchée, pas de preuve.
- Rejouer ou purger la file `failed` (1413 messages) : action Sylvain au journal.
- Aucune migration, aucun réglage retiré.
