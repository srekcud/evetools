# Spec #153 — La recette d'un type est lue une fois par calcul

Statut : validée en autonomie le 2026-10-28 (mode nuit), à relire par Sylvain. Termes : [glossaire](../glossary.md) ; règles du noyau : [spec du noyau](industry-engine.md) (R3, R9, R12).

## Contexte

Le noyau lit les recettes (`Recipe`) par le port `BlueprintCatalog::recipeFor()` (`src/Industry/Domain/BlueprintCatalog.php:15`). Sa seule implémentation, `SdeBlueprintCatalog`, ne garde rien en mémoire : une feuille coûte 2 requêtes (`SdeBlueprintCatalog.php:40` et `:45`), une recette de fabrication 4 (`:40`, `:58`, `:63`, `:69`), une réaction 5 (`:40`, `:45`, `:58`, `:63`, `:69`).

Le calcul relit pourtant la recette d'un même type à chaque arête du graphe de production :

- `ProductionPlanner::walkMaterials()` appelle `buildableRecipe()` pour chaque matériau, puis seulement ensuite teste `isset($finished[...])` (`ProductionPlanner.php:147-148`). Un type déjà terminé, une feuille ou un type déjà sur le chemin est relu à chaque consommateur.
- La recette d'une cible est lue deux fois par `plan()` et par `marginalCost()` : par `recipeOfTarget()` (`:34`, `:84`), puis par le parcours de `intermediatesInProductionOrder()` (`:134`).
- `ProductionCostCalculator::recipeOf()` est appelé à 5 endroits (`ProductionCostCalculator.php:59`, `:112`, `:142`, `:186`, `:192`), dont un par job du plan (`:112`) : les jobs d'un même produit, ou un produit déjà lu par le planificateur, sont relus.

L'issue chiffre le coût à environ 300 appels `recipeFor` et 66 `recipeOf` pour un Nomad. Ces chiffres sont ceux de l'audit du 2026-10-22, non recalculés ici (PHP n'est pas installé sur l'hôte, aucun test exécuté pour cette spec) : le compte exact est fixé par le test (voir les exemples).

La mémoire visée vit le temps d'un calcul. Aucun cache entre deux appels.

## Définitions locales

- **Calcul** : un appel de `ProductionPlanner::plan()`, de `ProductionPlanner::marginalCost()` ou de `ProductionCostCalculator::costOf()`.
- **Type atteint** : type d'une cible, ou d'un matériau rencontré par le parcours de `walkMaterials()` depuis les cibles. Le parcours ne dépend pas de la demande : un type sous un intermédiaire entièrement couvert par le **stock de départ** est atteint aussi.

Aucun terme nouveau du glossaire : **Plan**, **Intermédiaire**, **Feuille**, **Job**, **Blacklist**, **BOM**, **Stock de départ** suffisent.

## Règles métier

