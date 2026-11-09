# #121 — Liste d'achats et coût de production dérivés des étapes : conception

Chiffres vérifiés par simulation Python de R1 / plan agrégé sur `tests/Fixtures/Golden/sde-blueprint-recipes.json` (même SDE que les goldens ; QM, TC, PC, NT, NM identiques au SDE local `sde_industry_activity_*`). À recouper en phase rouge par les tests PHP.

## 1. Approches

**Constat clé.** Titanium Carbide sort à 10 000 unités par run : 490 ou 491, c'est 1 run, et ses matières ne bougent pas. L'exemple de l'issue ne se voit dans la liste d'achats que si Titanium Carbide est une **feuille** (blacklisté ou étape achetée). Par ailleurs, les étapes agrègent déjà la demande (#69) et lisent les runs comme des runs (#2) ; une liste dérivée des étapes corrige donc aussi #69 et #2 pour la liste et le coût (Nomad : 63/63 feuilles = Ravworks, contre 29 écarts aujourd'hui).

| | **A — Liste dérivée des étapes persistées** (recommandée) | **B — Rang 5 sur `src/Industry/Application` (mode plan)** |
|---|---|---|
| Principe | Un passage topologique sur les étapes (consommateurs avant matériaux, comme `IndustryStepCalculator`). Chaque étape = un job, avec ses runs, son ME et sa structure (`getStructureBonusForStep`). Les matières premières = matériaux SDE des jobs non fabriqués par une étape. | `PlanRequest` reconstruit depuis le projet (cibles = racines, stock, blacklist + étapes achetées), `ProductionPlanner::plan()`. Les feuilles du `Plan` donnent la liste. |
| Fichiers src | `IndustryShoppingListBuilder` (réécrit, ne dépend plus de `IndustryTreeService`). Option : extraire l'ordre topologique de `IndustryStepCalculator` dans un helper partagé (règle 3). `ProductionCostService`, `ShoppingListProvider`, frontend : aucun changement. | Builder + nouvel adaptateur projet → `PlanRequest` ; Domain étendu pour le découpage par durée (temps, TE, skills), le ME et la structure **par étape** (`JobMaterialModifiers::forJob(Recipe)` est par recette, pas par job), les runs modifiés à la main. |
| `extraQuantity` | Même forme : `quantity` = bonus de la meilleure structure (`findBestStructureForProduct`), `extraQuantity = max(0, réel − meilleur)` par type, clé présente seulement si une étape est sous-optimale (critère inchangé). Différence : l'écart ne porte que sur la couche matières premières ; les runs des intermédiaires sont ceux des étapes (déjà recalculés avec la structure réelle). La règle du « bonus selon la catégorie du matériau » (#6) disparaît au profit du produit du job (R4). | Le planner n'a qu'un modificateur par recette : il faudrait deux plans (meilleur / réel) et un sélecteur par étape. |
| `failedProducts` | Étape de production dont la recette est absente du SDE, ou dont un type (produit, matériau) n'a pas de nom : une entrée `{typeId, reason}` par produit d'étape, messages inchangés, typeId croissant. Plus seulement les racines (glossaire à ajuster). | Erreur du planner par cible ; un intermédiaire sans recette serait acheté. |
| Stock de départ (#20) | Stock d'un produit consommé, réservoir commun, déduit de sa demande ; la réduction descend aux matériaux par Δ (voir R4 ci-dessous). Stock d'une racine ignoré (inchangé). | Natif (R9 du noyau) mais recalcule les runs de tout le plan, donc s'écarte des étapes éditées. |
| Tests cassés | ~30 des 37 tests du builder (projets à étapes partielles, l'arbre comblait les trous) ; 0 à 2 du provider (projets à étape racine NFB seule, sans intermédiaire : inchangés). | Les mêmes, plus les écarts dès qu'une étape est éditée. |
| Risque / taille | Faible en prod (les étapes font foi, cohérence garantie par construction) ; src M, tests L. | Élevé (deux modèles divergents tant que le rang 7, la factory, n'est pas migré) ; XL. |

**Recommandation : A.** La spec demande « étapes = liste d'achats » ; A l'obtient par construction, sans second calcul de plan. Elle reste valable après la migration de la factory sur le noyau (rang 7) : les étapes viendront du planner et la liste suivra. B oblige à étendre le Domain (durée de job, structure par job) pour un seul appelant, et contredit les étapes éditées à la main. Hybride écarté : garder l'arbre pour les matériaux sans étape maintient deux modèles et laisse #69.

## 2. Périmètre minimal proposé

- Dans le périmètre : `IndustryShoppingListBuilder::getShoppingList()` (donc `GET /shopping-list`, `ProductionCostService`, l'estimation figée par `CreateProjectProcessor`).
- Hors périmètre : l'arbre affiché (`ProjectProvider`, rang 4). Le front ne s'en sert que pour l'affichage et la carte parent → enfants (`useShoppingStockAnalysis`, `useStockAnalysis`), pas pour un total. Le changer touche `StepHierarchyTree.vue` ; issue séparée, plus sûr.
- Effet de bord assumé : #2 et #69 corrigés pour la liste et le coût (commentaire sur ces issues, sans les fermer : l'arbre et les autres appelants restent).

## 3. Brouillon de spec — `docs/specs/121-shopping-list-from-steps.md`

### Contexte
La liste d'achats relit `IndustryTreeService::buildProductionTree()`, un nœud par produit et par branche, sur les runs totaux : elle ignore le découpage (#73) et l'agrégation (#69) des étapes. Le coût de production la lit tel quel. Écart étapes ↔ liste d'achats.

### Règles métier
- **R1 — Source.** La liste d'achats se calcule sur les étapes de production du projet (hors copie), jamais sur l'arbre. Chaque étape est un job : ses runs, son ME, sa structure (`getStructureBonusForStep`).
- **R2 — Produit fabriqué / matière première.** Un type produit par une étape de production non achetée, et absent de la blacklist actuelle, est **fabriqué**. Tout autre matériau d'un job est une **matière première** : quantité = R1 du noyau, job par job, sommée sur tous les jobs.
- **R3 — Ordre.** Les produits sont traités consommateurs avant matériaux. Une **racine** (produit consommé par aucune étape) garde ses runs persistés ; son stock n'est pas lu (inchangé).
- **R4 — Besoin d'un produit consommé.** Besoin = Σ quantités de ses étapes + Δ, avec Δ = Σ sur les jobs consommateurs de [R1(runs effectifs) − R1(runs persistés)]. À fabriquer = max(0, besoin − stock). Si Δ = 0 et stock = 0 : runs persistés de chaque job (découpage conservé). Sinon : runs = `ceil(à fabriquer / unités par run)`, répartis sur le même nombre de jobs (`SplitGroupQuantities::runsBySplitIndex`), 0 run = aucun matériau.
- **R5 — Acheté ou blacklisté.** Une étape achetée, ou dont le produit est blacklisté depuis, ne fabrique rien : on achète max(0, besoin − stock), sans sous-arbre ; ses matériaux reçoivent un Δ négatif.
- **R6 — Stock.** Stock d'un type = Σ `inStockQuantity` de ses étapes, consommé une seule fois.
- **R7 — `extraQuantity`.** Matières premières recalculées avec le bonus de la meilleure structure (`quantity`) et avec celui de l'étape ; `extraQuantity = max(0, écart)`, clé présente seulement si une étape est sous-optimale. Les runs des intermédiaires sont ceux de R4 dans les deux calculs.
- **R8 — Produit en erreur.** Étape dont la recette est introuvable, ou type sans nom dans le SDE : `failedProducts` porte `{typeId du produit de l'étape, reason}` (messages actuels), typeId croissant, une fois par produit. Ses matériaux sont absents, les étapes en dessous gardent leur besoin persisté.
- **R9 — API et coût.** Forme du retour inchangée ; #119 s'applique tel quel.

### Exemples (SDE golden, sans structure)
| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Quantum Microprocessor 32 runs ME 10 / TE 20, durée max 0,01 j (11 + 11 + 10), Titanium Carbide blacklisté | Titanium Carbide 490 | **491** (169 + 169 + 153) |
| E2 | E1 : autres feuilles (agrégation #69) | Oxygen Isotopes 1620, Robotics 11, Liquid Ozone 3465 | 405, 4, 1260 |
| E3 | E1 sans découpage (durée max 2 j) | 490 | 490 (garde-fou) |
| E4 | E1 sans blacklist, étape Titanium Carbide achetée | 490 | 491 achetés, aucune matière sous Titanium Carbide |
| E5 | E1 sans blacklist, 491 Titanium Carbide en stock | — | liste de E1 sans la ligne Titanium Carbide (Titanium Chromide, Silicon Diborite et Oxygen Fuel Block passent de 200 / 200 / 25 à 100 / 100 / 20, toujours 1 run) |
| E6 | E1 sans blacklist ni stock | — | identique à E3 hors blacklist : le découpage ne change aucune feuille (1 run de 10 000) |
| E7 | Nomad 1 run ME 10, étapes de la factory sans découpage | 29 feuilles sur 63 fausses | 63 feuilles = Ravworks |
| E8 | NFB 25 runs, étape racine seule | Nitrogen Isotopes 405 | 10125 (#2) |
| E9 | NFB 1 run en station, Raitaru meilleure | 401 + 4 | inchangé |
| E10 | Étape Robotics (sans recette) + NFB | `failedProducts` Robotics | inchangé |

### Cas limites
- Projet sans étape de production : liste vide, aucun produit en erreur [À CONFIRMER, aujourd'hui l'arbre du produit du projet].
- Matériau fabricable sans étape (étape supprimée) : acheté comme une matière première [À CONFIRMER].
- Stock partiel qui ne change pas le nombre de runs : liste identique.
- Groupe découpé dont les runs effectifs tombent sous le nombre de jobs : jobs à 0 run ignorés.
- Après un découpage manuel, les petits-enfants gardent leur besoin persisté (#73, R3) : la liste suit les étapes, la régénération remet tout en cohérence.

### Hors périmètre
Arbre affiché (`ProjectProvider`) ; BuyVsBuild, marges, stockpile, Group Industry ; plafond BPC ; bonus par catégorie de matériau dans la factory (#6) ; `AdaptStockProcessor` (voir risque).

### Glossaire
« Produit en erreur » : « produit d'une étape de production » au lieu de « produit racine ». « Stock de départ » : symbole `buildIntermediateTree()` retiré si plus appelé.

### Risques
- `AdaptStockProcessor` réduit runs et quantité en gardant `inStockQuantity`, puis le recalcul remet le besoin plein. Si une étape restait réduite, le stock serait déduit deux fois. Test à prévoir.
- Les chiffres de liste et de coût baissent en prod pour les projets à intermédiaires partagés : effet voulu, à annoncer.

## 4. Note de câblage (test-writer)
- **Harnais.** Construire les projets avec la vraie `IndustryProjectFactory` sur le SDE golden (des étapes à la main oublient des intermédiaires). Extraire le câblage de `IndustryProjectFactoryGoldenStepsCharacterizationTest` dans un trait `BuildsProjectOnGoldenSde`, partagé avec `BuildsShoppingListOnGoldenSde`. Unifier l'offset synthétique des blueprints : 1 000 000 côté factory, 900 000 000 côté liste.
- **Stubs à ajouter** dans `BuildsShoppingListOnGoldenSde` : `IndustryActivityMaterialRepository::findMaterialEntitiesForBlueprints()`, `IndustryActivityProductRepository::findProductsForBlueprints()` (clé `blueprintTypeId-activityId`). `InvTypeRepository::find()` reste pour les noms et les types absents. `addStep()` prend une `quantity`, obligatoire pour un intermédiaire, la propriété n'étant pas initialisée.
- **E1 :** `setIndustryBlacklistTypeIds([TITANIUM_CARBIDE])`, puis `createProject(QM, 32, me 10, te 20, 0.01)`. Ne pas reprendre `typeIdsWithoutBaseTime` du test #73 : sans effet ici, Titanium Carbide fait 1 run.
- **E7 :** durée max sans découpage [À CONFIRMER : le snapshot #69 découpe Hydrogen Fuel Block en 161 + 161 ; découpé, Coolant passe de 2609 à 2610].
- **Appelés à casser** (`IndustryShoppingListBuilderGoldenCharacterizationTest`) : `…CurrentlyBuysForOneRunIssue2`, `…AddsTheRunsOfSplitRootSteps…Issue2` et `…FerniteCarbide…Issues2And16` (corrigés) ; `…ProjectWithoutSteps…` ×2 (cas limite) ; `…TakesTheMeLevelOfTheFirstRootStep…` (chaque étape garde son ME) ; `…OneNomadCurrentlyOverestimates…Issue69` (= Ravworks) ; tous les tests stock, achat et blacklist sur `elevenRunsOfCapitalArmorPlate()` ou Fernite (étapes partielles) ; `…ExtraQuantity…Issue6` (étapes complètes, valeurs à recalculer). Stables : tests NFB racine seule (structure, échec, extra), `testGetPurchasedQuantities…`.
- **Stables :** `ProductionCostServiceTest` et `CreateProjectProcessorTest` (builder mocké) ; `ShoppingListProviderGoldenCharacterizationTest` (NFB racine seule, à vérifier au rouge).
- **Questions ouvertes pour Sylvain :** les deux [À CONFIRMER] des cas limites ; l'étendue de `failedProducts` à toute profondeur ; l'annonce de la baisse des chiffres (#2 et #69 corrigés pour la liste).
