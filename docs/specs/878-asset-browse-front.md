# #878 : inventaire en trois niveaux (lot 2, front)

Suite de `docs/specs/878-asset-browse-api.md` (lot 1, PR #960, fusionnée). Décision de Sylvain (2026-11-13) : résumé par lieu, contenu d'un lieu à l'ouverture, contenu d'un container à son ouverture, recherche côté serveur, même modèle pour la corporation et le personnage. PR empilée sur #964 (#890, volumes empaquetés) : `Assets.vue` y a changé.

## Périmètre

`Assets.vue` ne charge plus `GET {propriétaire}/assets` au chargement. Propriétaire = `/me/corporation/assets` ou `/me/characters/{id}/assets`. Les routes de liste complète restent (lot 1) et servent aux fonctions qui ne peuvent pas vivre sans elle.

## Règles

1. **Chargement** : `GET {propriétaire}/locations` (sans `divisionName`) alimente la liste des lieux, les statistiques (éléments, types, systèmes, lieux), la fraîcheur, `nextRefreshAt` (corporation), le graphique de répartition, les options Système, Lieu et Division. Aucun appel au contenu avant l'ouverture d'un lieu.
2. **Division** (corporation) : avec une division choisie, un second appel `…/locations?divisionName=` fournit lieux et statistiques ; les options Division restent celles du résumé sans filtre. Les lignes racines affichées sont filtrées par division côté front comme aujourd'hui.
3. **Ouverture d'un lieu** : `GET …/locations/{locationId}` (une fois par lieu et par chargement), lignes racines. Ouverture d'un container ou d'un vaisseau : `GET …/containers/{itemId}`, descendants directs. Une ligne avec `childCount > 0` est un container même sans contenu chargé.
4. **Ouverture par défaut** : lieux ouverts d'emblée seulement si le résumé compte 10 lieux au plus et `itemCount` ne dépasse pas `COLLAPSE_LOCATIONS_ROW_THRESHOLD` ; sinon tous repliés. Chaque lieu ouvert déclenche son appel.
5. **Recherche** (2 caractères au moins, après trim, délai de 300 ms) : `GET …/search?q=`. Lieux affichés = `locationIds` du résultat plus `rootLocationId` des hits. Les lieux hors `locationIds` s'ouvrent seuls (10 au plus, les autres restent repliés), leur contenu et celui des containers de `containerPath` se charge (20 containers distincts au plus, les autres restent ouvrables à la main) et ces containers s'ouvrent. La recherche ignore la casse et les accents (comme #882) : le dépôt compare avec `translate()` des lettres accentuées usuelles, sans extension Postgres. Limite : un asset sans nom de type n'est pas trouvé par son identifiant de type ni par le libellé « type inconnu » en vue Par lieu (trouvé en vue Par type, recherche locale). Dans un lieu trouvé par son nom, tout est affiché ; sinon seules les lignes hits, leurs containers de `containerPath` et, pour un container hit, tout son contenu. Moins de 2 caractères : filtre local des lieux par nom ou système. `truncated: true` affiche la note `assets.searchTruncated`. En vue Par type, la recherche reste locale (liste complète).
6. **Liste complète à la demande** (ancien appel, noté « Décisions ») pour les fonctions qui lisent toutes les lignes : vue Par type (au passage en Par type) ; sélection de lieux (résumé et volume de la sélection, barre affichée quand la liste est là, ligne `assets.selectionLoading` avant) ; volumes (en-têtes de lieu, containers, tri par volume) affichés seulement quand la liste est chargée, tri par volume la déclenche ; ouverture d'un container épinglé (`locateContainer`). Une fois chargée, les lieux s'ouvrent sans appel.
7. **Bloc Asset Safety** : calculé depuis le résumé (même regex que `summarizeAssetSafety`, `itemCount` et `shipCount` du résumé), fonction `summarizeAssetSafetyLocations`.
8. **Rechargement** (sync terminée, refresh, changement de division) : nouveau résumé, puis les contenus déjà chargés sont relus et remplacés d'un bloc ; liste, filtres et états d'ouverture restent (comportement #876).
9. **Changement de propriétaire** (personnage, vue) : tout est vidé, requêtes en cours annulées (`AbortController`).
10. **Erreurs** : résumé ou contenu en échec : `assets.loadFailed`, sans valeur par défaut. Requête abandonnée : ignorée.
11. **Propriétés des lignes** : `Asset` gagne `childCount?: number`. Les lignes de `items[]` et `hits[]` ont la forme de `Asset`.

## Exemples

| Situation | Résultat |
|---|---|
| Corporation, 85 lieux, 14 512 lignes | 1 appel `locations` au chargement, lieux repliés, 0 appel de contenu |
| Ouvrir « Jita IV » | 1 appel `locations/60003760`, ses lignes s'affichent ; le container « 1_Srek » (`childCount` 12) est ouvrable |
| Ouvrir « 1_Srek » | 1 appel `containers/{itemId}`, 12 lignes |
| Rouvrir un lieu déjà chargé | aucun appel |
| Saisir « 1_Srek » | après 300 ms, 1 appel `search`, seuls les lieux concernés restent, ouverts, container ouvert |
| Saisir « t » | aucun appel `search`, filtre local des lieux |
| Passer en Par type | 1 appel de liste complète, puis regroupement par type |
| Cocher un lieu | 1 appel de liste complète (une fois), barre de sélection avec résumé et volume |

## Cas limites

- Deux lieux de même nom : deux lignes (avant : fusionnées), repliés et sélectionnés ensemble (clé = nom).
- Résumé vide : état vide existant (sync en cours ou aucun asset).
- Réponse de recherche périmée (saisie plus récente) : ignorée.
- Un vaisseau avec `childCount > 0` propose l'ouverture avant chargement (pièces montées comprises) ; après chargement, sans contenu hors pièces montées, plus de bascule.
- Les compteurs de division du filtre passent de lignes à éléments (somme des quantités du résumé).

## Hors périmètre

Volume dans le résumé de l'API (lot ultérieur proposé, supprimerait la liste complète des en-têtes). Pagination d'un lieu énorme. Retrait des routes de liste complète. Normalisation des accents.

## Décisions prises en autonomie

- La liste complète reste appelée à la demande par : vue Par type, sélection, volumes, ouverture d'un container épinglé (chacune lit toutes les lignes ou leur chaîne de parents).
- Volumes des en-têtes, containers et tri par volume : disponibles après chargement de la liste complète seulement (le résumé n'a pas de volume).
- Compteurs de division en éléments, fusion des lieux homonymes abandonnée, bascule d'ouverture sur les vaisseaux avant chargement.