1. **Une lecture par type dans un plan.** Pendant un même `plan()`, `BlueprintCatalog::recipeFor()` reçoit au plus un appel par typeId atteint, qu'il soit cible, intermédiaire ou feuille.
2. **Idem en coût marginal.** La règle 1 vaut pour `marginalCost()`.
3. **L'absence de recette se retient aussi.** Un typeId pour lequel `recipeFor()` a rendu `null` (une feuille) n'est pas relu quand une autre arête y mène.
4. **La recette d'une cible sert au parcours.** La recette lue par `recipeOfTarget()` n'est pas relue par `intermediatesInProductionOrder()`, ni quand la cible réapparaît comme matériau (cycle, ou cible aussi matériau d'une autre cible).
5. **La blacklist reste évaluée avant la mémoire.** Un type blacklisté n'est jamais lu (`ProductionPlanner.php:118-120`, inchangé), sauf comme cible, que `recipeOfTarget()` lit sans consulter la blacklist (`:112`). La mémoire ne stocke que ce que le catalogue a rendu, jamais le résultat du filtre.
6. **Une lecture par typeId dans `costOf`.** Pendant un même `costOf()`, `ProductionCostCalculator::recipeOf()` n'interroge le catalogue qu'une fois par typeId, quel que soit le site d'appel : recette de la cible, un job de plan, un intermédiaire du coût marginal, produit de l'invention, T1 de l'invention. Plusieurs jobs du même produit (découpage par **plafond de runs d'un BPC**) ne relisent pas.
7. **Portée : un calcul.** La mémoire naît au début du calcul et disparaît à sa fin. Deux calculs successifs, sur la même instance ou non, relisent chacun leurs recettes : aucune péremption à gérer, rien de partagé entre utilisateurs, requêtes ou workers. `ProductionPlanner` et `ProductionCostCalculator` n'ont toujours aucun état entre deux appels.
8. **Invariance du résultat.** Le `Plan`, le `MarginalCostPlan` et le `ProductionCost` rendus sont identiques à ceux d'avant, pour toute entrée : mêmes jobs, mêmes runs, mêmes quantités, mêmes feuilles, mêmes montants, même ordre.
9. **Erreurs inchangées.** Une exception du catalogue (blueprint ou activité absent de la table SDE, `SdeBlueprintCatalog.php:60` et `:65`) traverse le calcul sans être retenue ; la première lecture d'un type a lieu au même moment qu'avant, donc le même type lève la même erreur. Un produit sans recette lève toujours `DomainException` (`ProductionPlanner.php:113`, `ProductionCostCalculator.php:301`) ; un cycle lève toujours l'erreur du parcours (`ProductionPlanner.php:152`).

## Exemples

