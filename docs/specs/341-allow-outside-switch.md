# #341 (reliquat) — Bouton « Autoriser pour ce projet »

Suite de `341-structure-total-cost.md` (option du projet, PR #382) et `341-lot2-structures-table.md` (carte Structures, PR #406). Recommandation du Blocage de #343 appliquée : même mécanisme que la bascule acheter ou fabriquer (PATCH, reconstruction de la BOM, refus si une ligne travaillée disparaît).

## Règles

1. **PATCH** `/api/industry/projects/{id}` accepte `acceptsOutsideFavoriteSystem` (bool) et `minimumOutsideSaving` (float, ≥ 0, `Assert\Range`). Absents : option inchangée, BOM non reconstruite.
2. **Statuts** : permis en brouillon, publié, en cours, en vente. Projet terminé : 400 « The structure policy of a completed project cannot be changed », rien n'est enregistré.
3. **Reconstruction** : si une valeur envoyée diffère de l'actuelle, la BOM est reconstruite par `IndustryProjectService::buildBom` avec `refuseToDropWorkedLines = (statut ≠ brouillon)`. Valeurs identiques à l'actuelle : aucune reconstruction.
4. **Refus** : si la reconstruction lève `\RuntimeException` (ligne portant un job lié, un achat ou un apport), 400 avec le message du service, les deux champs restaurés, aucun flush.
5. **Accès** : inchangé (propriétaire ou admin, 403 sinon). Pas d'action asynchrone, aucun topic Mercure.
6. **Front** : `ProjectStructures.vue` reçoit `canSwitch` (propriétaire ou admin, projet non terminé) et émet `switched`. Dans le bandeau d'un groupe : `onlyFavoriteSystem` porte le bouton « Autoriser pour ce projet » (PATCH `acceptsOutsideFavoriteSystem: true`) ; `belowThreshold`, `notRetained` et `byTotalCost` portent « Revenir au système préféré » (PATCH `false`). Bouton désactivé pendant l'appel ; erreur du serveur affichée dans la carte (rôle alert). Succès : structures relues, `switched` émis (la fiche relit BOM, cockpit et chaîne comme pour la bascule de #343). Sans `canSwitch` : aucun bouton. Libellés FR et EN.

## Hors périmètre

Modifier le seuil `minimumOutsideSaving` depuis l'écran (le PATCH l'accepte) ; barème de transport et EIV à confirmer en jeu ; une seule reconstruction quand blacklist et option sont envoyées ensemble (deux reconstructions, le front n'envoie jamais les deux).
