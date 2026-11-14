# Spec #888 — Inventaire : ce qui a changé depuis la sync précédente

Décision de Sylvain (2026-11-13, commentaire de l'issue) : table de diff, dernière sync seulement, ni snapshot complet ni historique. Personnages et corporation couverts.

## Règles

1. **Diff** : à chaque sync d'assets réussie d'un propriétaire (personnage ou corporation), on compare les assets en cache avant la sync à ceux de la sync. Clé de comparaison : `(locationId, divisionName, typeId)`, quantités sommées par clé (une pile qui se scinde ou fusionne ne fait pas de bruit). Delta = quantité après − quantité avant ; une clé à delta 0 n'est pas écrite.
2. **Corporation** : seuls les assets d'une division (`divisionName` non null) sont comparés ; les autres ne sont jamais visibles dans l'inventaire.
3. **Pas de référence** : un propriétaire sans aucun asset en cache avant la sync (première sync) n'écrit rien : tout apparaîtrait comme ajouté.
4. **Dernier diff seulement** : un diff non vide remplace les lignes du propriétaire. Un diff vide ne remplace pas un diff non vide (deux rafraîchissements dans la fenêtre de cache ESI donneraient des assets identiques et effaceraient l'information) ; les lignes gardent leurs deux dates, le panneau reste vrai.
5. **Atomicité** : le diff est écrit dans le même `flush` que les nouveaux assets ; une sync en erreur n'écrit rien et garde le diff précédent.
6. **Lignes** : `typeId`, `typeName`, `locationId`, `locationName` (nom déjà résolu par la sync : « conteneur — station » pour un contenu), `divisionName`, `quantityDelta` signé, `syncedAt` (instant de la sync), `previousSyncedAt` (plus récent `cachedAt` des assets d'avant).
7. **Lecture personnage** : `GET /me/characters/{characterId}/assets/changes`, 404 si le personnage n'est pas à l'utilisateur.
8. **Lecture corporation** : `GET /me/corporation/assets/changes`, corporation du personnage principal ; 403 sans personnage principal ; seules les lignes dont la division est visible pour l'utilisateur (`CorpAssetVisibility::divisionsVisibleTo`, résolue en noms par `findDistinctDivisions`) sont renvoyées ; sans configuration de visibilité, aucune ligne.
9. **Réponse** : `{id, syncedAt, previousSyncedAt, total, changes: [...]}` triée par `locationName` puis `typeName` ; `syncedAt` et `previousSyncedAt` null quand il n'y a aucune ligne.
10. **Panneau** (`AssetChangesPanel`, vue Assets, vues personnage et corporation) : titre « Changes since the previous sync », intervalle des deux dates, nombre d'unités ajoutées et retirées, repliable (replié par défaut), lignes groupées par emplacement, `+ N Type` en vert et `− N Type` en rouge. Aucune ligne : message « No change recorded between the last two syncs » (plus rien si jamais de diff). Rechargé au changement de personnage ou de vue et à la fin de la sync (`syncCompleted` Mercure existant, topics `character-assets` et `corporation-assets`). Échec de lecture : message d'erreur du panneau, la vue continue.

## Exemples

| Avant | Après | Lignes écrites |
|---|---|---|
| Iridium Charge M ×10 dans le conteneur C | ×6 | `−4 Iridium Charge M` (C) |
| rien | 3 Drakes en station S, division 2 | `+3 Drake` (S) |
| 5 Tritanium en S, 5 en S après scission en 2 piles (3+2) | idem | aucune |
| Veldspar ×2 en station S (corp, hors division) | ×9 | aucune (règle 2) |

## Cas limites

- Même type dans deux conteneurs : deux lignes (clé par `locationId`).
- Type déplacé d'un conteneur à un autre : un retrait et un ajout.
- Nom de type absent d'un côté : le nom connu de l'autre côté est utilisé, sinon `typeName` null reste null.

## Hors périmètre

Historique au-delà de la dernière sync, notification, diff de valeur ISK, qui a déplacé quoi (non fourni par l'ESI), purge planifiée (une ligne par clé et par propriétaire seulement).

## Actions prod

Migration `asset_sync_changes` appliquée au déploiement. Aucune commande console. Le premier diff apparaît à la deuxième sync après le déploiement.
