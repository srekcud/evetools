# #343 (reliquat) — Bouton « Passer en achat / en fabrication »

Suite de `343-buy-or-build-intermediates.md` (PR #402 fusionnée). Décision : option A du Blocage du 2026-11-07, recommandation appliquée en mode nuit. Tranche : bascule d'un intermédiaire par la blacklist de type du projet.

## Règles

1. **Bascule** = `PATCH /api/industry/projects/{id}` avec `blacklistTypeIds` (champ existant, liste complète). Passer en achat : le type est ajouté ; passer en fabrication : il est retiré. La BOM est reconstruite par `IndustryProjectService::buildBom`. Aucun nouveau champ ni route.
2. **Statuts** : un PATCH qui n'envoie que `blacklistTypeIds` est permis dans tout statut sauf `completed` (400 « The blacklist of a completed project cannot be changed »). Un PATCH qui envoie `blacklistGroupIds` ou `blacklistCategoryKeys` hors brouillon reste refusé comme aujourd'hui (400 « The blacklist of a project can only be changed while it is a draft »). Le brouillon garde son comportement (#445) : une ligne qui porte un apport y est conservée, pas refusée.
3. **Hors brouillon** (`published`, `in_progress`, `selling`) : si la reconstruction doit retirer du plan une ligne qui porte un job lié, un achat ou un apport, le PATCH est refusé en 400 avec le nom du type (« The line "X" carries a linked job, a purchase or a contribution and cannot be removed from the plan »), rien n'est enregistré et la blacklist du projet reste inchangée. Contrôle fait avant toute modification des lignes. Une réservation ne bloque pas (supprimée avec la ligne).
4. **Comparaison** : `GET /buy-or-build` ajoute à chaque ligne `blacklisted` (bool). Les lignes actuelles : `false`. S'y ajoutent les types de `blacklistTypeIds` du projet qui seraient des intermédiaires fabriqués si on les retirait de la blacklist : plan recalculé avec la blacklist de type du projet moins ce type seul (groupes inchangés), ligne du comparateur de ce type, `blacklisted: true`. Un type de la blacklist qui n'est pas un intermédiaire (matière première) n'a pas de ligne ; un type blacklisté par groupe ou catégorie n'a pas de ligne (bascule par type impossible). Les lignes blacklistées suivent les lignes du plan, dans l'ordre de `blacklistTypeIds`.
5. **Accès** : inchangé (lecture : participant accepté ; PATCH : propriétaire ou admin, 403 sinon). Le plan reste celui du propriétaire.

## Front

Colonne d'action dans la carte `ProjectBuyOrBuild.vue` : bouton par ligne, visible si `canSwitch` (propriétaire ou admin et projet non terminé). Ligne `blacklisted: false` : « Passer en achat » ; ligne `blacklisted: true` : puce « acheté (blacklist) » et bouton « Passer en fabrication ». Le bouton est désactivé pendant l'appel ; une erreur 400 s'affiche dans la carte (rôle alert) avec le message du serveur. Succès : la comparaison est relue et la carte émet `switched` (la fiche relit la BOM, le cockpit et la chaîne). Les props `blacklistTypeIds` viennent de la fiche projet. Libellés FR et EN.

## Cas limites

- Deux basculements enchaînés : la liste envoyée part toujours de `project.blacklistTypeIds` à jour (la fiche est mise à jour par `updateProject`).
- Type blacklisté dont le plan sans blacklist ne le fabrique plus (BOM changée entre-temps) : pas de ligne, la blacklist garde le type.
- Aucun type blacklisté : aucun plan supplémentaire calculé.

## Tests existants modifiés

- `UpdateProjectProcessorTest::testTheBlacklistOfAProjectThatIsNotADraftIsRefusedAndNothingIsChanged` : le cas `completed` (types seuls) change de message ; les cas avec groupes ou catégories restent identiques.
- `ProjectBuyOrBuildApiTest::ROW_KEYS` : ajoute `blacklisted`.

## Hors périmètre

Bascule d'un type blacklisté par groupe ou catégorie, bascule groupée, valorisation du surplus.
