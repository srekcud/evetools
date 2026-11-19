# Triage : l'arbre des jobs plante au changement de vue (2026-11-07)

## Cause (vue sur des données locales, projet de 163 nœuds)

- `src/ApiResource/Industry/Project/ProjectChainResource.php:14-32` ne déclare pas `normalizationContext: ['skip_null_values' => false]`, contrairement à `ProjectPlanningResource.php:31` et `ProjectStructuresResource.php:31`. API Platform omet donc les champs `null` de `ProjectChainNode` (`src/Service/Industry/Project/ProjectChainNode.php`).
- Réponse réelle de `GET /api/industry/projects/{id}/chain` : aucun `null`. `plannedStructure` est absent sur les matériaux, les blueprints et la vente ; `parentId`, `activity`, `runs`, `meLevel` et `teLevel` sont absents sur les matériaux.
- `frontend/src/components/group-industry/ProductionChainJobTree.vue:218` teste `plannedStructure !== null` (vrai pour `undefined`), puis la ligne 222 lit `.name` : `TypeError` sur la première ligne de matériau (racine de l'arbre, `ProjectChainBuilder.php:143-157`). Les lignes 168 et 210 (`activity !== null`) afficheraient aussi `activity.undefined` et un faux « E/S inconnu ».
- La vue d'ensemble ne lit jamais `plannedStructure`, d'où un plantage limité à l'arbre. Trace annexe : `progressPercent` donne `NaN` sur le nœud de vente.

## Pistes écartées

- Ids en double : ce sont les UUID des lignes de BOM (`ProjectChainBuilder.php:430-433`).
- Cycle de `parentId` : aucun sur les données locales ; `visit` part des racines. Risque résiduel de boucle dans `unfoldAncestorsOf` (`ProductionChainJobTree.vue:87`) seulement en cas de cycle.
- Clés i18n et statuts : complets.

## Pourquoi les tests n'ont rien vu

- `frontend/tests/productionChainNodes.ts:7-24` envoie des `null` explicites, jamais des clés absentes.
- `tests/Functional/Api/Industry/Project/ProjectChainApiTest.php:124-128` lit `$node['parentId'] ?? null`, qui accepte un champ absent.

## Tests qui le prouveraient

1. Fonctionnel : `assertArrayHasKey('plannedStructure', $materialNode)` puis `assertNull`, idem `parentId`.
2. Vitest : monter `ProductionChain` avec un nœud matériau sans clé `plannedStructure`, cliquer sur `chain-view-tree`, vérifier le rendu de la ligne.
