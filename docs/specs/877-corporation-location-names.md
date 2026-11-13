# #877 — Corporation locations stored without a usable name

Circuit M, mode nuit (spec validée en autonomie). Backend `AssetsService`, front `Assets.vue`.

## Règles

1. Un lieu (station ou structure) dont le nom ESI/SDE est vide ou blanc n'est jamais stocké vide : il devient `Unknown structure (<système>)`, ou `Unknown structure` si le système est inconnu. Pour un id de station NPC : `Unknown station (<système>)`.
2. Une Asset Safety Wrap (conteneur dont `location_flag` vaut `AssetSafety`) dont l'origine est inconnue (nom de racine `Structure #<id>` ou `Unknown structure…`) et dont le système est connu est nommée `Asset Safety (<système>)`. Origine connue : inchangé, `Asset Safety Wrap — <origine>`.
3. Un Office dont la structure n'a pas de nom : `Office — Unknown structure (<système>)`, jamais `Office — `.
4. Un objet posé directement dans une structure sans nom : `Unknown structure (<système>)`.
5. Hors changement : `Structure #<id>` reste le nom stocké d'une structure non résolue (le cache structures et `ProfitMarginService` s'appuient sur ce préfixe) ; `Office — Structure #<id>` reste tel quel.
6. Front, filtre Lieu : les lieux `Structure #<id>` et `Location #<id>` restent listés. Libellé de l'option : `Structure inconnue (<système>)` (sans système : `Structure inconnue`), `Lieu inconnu (<système>)` pour `Location #`. La valeur de l'option reste le `locationName` brut (unique).
7. Front, en-tête de groupe : badge `data-testid="location-badge"` avec le motif en tooltip (`title`) : `Structure non résolue` (nom contenant `Structure #<id>` ou `Location #<id>`) ou `Nom manquant` (nom contenant `Unknown structure`, `Unknown station`, ou commençant par `Asset Safety (`). Un lieu normal n'a pas de badge.

## Cas limites

- Un nom de structure en cache base à `''` est traité comme blanc (règle 1), pas comme résolu.
- Aucune migration, aucune commande prod : les noms se corrigent à la prochaine sync des assets corporation.

## Hors périmètre

Retry de résolution des structures, filtre par système, API.
