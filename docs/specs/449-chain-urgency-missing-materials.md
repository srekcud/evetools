# Spec #449 : vue d'ensemble de la chaîne triée par urgence, statut « Matériaux manquants »

Circuit l, mode nuit. Périmètre : `ProjectChainBuilder`, `GET /industry/projects/{projectId}/chain`, `ProductionChainFlow.vue`. Glossaire : « Chaîne de production », « Vue d'ensemble ».

## Règles

1. **Niveau de fabrication** d'un job de composant ou de produit final = 1 + le plus haut niveau de ses enfants fabriqués, 1 sans enfant. Enfants d'un type T : les lignes `component` dont `parentTypeId` = T (premier consommateur, seule relation que la BOM garde). Le niveau est porté par le type : toutes les lignes d'un même type (jobs découpés) partagent le niveau. Réaction = 1, composant qui consomme une réaction = 2, composant capital = 3. Aucune liste de groupes. Copie et invention : pas de niveau.
2. **Besoin d'un job en matériaux** = matériaux du job du plan du propriétaire (`ProductionPlanning::planWithStructuresOf` via `IndustryProjectService::planOf`, même plan que `buildBom`), restreints aux types qui ont une ligne matériau de la BOM (les intermédiaires fabriqués ne sont pas des matériaux fournis). Le job du plan est apparié à sa ligne par type, activité, groupe (composant ou final), ME, TE, puis par rang de runs décroissants ; un job dont les runs diffèrent de ceux de la ligne n'est pas apparié.
3. **Allocation** : la quantité fournie d'un matériau (`fulfilledQuantity`) est allouée aux jobs consommateurs par niveau croissant, puis nom du type, puis id de ligne ; chaque job reçoit `min(besoin, reste)`. Un job a ses matériaux fournis si chaque allocation couvre son besoin. Un job livré garde sa part dans l'allocation.
4. **Statut** d'un job composant ou produit sans job lié ni livraison : sous-composants non tous terminés → `blocked` (inchangé) ; sinon matériaux fournis → `todo` (« À lancer ») ; sinon `missing_materials` (« Matériaux manquants ») ; besoin inconnu (plan non calculable, job non apparié) → `unknown`. `done`, `active` et `unknown` par liens ESI inchangés. Les lignes copie et invention ne vérifient pas les matériaux (statut inchangé).
5. **Niveau d'un matériau** = niveau du premier consommateur (le plus bas niveau parmi les jobs qui le consomment), `null` si le besoin est inconnu. Exposé par le champ `level` du nœud (`null` pour copie, invention, vente).
6. **Tri** (dans chaque étape, l'étape reste blueprint, material, component, product) :
   - composants : statut (`todo`, `missing_materials`, `unknown`, `active`, `blocked`, `done`), puis niveau croissant, puis nom, puis id ;
   - matériaux : rien fourni (`todo`), partiellement fourni (`active`), terminé (`done`) ; puis niveau du premier consommateur croissant (`null` en dernier) ; puis part restant à fournir décroissante ; puis nom, puis id ;
   - blueprints et produits : nom puis id, inchangé.
7. **Front** : statut `missing_materials` avec puce, bord, point et entrée de légende dédiés (orange, distinct de `blocked` ambre) ; dans chaque colonne sauf Vente, les nœuds `done` sont regroupés sous un `<details>` « N terminés » en bas de colonne, fermé par défaut (cliquer un nœud ouvre toujours l'arbre).

## Exemples chiffrés

- Composant X (2 runs) besoin 100 Tritanium ; fourni 100 → `todo`. Fourni 99 → `missing_materials`.
- Tritanium fourni 150 ; réaction R (niveau 1) besoin 100, composant C (niveau 2) besoin 100 → R reçoit 100 (fourni), C reçoit 50 (manquant).
- Chaîne produit P ← composant capital K ← composant C ← réaction R : niveaux R=1, C=2, K=3, P=4.
- Matériaux : A (0/100, niveau 2), B (30/100, niveau 1), C (0/100, niveau 1), D (0/200, niveau 1) → D, C, A, B.

## Cas limites

- Plan non calculable (`\RuntimeException` de `planOf`) : tous les jobs composant et produit qui seraient `todo` passent `unknown`, jamais `todo` par défaut ; niveaux et tri des matériaux par part restante restent calculés.
- Besoin d'un type sans ligne matériau (acheté hors BOM) : ignoré.
- Cycle de parenté dans la BOM : `\UnexpectedValueException`.

## Hors périmètre

Arbre des jobs (#418), blueprint utilisé par un job en cours, recalcul de la BOM, persistance des besoins par job, affichage du niveau sur les cartes.

## Décisions prises en autonomie

Règle d'allocation de l'issue retenue telle quelle ; plan relu à la lecture comme `buy-or-build` ; colonne Vente non repliable ; `level` exposé sans affichage.
