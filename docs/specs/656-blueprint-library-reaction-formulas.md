# #656 : formules de réaction (BPR) dans la bibliothèque de blueprints

Circuit m, mode nuit, aucun sous-agent. Termes : [glossaire](../glossary.md) (Réaction, BPO, BPC, Bibliothèque de blueprints).

## Constat (vérifié dans le code)

- La sync (`BlueprintSyncService`, perso et corpo) ne filtre rien : tout item renvoyé par l'ESI `/blueprints` est stocké dans `OwnedBlueprint`, formules de réaction comprises (quantité -1).
- `BlueprintLibraryReader::rowOf` ne connaît que `bpo` (quantité -1) et `bpc` : une formule de réaction sort donc comme `bpo`, avec ME 0 et TE 0.
- Une formule de réaction est un blueprint qui a une ligne `IndustryActivity` d'activité `IndustryActivityType::Reaction` (11) dans le SDE. Vérifié en base locale : les 120 types des groupes 1888, 1889, 1890 et 4097 en ont une. Aucun groupId en dur : on réutilise `IndustryActivityRepository::findByTypeIdsAndActivityIds`.

## Règles

1. Une ligne dont le blueprint a l'activité de réaction a `kind = 'bpr'`, qu'elle soit perso ou corpo. Un BPC n'a jamais cette activité : le test est fait avant `bpo`/`bpc`.
2. Une ligne `bpr` garde `runs = null`, `count` comme un BPO, et ME/TE tels que stockés (0 de l'ESI). Le front ne les affiche pas.
3. Tri pour un même nom : BPO, puis BPR, puis BPC (puis ME décroissant comme avant).
4. Front : badge « BPR » (FR et EN), icône `bp` de l'image server, cellules ME/TE et runs en « — » au lieu de « ME 0 · TE 0 » et « ∞ ».
5. Une seule requête SDE par lecture de la bibliothèque (tous les typeIds d'un coup).

## Hors périmètre

Filtre par type (#657). `OriginalBlueprintFinder` et le plan de projet ne changent pas : une formule reste un original pour eux.

## Décisions

- Tri BPO, BPR, BPC : proposition de l'issue (« à valider »), prise telle quelle.
- ME/TE restent dans l'API (valeurs stockées) : seul l'affichage change.