Le test espion est un `BlueprintCatalog` qui compte les appels par typeId et délègue à `InMemoryBlueprintCatalog::fromSdeFixture()` (golden Nomad : `tests/Fixtures/Golden/nomad-1run-me10-no-structure.ravworks.json`, 63 feuilles et 65 intermédiaires d'après `GoldenPlanTest`).

| Entrée | Attendu (appels `recipeFor`) |
|---|---|
| Nomad, 1 run, ME 10, sans structure, `plan()` | 1 appel par type atteint, aucun typeId à 2 appels. Total = nombre de types distincts atteints depuis la cible, blacklistés exclus : `[TO CONFIRM: à calculer par test-writer depuis le golden Nomad]` |
| Même Nomad, `marginalCost()` | Mêmes appels que `plan()` : `[TO CONFIRM: à calculer par test-writer depuis le golden Nomad]` |
| Même Nomad, `costOf()` en mode plan | Côté calculateur, 1 lecture par typeId de `recipeOf()` ; total sur le catalogue espion : `[TO CONFIRM: dépend de la question ouverte 1]` |
| Matériau C consommé par A et par B, B consommé par A | C : 1 appel |
| Feuille F consommée par 10 intermédiaires | F : 1 appel (`null` retenu) |
| Cibles T et U, U matériau de T | U : 1 appel ; T : 1 appel |
| Même cible deux fois dans `targets` | 1 appel pour ce typeId |
| Cible T qui est aussi matériau d'un de ses descendants | 1 appel de T, puis le cycle lève la même `DomainException` qu'avant |
| Type blacklisté B, matériau de deux intermédiaires | 0 appel de B |
| Intermédiaire entièrement couvert par le stock de départ, avec une feuille dessous | La feuille est lue 1 fois (le parcours ignore la demande) |
| `costOf()` d'un produit T2 inventé | `recipeOf` du T2 : 1 lecture (lignes 59 et 186) ; T1 : 1 lecture |
| Produit dont le plafond de runs découpe la fabrication en 4 jobs | `recipeOf` du produit : 1 lecture, 4 `JobCost` |
| Deux `costOf()` successifs sur le même calculateur | Chacun lit ses types : les compteurs ne s'additionnent pas et ne se partagent pas |

Les montants, runs et quantités de ces cas ne sont pas ré-assertés ici : ce sont ceux des goldens existants (`GoldenPlanTest`, `ProductionPlannerMarginalCostTest`, `ProductionCostCalculatorTest`), qui doivent rester verts sans modification.

## Cas limites

- **Type sans recette** : retenu comme `null` par le planificateur (règle 3). Dans le calculateur, `recipeOf()` lève sur `null` : rien à retenir, le calcul s'arrête.
- **Même type à deux profondeurs** : une lecture (exemple C). C'est le cas du Nomad (un même fuel block ou polymère consommé à plusieurs niveaux).
- **Plusieurs cibles** : la mémoire est commune aux cibles d'un même `PlanRequest`.
- **Mode marginal** : `marginal()` reçoit la recette de la cible déjà lue (`ProductionCostCalculator.php:76`) ; seuls les intermédiaires fractionnaires passent par `recipeOf()` (`:142`).
- **Invention** : `recipeOf()` lit le produit T2 (`:59`, `:186`) et son T1 (`:192`) ; le T2 est lu une seule fois pour les deux sites.
- **Blacklist** : la liste est celle de la requête du calcul, constante pendant lui ; la mémoire peut donc être indexée par typeId seul.
- **Deux appels successifs** : rien n'est partagé (règle 7). Profit Margins en fait plusieurs par produit (`ProfitMarginService.php:125`, une fermeture appelée par décryptor, `:227`) : chacun relit.

## Invariance

Aucun résultat de plan ou de coût ne change. Les goldens ne bougent pas : `GoldenPlanTest`, `ProductionPlannerTest`, `ProductionPlannerMarginalCostTest`, `ProductionCostCalculatorTest`, `ProfitMarginGoldenCharacterizationTest`, `BuyVsBuildServiceCharacterizationTest` et leurs fixtures passent sans édition. Les nouveaux tests ne comptent que des lectures du catalogue, jamais des montants.

## Hors périmètre

- Cache persistant ou entre requêtes (service partagé, Redis, `reset()` Symfony) : péremption du SDE après un réimport, invalidation, état partagé.
- Réduction des 4 à 5 requêtes de `SdeBlueprintCatalog` par recette (jointure, chargement groupé) : autre issue.
- Migration des appelants vers le noyau ([#141](https://github.com/srekcud/evetools/issues/141)) et mémoïsation côté `BuyVsBuildService` (`BuyVsBuildService.php:206`, `:211`, `:262`, qui lit lui-même le catalogue).
- Tout changement de règle de calcul (R1 à R13 du noyau).

## Questions ouvertes

1. **Le planificateur et le calculateur partagent-ils la mémoire ?** Avec deux mémoires séparées (planificateur, puis calculateur), un `costOf()` relit chaque cible et chaque intermédiaire deux fois : une fois par le planificateur, une fois par `recipeOf()` sur les jobs. Seul `plan()` et `marginalCost()` tiennent alors « une fois par type » ; le test de l'issue, posé sur un calcul de coût complet, ne passerait pas. Option A : deux mémoires, l'objectif de l'issue est atteint au niveau du plan seulement. Option B : une mémoire créée par `costOf()` (par exemple un décorateur de `BlueprintCatalog` propre à l'appel) transmise au planificateur (aujourd'hui construit une fois dans le constructeur, `ProductionCostCalculator.php:54`) et utilisée par `recipeOf()`. Recommandation : B, la portée reste un appel et la lecture totale d'un `costOf()` tombe au nombre de types distincts. Une mémoire mutable ne peut pas vivre dans une classe `readonly` : le décorateur ne l'est pas, mais `ProductionPlanner` et le calculateur restent `readonly` si la mémoire est créée dans l'appel et passée en paramètre.
2. **Le gain visé dans « Impact » est-il atteint ?** L'issue craint que Profit Margins, avec 9 appels `costOf()` par produit, multiplie le coût en requêtes. Une mémoire par calcul ne réduit pas ce facteur : chaque `costOf()` relit tout. Recommandation : livrer cette spec telle quelle, mesurer le nombre de requêtes par produit après la migration de Profit Margins, puis ouvrir une issue séparée pour une mémoire à portée de requête HTTP si le coût le justifie.
3. **Le compte exact du Nomad vaut-il comme critère ?** Il dépend du nombre de types atteints (cible, 65 intermédiaires, 63 feuilles d'après les goldens, sous réserve que la cible ne soit pas déjà comptée), à confirmer par le test. Recommandation : asserter l'égalité avec le nombre de typeId distincts relevés par l'espion (« aucun typeId à plus d'un appel »), plus un total chiffré une fois calculé, plutôt qu'un total écrit à la main.
