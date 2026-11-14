# #878 : inventaire en trois niveaux (lot 1, API)

Décision de Sylvain (2026-11-13, commentaire de l'issue) : trois niveaux, recherche côté serveur, même modèle pour les assets de personnage ; les nouvelles requêtes hydratent des tableaux, pas des entités (commentaire du 2026-11-14).

## Périmètre

Lot 1 : API et requêtes, corporation et personnage. Lot 2 (hors de cette spec, issue laissée ouverte) : câblage de `Assets.vue` (résumé d'abord, ouverture d'un lieu puis d'un container, recherche serveur, vue « par type », panneau d'épingles, sélection de lieux). Les routes `GET /me/corporation/assets` et `GET /me/characters/{characterId}/assets` restent, le front actuel les utilise.

## Vocabulaire (glossaire : Container, Division, Visibilité d'une division)

- **Portée** : l'ensemble des lignes `cached_assets` lisibles par l'appelant. Personnage : lignes non corporation du personnage (appartient à l'utilisateur, sinon 404). Corporation : lignes de la corporation du personnage principal dont le `location_flag` est `CorpSAG{N}` pour une division visible de l'appelant (`CorpAssetVisibility::divisionsVisibleTo()`), plus leur contenu en descendant la chaîne des containers (5 niveaux au plus, comme `findContentsOfContainers()`). Sans réglage de visibilité ou sans division visible : portée vide.
- **Ligne racine** : personnage, `location_type` différent de `item` ou parent absent des lignes du personnage ; corporation, ligne de division visible. Le **lieu** d'une ligne racine est son `location_id`.
- **Descendants** d'une ligne : lignes dont le `location_id` est son `item_id` avec `location_type = item`, récursivement.

## Routes (propriétaire = `/me/corporation/assets` ou `/me/characters/{characterId}/assets`)

1. `GET {propriétaire}/locations[?divisionName=]` : résumé, une ligne par lieu.
   Corps : `itemCount`, `typeCount`, `systemCount`, `locationCount`, `lastSyncedAt` (plus récent `cached_at`, `null` si portée vide), `locations[]` ; id (`characterId` ou `id: "corporation"`) ; corporation : `nextRefreshAt` en plus (`CorporationAssetsRefreshGate`).
   Chaque lieu : `locationId`, `locationType`, `locationName`, `solarSystemName`, `itemCount`, `shipCount`, `divisions[]` (`{name, count}`, corporation seulement, noms non nuls), `cachedAt`.
   - `itemCount` d'un lieu = somme des `quantity` des lignes racines et de leurs descendants ; au total, somme des lieux.
   - `shipCount` = somme des `quantity` des lignes racines singleton de catégorie SDE 6.
   - `divisions[].count` = même somme que `itemCount` ventilée par `division_name`, tri décroissant puis nom.
   - `typeCount` = types distincts (racines et descendants) ; `systemCount` = `solar_system_name` distincts non nuls des racines.
   - Lieux triés par `itemCount` décroissant puis nom. `divisionName` restreint aux lignes racines de ce nom de division (corporation).
2. `GET {propriétaire}/locations/{locationId}[?divisionName=]` : lignes racines du lieu, triées par nom de type. Corps : `locationId`, `items[]`. Lieu sans ligne dans la portée (inexistant, division masquée, autre propriétaire) : 404, même réponse dans les trois cas.
3. `GET {propriétaire}/containers/{itemId}` : descendants **directs** du container (un niveau ; un sous-container se rouvre par le même appel). Corps : `itemId`, `items[]`. Container hors portée (inexistant, chaîne de parents qui n'aboutit pas à une ligne racine de la portée, autre propriétaire) : 404.
4. `GET {propriétaire}/search?q=` : `q` de 2 caractères au moins après trim, sinon 400. Insensible à la casse (`ILIKE`, `%`, `_` et `\` échappés). Corps : `query`, `truncated`, `locationIds[]` (lieux dont le nom ou le système contient `q`), `hits[]`. Un hit est une ligne (racine ou descendante) dont `type_name` ou `item_name` contient `q`, avec `rootLocationId` et `containerPath[]` (`item_id` des containers de la racine au parent direct, vide pour une racine). 200 hits au plus, `truncated: true` au-delà.

## Forme d'une ligne (`items[]`, `hits[]`)

Les champs de `AssetItemResource` (même clés, même ordre), plus `childCount` (nombre de lignes descendantes directes, 0 sans contenu) ; un hit ajoute `rootLocationId` et `containerPath`. `categoryId` vient du SDE, `blueprint` est annoté comme dans la liste actuelle (`AssetBlueprintAnnotator`), `canResolveItemName` suit la règle de `CachedAsset::canResolveItemName()` (une seule implémentation partagée, pas de copie). `divisionName` rempli pour la corporation, `null` pour un personnage.

## Cas limites

- Deux jours de cache différents dans un lieu : `cachedAt` = le plus récent.
- Item dont le parent n'est pas en cache (personnage) : ligne racine, son `location_id` est le lieu.
- Cycle dans les données en cache : la descente s'arrête à 5 niveaux.
- JSON et JSON-LD donnent les mêmes clés, sans `@id`, `@type` ni `@context` (comme #874).
- Aucune lecture n'écrit en base, aucun appel ESI.

## Hors périmètre

Front (lot 2). Pagination d'un lieu énorme. Normalisation des accents dans la recherche (pas d'extension `unaccent` en base). Retrait des routes existantes (décision de Sylvain requise).

## Décisions prises en autonomie

- `itemCount` d'un lieu compte aussi les sous-containers de plusieurs niveaux (le front actuel ne compte que le premier niveau) : cohérent avec le nombre d'objets d'un container épinglé (#893).
- Recherche : un hit par ligne correspondante, le front reconstruit l'arbre avec `containerPath`.
