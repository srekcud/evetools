# #345 : étapes de réaction dans la chaîne de production

Maquette : `industry-project-v3.html#p=33` (carte « Réactions · Particle Accelerator Unit », note 27). Groupe `unified-industry`. Mode nuit : validée en autonomie. Issue partielle : les coûts par unité, la valeur du surplus et la comparaison à l'achat sont ceux de #343 (PR #402, carte « Acheter ou fabriquer » de l'onglet Chaîne), pas dupliqués ici.

## Contexte

- La chaîne (#337) montre déjà un nœud par ligne de BOM, réactions comprises (`activity` `reaction`), mais sans formule, sans durée, sans surplus. Une ligne de job porte `runs` et `requiredQuantity` = runs × unités par run (quantité produite).
- Le surplus d'un intermédiaire (`Intermediate::$surplus`, glossaire « Surplus ») n'existe que dans le plan, calculé à la création du projet ; il n'est pas conservé. Le recalculer à la lecture contredit R1 de #337 (plan jamais recalculé) et divergerait de la BOM si les structures du propriétaire changent.
- Formule, unités par run et durée de base viennent du SDE (`BlueprintCatalog::recipeFor`, `Recipe::$materials`, `$baseTimeSeconds`). Les fuel blocks sont les types du groupe SDE 1136 (déjà utilisé par `IndustryBlacklistService`).

## Règles

1. **Surplus conservé à la création.** `IndustryProjectBomLine::$surplusQuantity` (entier nullable, colonne `surplus_quantity`, migration irréversible comme #337 R9b) reçoit `Intermediate::$surplus` du plan pour chaque ligne de job `component` (fabrication et réaction), à la création et à la reconstruction de la BOM (`updateLineInPlace` copie la valeur). Lignes `final`, copie, invention, matériaux : `null`. Si un intermédiaire est découpé en plusieurs jobs (plafond de runs), chaque ligne de ce type porte la même valeur : c'est le surplus de l'intermédiaire, pas de la ligne. Une ligne antérieure à la migration garde `null`.
2. **Ressource `ProjectReactionSteps`** : `GET /api/industry/projects/{projectId}/reactions`, `uriVariables` avec `Link` vers `IndustryProjectResource`, `normalizationContext: skip_null_values=false`. Corps : `id` (projet), `steps`. Calculée à la lecture, rien de persisté en dehors de R1.
3. **Une étape par type produit par réaction** : lignes de BOM `isJob` avec `activityType` `reaction`, regroupées par `typeId`. Ordre : nom de type, puis `typeId`. Un projet sans réaction : `steps` vide, 200.
4. **Champs d'une étape** : `typeId`, `typeName`, `runs` (somme des runs des lignes), `producedQuantity` (somme des `requiredQuantity`), `surplusQuantity` (valeur de R1 ; `null` si une ligne du type la porte à `null`), `neededQuantity` = `producedQuantity − surplusQuantity` (`null` si le surplus est `null`), `outputPerRun`, `baseSecondsPerRun`, `baseTotalSeconds` = `baseSecondsPerRun × runs`, `inputs`.
5. **Formule lue dans le SDE à la lecture.** `inputs` : un élément par matériau de la formule, `{typeId, typeName, quantityPerRun, totalQuantity, isFuelBlock}` ; `quantityPerRun` = quantité de base du SDE (sans bonus de structure ni de rig, qui s'appliquent aux matériaux du plan et sont déjà dans les lignes de matériau de la BOM), `totalQuantity` = `quantityPerRun × runs`, `isFuelBlock` = type du groupe 1136. Ordre : fuel blocks en dernier, puis nom de type.
6. **Inconnu, jamais une valeur plausible.** Si le SDE n'a plus de formule de réaction pour le type (recette absente, ou activité autre que réaction) : `inputs`, `outputPerRun`, `baseSecondsPerRun` et `baseTotalSeconds` valent `null`, l'étape reste listée avec ses quantités de BOM. Pas de `?? 0`, pas de durée par défaut.
7. **Durée de base** : temps SDE du blueprint de la formule par run, **sans** compétence (Reactions) ni bonus de structure ou de rig. L'écran l'écrit (« durée de base, sans bonus »). La durée prévue avec les compétences est celle du planning (#338).
8. **Accès** comme la chaîne : `ProjectAccessChecker::assertAcceptedMember` ; projet inconnu : 404 « Project not found » avant tout contrôle ; non-participant : 403 ; utilisateur non authentifié : 401. Ne sort que de la BOM et du SDE, rien de plus que ce que la chaîne expose aux participants.
9. **Front** : carte « Réactions » (`ProjectReactionSteps.vue`, props `projectId`), montée dans `ProductionChain.vue` sous la chaîne, seulement quand la chaîne a des nœuds et que `steps` n'est pas vide ; store `group-industry/reactionSteps.ts` (`fetchReactionSteps`, requêtes périmées ignorées comme `chain.ts`). Colonnes : composite (nom, puis formule « Carbon Polymers ×100 · … » avec les fuel blocks distingués), besoin, runs · durée de base, produit, surplus (quantité et part du produit en %). Bandeau d'avertissement quand `surplusQuantity` > 0 : le run entier produit plus que le besoin, le surplus ne sert que si un autre projet ou le Stockpile le consomme. `surplusQuantity` `null` : tiret et cause « surplus non enregistré (projet créé avant la migration) », jamais 0. Étape sans formule (R6) : « formule absente du SDE ». Mention « coûts et comparaison à l'achat : carte Acheter ou fabriquer ». Libellés FR et EN, testés avec des noms de type très longs (troncature, titre complet).
10. **Direct.** Mercure : aucune action asynchrone ajoutée. La carte se recharge sur les mêmes signaux que la chaîne (`getGroupProjectEvent(projectId)` et `getSyncProgress('industry-project')` du même projet).

## Exemples (vérifiables par test)

Formule Fullerides (SDE) : Carbon Polymers 100, Platinum Technite 100, Nitrogen Fuel Block 5 par run, 3 000 unités par run, 10 800 s par run.

| # | État | Résultat |
|---|---|---|
| E1 | Fullerides : 1 run, `requiredQuantity` 3 000, surplus 2 509 | produit 3 000, besoin 491, surplus 2 509 (83,6 % du produit), `baseTotalSeconds` 10 800, `inputs` : 100, 100, 5 (le fuel block en dernier, `isFuelBlock` vrai) |
| E2 | Même type en deux lignes (runs 3 et 2, quantités 9 000 et 6 000, surplus 4 000 sur chaque) | une étape : runs 5, produit 15 000, surplus 4 000, besoin 11 000, `baseTotalSeconds` 54 000, `totalQuantity` 500, 500, 25 |
| E3 | Une ligne du type avec `surplusQuantity` null | `surplusQuantity` null, `neededQuantity` null, produit connu, formule affichée |
| E4 | Aucune formule de réaction pour le type dans le SDE | `inputs`, `outputPerRun`, durées null ; quantités de BOM conservées |
| E5 | Composant fabriqué (activité manufacturing) et produit final | aucune étape |
| E6 | Projet sans réaction | `steps` vide |
| E7 | Deux réactions (Hypersynaptic Fibers, Fullerides) | ordre alphabétique des noms |
| E8 | Création d'un projet dont le plan a une réaction de demande 491, formule à 3 000 par run | la ligne de réaction porte `surplusQuantity` 2 509 ; la ligne finale et les matériaux : null |
| E9 | Intermédiaire découpé en deux jobs par le plafond de runs | les deux lignes portent le surplus de l'intermédiaire |
| E10 | Non-participant ; projet inconnu ; non authentifié | 403 ; 404 ; 401 |

## Cas limites

- Réaction dont le stock de départ couvre la demande : pas de job, donc pas de ligne ni d'étape.
- Surplus 0 : pas de bandeau, part 0 %.
- `producedQuantity` 0 : aucune étape ne peut l'avoir (au moins un run) ; pas de division par zéro côté front.
- Type de matériau inconnu du SDE : nom `null` côté API, l'écran affiche l'identifiant.

## Tests existants modifiés

À repérer par `grep` : tests qui comparent toutes les propriétés d'une ligne de BOM ou la forme complète de la création (`GroupIndustryBomGoldenCharacterizationTest`, `IndustryProjectServiceTest`, `GroupIndustryBomPersistenceTest`) : ajout de `surplusQuantity` seulement, aucune assertion existante affaiblie. Test du nombre de ressources ou de routes API s'il existe.

## Hors périmètre

Coût par unité, valeur du surplus, comparaison à l'achat, bouton de bascule (#343) ; durée prévue avec compétences et structures (#338) ; bonus de structure sur les matériaux de la formule ; valorisation du surplus par d'autres projets ; ligne « matériaux lunaires non développés » de la maquette.

## Décisions

- **D1 — Surplus persisté (colonne nullable)** plutôt que plan recalculé à la lecture : même choix que #337 R9b (structure conseillée), même migration irréversible. Alternative écartée : `planOf` à la lecture (comme #343), qui diverge de la BOM si le propriétaire change ses structures.
- **D2 — Ressource séparée** plutôt que de nouveaux champs sur les nœuds de la chaîne : le contrat de la chaîne (#337) et son constructeur pur restent inchangés.

## Glossaire

Ajouter : **Étape de réaction** (Reaction step), **Durée de base** (Base duration).
