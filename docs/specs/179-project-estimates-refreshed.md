# Spec #179 : les estimations d'un projet suivent ses runs et ses produits

**Statut : rédigée en mode nuit le 2026-11-04 (thread, 0 token sous-agent), validée en autonomie.**

## Constat vérifié dans le code

| Preuve de l'issue | État au 2026-11-04 |
|---|---|
| Seul `CreateProjectProcessor::process()` écrit `estimatedJobCost`, `estimatedMaterialCost` et le triplet `estimatedSellPrice` / `Source` / `TaxAmount` (hors commande `ReactionProjectSellPriceRecalculation`, réactions seulement) | vrai |
| `UpdateProjectProcessor::process()` change runs, ME, TE ou durée max, régénère les étapes (`IndustryProjectFactory::regenerateSteps`) et ne recalcule rien | vrai |
| `Industry.vue::createProject()` crée le projet avec le premier produit, puis `store.createStep()` pour les suivants ; `EstimatedSellPriceEstimator::estimateFor()` ne lit que `project.productTypeId` | vrai |
| Coûts matériaux et installation : `ProductionCostService::estimateMaterialCost()` (liste d'achats) et `estimateJobInstallCosts()` (toutes les étapes) lisent déjà **toutes** les étapes du projet | donc un produit ajouté au projet est pris en compte dès que ces calculs sont relancés |

Le vocabulaire est celui du glossaire (**Prix de vente estimé**, **Coût inconnu**, **Quantité produite**, **Producteur**).

## Règles métier

- **R1 : une seule règle de recalcul.** Un service `ProjectEstimatesRefresher::refresh(IndustryProject $project): void` (dans `src/Service/Industry/`) recalcule, dans l'ordre, `estimatedJobCost` (`estimateJobInstallCosts()['total']`), `estimatedMaterialCost` (`estimateMaterialCost()['total']`) et le triplet du prix de vente (`EstimatedSellPriceEstimator::estimateFor()`). Il ne fait pas de `flush` : l'appelant flush. C'est le code actuel de `CreateProjectProcessor` (trois `try/catch` indépendants, avertissement journalisé `Industry project estimation failed`, clés `estimation`, `projectId`, `reason`), déplacé tel quel.
- **R2 : un échec ou un prix absent rend l'estimation inconnue, jamais l'ancienne valeur.** Si un calcul lève une exception, ou si `estimateFor()` rend `null`, le champ concerné (ou le triplet complet) passe à `null`. Aujourd'hui la création laissait `null` parce que le projet était neuf ; au recalcul, garder la valeur précédente serait un montant faux affiché comme estimation. Les montants `null` d'un coût inconnu (produit en erreur, cost index ou prix ajusté absent) sont déjà écrits tels quels.
- **R3 : quand on recalcule.**
  - Création du projet (`CreateProjectProcessor`) : comportement inchangé.
  - `PATCH` du projet qui régénère les étapes (runs, ME, TE ou durée max modifiés, `$regenerateSteps`) : `refresh` après `regenerateSteps()`, puis `flush`. Un `PATCH` qui ne touche que nom, notes, coûts saisis, statut ne recalcule rien.
  - Ajout d'une étape (`CreateStepProcessor`, toutes les branches : `typeId`, `stepId`, `splitGroupId`) et suppression d'une étape (`DeleteStepProcessor`) : `refresh` après la recalculation des quantités, avant le `flush` final de l'opération.
- **R4 : prix de vente estimé d'un projet à plusieurs produits.** Les **produits du projet** sont le produit du projet et le produit de chaque étape racine supplémentaire : étape de profondeur 0, activité `manufacturing` ou `reaction`, dont le `productTypeId` diffère de celui du projet.
  - Produit du projet : inchangé, `meilleur prix × runs × unités par run du producteur`.
  - Autre produit racine : `meilleur prix × somme des quantités de ses étapes racines` (un produit dont les jobs sont découpés compte tous ses jobs).
  - Le meilleur prix d'un type est la règle actuelle (Jita ou structure préférée, le plus haut ; source `jita` ou `structure`).
  - Montant = somme des produits ; taxe = montant × (courtage + taxe de vente de l'utilisateur), même taux qu'aujourd'hui.
  - **Un seul produit sans prix rend tout le prix de vente estimé inconnu** (`null`) : jamais une somme partielle (glossaire, **Coût inconnu**).
  - Source : celle commune à tous les produits, `mixed` quand elles diffèrent.
- **R5 : l'écran affiche la source `mixed`.** `ProjectDetail.vue` montre `Jita` pour `jita`, le libellé structure pour `structure`, et le libellé `industry.costs.mixedSources` (« Mixed » / « Mixte ») pour `mixed`.
- **R6 : le front ne change pas le flux de création.** `createProject()` crée toujours le projet puis les étapes des produits suivants ; R3 suffit à ce que les estimations incluent tous les produits à la fin de la création. Le dernier `createStep` laisse l'estimation complète.

## Exemples chiffrés (tests rouges)

Taux : réglages utilisateur courtage 1 %, taxe de vente 2 % (sans réglages, les défauts `EveConstants` donnent 7,2 %), donc taxe = 3 % du montant.

| Cas | Entrée | Attendu |
|---|---|---|
| Runs | projet Rifter, prix Jita 100 000, 1 unité par run, créé à 1 run (`estimatedSellPrice` 100 000) puis passé à 10 runs | `estimatedSellPrice` 1 000 000, taxe 30 000, source `jita` ; `estimatedMaterialCost` et `estimatedJobCost` valent le retour des services pour les nouvelles étapes |
| Deux produits | projet Rifter 10 runs (Jita 100 000) + étape racine Hammerhead I, quantité 5 (Jita 20 000) | 1 000 000 + 100 000 = 1 100 000, taxe 33 000, source `jita` |
| Sources mixtes | idem, Hammerhead I au prix structure 20 000 supérieur au Jita | 1 100 000, source `mixed` |
| Produit sans prix | idem, Hammerhead I sans prix Jita ni structure | `estimatedSellPrice`, `Source`, `TaxAmount` : `null` |
| Produit découpé | Hammerhead I en deux jobs racines de quantités 3 et 2 | 5 × 20 000 |
| Étape de copie ou d'invention racine, produit identique à celui du projet | étape racine de copie d'un autre type, ou étape racine du produit du projet | non comptée (activité ou produit exclus) |
| Échec | `estimateMaterialCost()` lève une exception sur un projet déjà estimé | `estimatedMaterialCost` `null`, avertissement journalisé, les deux autres estimations recalculées |
| Prix disparu | `estimateFor()` rend `null` sur un projet qui avait un triplet | triplet `null` |
| PATCH sans régénération | seul `name` modifié | `refresh` jamais appelé |
| Ajout puis retrait | `CreateStepProcessor` (typeId Hammerhead I) puis `DeleteStepProcessor` de cette étape | estimations recalculées à chaque appel, `refresh` après `recalculateStepQuantities` |

## Cas limites

- Projet sans étape (tests unitaires existants, projet vide) : seul le produit du projet est compté, comme aujourd'hui.
- `ProducerMissingFromSdeException` sur le produit du projet : traité par R2 (prix de vente `null`, avertissement), comme à la création.
- Deux étapes racines du même autre produit (jobs découpés ou doublons) : leurs quantités s'additionnent.

## Hors périmètre

- `UpdateStepProcessor`, `SplitStepProcessor`, `MergeStepsProcessor`, achats, étapes en stock, jobs liés : leurs effets sur les coûts restent hors recalcul des estimations (le chiffrage à jour reste celui de l'onglet Estimation des coûts et de la liste d'achats). À décider si Sylvain veut un recalcul sur chaque mutation d'étape.
- Prix de vente saisi (`sellPrice`), coûts saisis (`materialCost`, `bpoCost`, etc.) : inchangés.
- Recalcul rétroactif des projets existants : aucun (les estimations se corrigent au prochain PATCH qui régénère ou à la prochaine étape ajoutée ou supprimée). Aucune migration ni commande console.
- Commande `ReactionProjectSellPriceRecalculation` : garde son comportement ; elle bénéficie de R4 puisqu'elle appelle `estimateFor()`.

## Décisions prises en autonomie

1. Les trois estimations repassent à `null` quand leur calcul échoue (R2), plutôt que de garder la valeur périmée.
2. Source `mixed` pour un prix de vente multi-produits issu de deux marchés (R4), avec libellé front (R5).
3. Recalcul limité à création, régénération des étapes, ajout et suppression d'étape (R3) ; les autres mutations d'étape sont notées hors périmètre.
4. Pas de note de câblage `architect` : le câblage est dans la présente spec (fichiers : `CreateProjectProcessor`, `UpdateProjectProcessor`, `CreateStepProcessor`, `DeleteStepProcessor`, `EstimatedSellPriceEstimator`, nouveau `ProjectEstimatesRefresher`, `ProjectDetail.vue`, `en.json`, `fr.json`).
