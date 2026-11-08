# 418 — Arbre des jobs : liste à plat ordonnée par niveau de fabrication

Issue #418 (group:unified-industry, circuit:l). Suite de #337 et #416, agrégation de #69 (une ligne de BOM par type).

## Périmètre

La vue « Arbre des jobs » de l'onglet Chaîne (`ProductionChainJobTree.vue`) devient une liste à plat, ordonnée du niveau 1 (le plus bas) au plus haut. Le niveau est calculé côté API et porté par chaque nœud de `GET /industry/projects/{projectId}/chain`.

## Règles

1. **Niveau d'un job** : niveau 1 si aucun de ses matériaux n'est lui-même fabriqué ou réagi par un autre job du plan ; sinon 1 + le plus haut niveau parmi ses matériaux fabriqués. Les matériaux achetés ne comptent pas.
2. **Un type = un niveau** : un intermédiaire utilisé par plusieurs consommateurs porte un seul niveau, celui de ses propres intrants. Plusieurs jobs du plan sur un même type donnent le même niveau.
3. **Cible** : le produit final a son niveau calculé comme les autres ; avec plusieurs cibles, chacune garde le sien (le produit n'est donc le dernier niveau que s'il consomme tout).
4. **Nœuds blueprint** (copie, invention) : même niveau que le type dont ils sont le blueprint, listés juste avant lui.
5. **Nœud matériau et nœud vente** : `level` = null, jamais listés dans la vue (la vue d'ensemble les garde).
6. **Niveau inconnu** : si le plan ne se calcule pas (`RuntimeException` de `IndustryProjectService::planOf`), ou si le type d'un job est absent du plan, `level` = null sur ces nœuds. La chaîne et la vue d'ensemble restent servies. Le front les range sous un en-tête « Niveau inconnu » avec sa cause, en dernier. Jamais de niveau par défaut.
7. **Ordre dans un niveau** : nom du type, puis blueprint avant le job.
8. **En-tête** : une ligne d'en-tête par niveau, « Niveau N », avec le nombre de jobs ; aucune ligne d'en-tête pour un niveau vide.
9. **Colonnes** : inchangées (élément, activité, runs, ME/TE, personnage, statut, coût réel, fin, E/S, structure).
10. **Plus de repli** : l'indentation et les boutons de repli disparaissent (la liste est à plat).
11. **Ouvrir dans l'arbre des jobs** (vue d'ensemble → clic sur un nœud) : bascule sur la vue Arbre, marque la ligne `aria-selected="true"` et la fait défiler dans la vue (`scrollIntoView`). Un nœud matériau ou vente n'a pas de ligne : rien n'est sélectionné.

## Exemples

| Plan | Niveaux |
|---|---|
| Produit P (matériaux achetés seulement) | P = 1 |
| P ← C ← S (C fabriqué avec S, S avec des achats) | S = 1, C = 2, P = 3 |
| P utilise A (niveau 1) et B (B utilise A) | A = 1, B = 2, P = 3 ; A listé une fois |
| P utilise A directement et via B, B utilise A | A = 1, B = 2, P = 3 |
| Deux cibles P1 (achats seuls), P2 (utilise P1) | P1 = 1, P2 = 2 |
| Plan non calculable | tous les `level` des jobs = null, en-tête « Niveau inconnu » |

## Cas limites

- Cycle de matériaux dans le plan : `\LogicException` (impossible en EVE, jamais masqué).
- Nom de type très long : tronqué comme aujourd'hui (`truncate`, titre).
- Statut, coût réel et E/S : inchangés par nœud (E/S reste « inconnu » avec sa cause).

## Hors périmètre

- Ne retire rien : la vue d'ensemble, les colonnes, `parentId` du nœud et la clé i18n `chain.toggle.*` restent (clé devenue inutilisée, question dans « Décisions »).
- Pas de nouveau topic Mercure : le niveau est relu avec la chaîne, déjà rafraîchie sur `/user/{id}/sync/industry-project`.
- Pas de colonne ni de migration : niveau calculé à la lecture, sur le plan du propriétaire (même source que acheter ou fabriquer, #343).

## Décisions prises en autonomie

- Niveau = plus long chemin depuis les matériaux achetés (définition de l'issue), pas la profondeur depuis le produit.
- Calcul à la lecture sur le plan, car la BOM ne garde que le premier consommateur (`parentTypeId`) et ne suffit pas pour un intermédiaire multi-branches.
- Plan non calculable : état « inconnu » visible plutôt que 422 : un 422 casserait aussi la vue d'ensemble.

## Fichiers

Prod : `src/Service/Industry/Project/ProjectChainLevels.php` (nouveau), `ProjectChainNode.php` (`level`), `ProjectChainBuilder.php` (`levelByTypeId`), `ProjectChainProvider.php`, `frontend/src/components/group-industry/ProductionChainJobTree.vue`, `ProductionChain.vue` (sélection), `stores/group-industry/{types,chain}.ts`, locales fr et en.
Tests : `ProjectChainLevelsTest`, `ProjectChainBuilderTest`, `ProjectChainProviderTest`, `ProjectChainApiTest`, `productionChain.spec.ts`, `productionChainIcons.spec.ts`, `groupChain.spec.ts`.
