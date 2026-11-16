# #996 Tri des items dans un lieu ou un conteneur

Front seul : le serveur garde `ORDER BY type_name ASC, item_id ASC` (ordre « nom »), aucun changement d'API, de migration ni de chemin d'accès. Vue personnage et vue corporation.

## Règles

1. Un second sélecteur « Items » (`data-testid="item-sort"`) à côté du tri des lieux : `name` (défaut), `quantity`, `volume`. Le tri des lieux (`location-sort`) ne change pas.
2. `volume` n'est proposé que si le catalogue des volumes reconditionnés est chargé (`packagedVolumeCatalog !== null`), comme le tri de volume des lieux. Sans catalogue (ou liste complète non chargée), un tri `volume` venu de l'URL retombe sur l'ordre `name`.
3. Le tri s'applique aux items d'un lieu (hors groupes de vaisseaux) et au contenu d'un conteneur déplié ; les deux niveaux utilisent le même tri.
4. `name` : ordre reçu du serveur, inchangé.
5. `quantity` : quantité décroissante ; égalité : ordre `name`.
6. `volume` : volume décroissant d'un item = `summarizeVolume([item], contenuComplet, catalogue).cubicMeters` (un conteneur compte son contenu, comme son badge) ; égalité : ordre `name`.
7. Les items épinglés par un projet restent en tête quel que soit le tri, triés entre eux par le tri choisi. Les épingles utilisateur ne changent pas l'ordre.
8. Les groupes de vaisseaux (`AssetShipGroups`) ne sont pas touchés.
9. URL (`assetFilterQuery`) : clé `itemSort`, écrite seulement si différente de `name`, valeur inconnue lue comme `name`. Le tri des lieux garde la clé `sort`.

## Exemples

| Items (type, quantité, volume unitaire m³) | Tri | Ordre |
|---|---|---|
| Tritanium 100 (0,01), Rifter 1 (2 500), Pyerite 5 000 (0,01) | `name` | Pyerite, Rifter, Tritanium (ordre serveur) |
| idem | `quantity` | Pyerite, Tritanium, Rifter |
| idem | `volume` | Rifter (2 500), Pyerite (50), Tritanium (1) |
| Conteneur de 3 Rifter + Tritanium 100 000 (1 000 m³) | `volume` | le conteneur (7 500) avant Tritanium (1 000) |

## Hors périmètre

Tri côté serveur, tri par valeur ISK, groupes de vaisseaux, vue par type.

## Décisions de nuit

Clé d'URL `itemSort` (la clé `sort` est déjà celle des lieux). Pas de libellé de tri existant retiré.
