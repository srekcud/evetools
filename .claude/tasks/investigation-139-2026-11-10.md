# Investigation #139 : blacklist industrie appliquée ou ignorée selon l'écran

Date : 2026-11-10, base origin/main `af3b30ad`. Lecture seule, 0 sous-agent. La décision de Sylvain (commentaire du 2026-10-22) fixe la règle : la blacklist s'applique partout sauf Buy vs Build ; une catégorie est cochée seulement si tous ses groupes sont blacklistés, état intermédiaire si partielle.

Les numéros de ligne de l'issue sont périmés (Stockpile refondu sur le noyau, #69, 2026-10-28). Lignes ci-dessous relues sur origin/main.

## 1. Écrans et services, état réel

| Écran | Preuve | Comportement | Écart avec la décision |
|---|---|---|---|
| Projet (BOM, liste d'achats, jobs) | `IndustryProjectService.php:271-285` | blacklist **du projet** (copie prise à la création, pré-cochée depuis les réglages) passée à `ProductionPlanRequest` | aucun |
| Profit Margins | `ProfitMarginService.php:129-139` | blacklist **vivante** de l'utilisateur dans `ProductionCostRequest` | aucun |
| Buy vs Build | `BuyVsBuildService.php:92-103`, `:230-240` | l'utilisateur ne sert qu'aux structures ; chaque composant est passé en `boughtTypeIds` ; message `BuyVsBuildTab.vue:316` (`industry.scanner.buyVsBuild.blacklistNotApplied`) ; test `BuyVsBuildServiceCharacterizationTest.php:288` | aucun (voulu) |
| Stockpile : aperçu, import, cibles | `PlansStockpileImports.php:51-56` : `ProductionPlanRequest([$target], structures, FavoriteSystems::none(), startingStock:)`, pas de `blacklist` ; appelé par `StockpileService.php:369` (`stockpileTargetsOf`) | tout composant fabricable est développé : intermédiaires et matières de leur sous-arbre deviennent des cibles | **écart** |
| Stockpile : réduction par le stock de départ | `StockpileStartingStockReducer.php:68` et `:81` : deux `planOfImport`, même absence | plan complet et plan réduit calculés sans blacklist | **écart** |
| Scanner par lots, Opportunités, Pivot | aucune occurrence de `blacklist` (`BatchProfitScannerService`, `PivotAdvisorService`, `Opportunities/`) | coût sur un seul niveau de matériaux, aucune récursion de fabrication à filtrer | hors sujet : grep `ProductionPlanning`, `ProductionPlanner`, `ProductionCostCalculator`, `recipeFor` vide dans ces trois fichiers |

Mécanisme commun : `ProductionPlanner::buildableRecipe` (`ProductionPlanner.php:118-125`) renvoie `null` pour un type blacklisté, qui devient alors une feuille. La **cible** n'est jamais filtrée (`recipeOfTarget`, `:112-116`) : importer en Stockpile un produit blacklisté lui-même reste possible, donc le correctif ne casse pas ces imports.

La doc consigne l'écart comme un fait : `docs/glossary.md:47` (« le Stockpile ... sans blacklist ») et `docs/modules/industry.md:77` (« aucune blacklist »), `:112`. À corriger avec le code.

## 2. Règle ALL ou ANY des catégories

- `IndustryBlacklistService.php:124-126` : commentaire « ALL », code `!empty(array_intersect(...))` = **ANY**. Le champ `blacklisted` d'une catégorie est donc vrai dès qu'un seul de ses groupes est blacklisté.
- Une seule catégorie a plusieurs groupes : `biochem_reactions` = `[661, 662]` (`:63-67`). Les neuf autres n'en ont qu'un, ALL et ANY y sont identiques.
- Le contrat API ne porte aucun état partiel : `BlacklistResource.php` expose `categories` (clé, libellé, `groupIds`, `blacklisted`) et `items`, `frontend/src/stores/industry/types.ts:11-16` pareil.
- Conséquences vérifiées dans le front :
  - `BlacklistConfig.vue:39-48` reconstruit les groupes actifs en réunissant **tous** les `groupIds` des catégories `blacklisted`. Avec un état partiel (661 seul, possible par `PUT /industry/blacklist`), toute action (cocher une autre catégorie, ajouter un item) réécrit aussi 662 : la blacklist s'élargit sans geste.
  - `BlacklistConfig.vue:136` : case cochée même si partielle, pas d'état intermédiaire.
  - `NewProjectModal.vue:175-193` : la pré-coche des catégories du projet lit ce `blacklisted` ; une catégorie partielle est cochée, `resolveGroupIds` ajoute alors les deux groupes (`IndustryBlacklistService.php:79-89`) au projet.
  - `ProjectBlacklistEditor.vue:36-37` applique déjà ALL (`every`) côté client : les deux écrans ne disent pas la même chose pour les mêmes groupes.
- Les groupes hors catégorie (envoyés par l'API) sont perdus au premier enregistrement de `BlacklistConfig` (`activeGroupIds` ne vient que des catégories) : défaut voisin, même correctif (exposer les groupes bruts).

Atteignable par l'interface seule ? Non : l'UI ne coche que des catégories entières, l'état partiel vient de l'API ou de données anciennes. Impact réel faible, mais la règle est à fixer par la décision.

## 3. Cause

1. Stockpile : `PlansStockpileImports::planOfImport` (créé par la refonte sur le noyau, `fffcdd46`) n'a jamais reçu la blacklist ; les deux classes qui utilisent le trait n'ont pas `IndustryBlacklistService`.
2. Catégories : `getCategories` implémente ANY sous un commentaire ALL, et le contrat n'a pas d'état partiel.

Rien à vérifier en jeu : tout se tranche par test unitaire.

## 4. Options de correctif

**Option A (recommandée), en deux lots, circuit m** : applique la décision en entier.
- Lot 1, Stockpile (back, circuit s) : injecter `IndustryBlacklistService` dans `StockpileService` et `StockpileStartingStockReducer` ; `planOfImport` passe `blacklist: resolveBlacklistedTypeIds($user)` aux deux plans ; la blacklist est celle, vivante, de l'utilisateur (le Stockpile n'a pas de copie par projet, comme Profit Margins). Fichiers : `PlansStockpileImports.php`, `StockpileService.php`, `StockpileStartingStockReducer.php`, tests `StockpileServiceTest`, `StockpileStartingStockReducerTest`, `StockpileGoldenImportCharacterizationTest` (constructeur), `docs/glossary.md:47`, `docs/modules/industry.md:65,77,112`.
- Lot 2, catégories (back + front, circuit s à m) : `getCategories` renvoie `blacklisted` (ALL) et `partiallyBlacklisted` (au moins un groupe, pas tous) et expose `blacklistedGroupIds` des groupes réellement blacklistés ; `BlacklistConfig.vue` : case `indeterminate` si partielle, clic depuis partielle = tout cocher, groupes actifs lus depuis `blacklistedGroupIds` (ne plus élargir ni perdre de groupe) ; `NewProjectModal.vue` ne pré-coche que `blacklisted` ; type `BlacklistCategory` ; i18n si un libellé d'état est ajouté. Fichiers : `IndustryBlacklistService.php`, `BlacklistProvider`/`UpdateBlacklistProcessor` (inchangés si le service seul porte la forme), `BlacklistConfig.vue`, `NewProjectModal.vue`, `stores/industry/types.ts`, spec vitest.

**Option B, circuit s** : lot 1 seul, plus `getCategories` passé en ALL sans état partiel. Rapide, mais une catégorie partielle s'affiche décochée alors que la décision demande un état intermédiaire, et le décochage/recochage ne conserve plus le groupe partiel. Écarte la décision : déconseillée.

## 5. Risques à trancher ou connus

- Cibles Stockpile déjà importées : elles sont persistées à l'import (`StockpileService.php:103-132`), alors que la réduction rejoue le plan à chaque statut avec la blacklist du moment. Après changement de blacklist, les cibles d'un sous-arbre devenu feuille ne sont plus réduites par le stock de départ (réduction absente = cible pleine) tant que l'import n'est pas refait. Pas de migration ; à signaler dans la PR, pas de code supplémentaire proposé.
- Libellé de palier : un composant blacklisté apparaît au niveau `raw_material` dans l'aperçu (`StockpileService.php:376-386`, `isset($plan->leaves[...])`). Cohérent avec « Feuille » du glossaire (`:49`), à confirmer à l'écran en revue de PR.
- Après déploiement : aucune commande ni migration à lancer en prod.

## 6. Tests qui prouveraient le correctif

Lot 1 (PHPUnit, rouge avant) :
- `StockpileServiceTest` : un composant blacklisté (type individuel et via groupe) est une cible `raw_material` à la quantité demandée, sans ses matières ; sans blacklist, comportement inchangé (golden existant).
- `StockpileStartingStockReducerTest` : avec le même composant blacklisté, son stock n'est pas consommé comme intermédiaire et la réduction de ses matières est absente ; plan complet et plan réduit reçoivent la même blacklist.
- Produit importé lui-même blacklisté : l'import réussit (cible non filtrée).

Lot 2 :
- `IndustryBlacklistServiceTest` (nouveau, cf. `docs/modules/industry.md:106`) : `biochem_reactions` avec [661] seul = `blacklisted` faux et `partiallyBlacklisted` vrai ; [661, 662] = vrai/faux ; aucun = faux/faux ; une catégorie à un groupe n'a jamais d'état partiel.
- Spec vitest `BlacklistConfig` : case `indeterminate` en partiel ; cocher une autre catégorie n'ajoute pas 662 ; groupe hors catégorie conservé à l'enregistrement.
- Spec `NewProjectModal` : catégorie partielle non pré-cochée.

## 7. Clôture

Issue à laisser ouverte (aucun correctif sur origin/main : `git log origin/main` sur `IndustryBlacklistService.php` s'arrête à `4fd781cb`, et `PlansStockpileImports.php:51-56` sans blacklist). Proposition : deux issues de lot (Stockpile ; catégories ALL + partiel) avec la décision en référence, puis fermeture de #139 quand les deux sont fusionnées.
