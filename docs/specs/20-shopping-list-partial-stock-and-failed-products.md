# Spec #20 — Liste d'achats : stock partiel d'un intermédiaire et produit en erreur

## Contexte

`IndustryShoppingListBuilder::getShoppingList()` construit la liste d'achats d'un projet depuis l'arbre de production (`IndustryTreeService`).

1. **Stock partiel.** Le stock d'un intermédiaire (`inStockQuantity` des étapes) réduit la quantité demandée, mais le parcours descend ensuite dans le sous-arbre calculé pour la quantité **totale** (`IndustryShoppingListBuilder.php:184-201`). Le stock partiel est perdu : la liste est identique à celle d'un projet sans stock.
2. **Produit en erreur.** Un `catch (\RuntimeException) { continue; }` (`:111`, `:132`) retire en silence un produit racine dont l'arbre ne peut pas être construit (aucun blueprint ni réaction, type absent du SDE). La liste ne le signale pas ; un projet dont le seul produit est en erreur a une liste vide, indiscernable d'un projet sans rien à acheter.

Règles cibles : spec du noyau `docs/specs/industry-engine.md`, R9 (stock de départ) et § 5 (« Produit sans blueprint ni réaction : erreur explicite, signalée dans le résultat ; jamais un produit retiré en silence »).

## Règles métier

- **R1 — Stock consommé avant les runs.** Pour un intermédiaire fabricable, ni acheté ni blacklisté, demandé en quantité `Q` avec un stock disponible `S` : si `S ≥ Q`, aucun matériau en dessous (inchangé) ; si `0 < S < Q`, son sous-arbre est celui d'un intermédiaire demandé en quantité `Q − S` : runs `ceil((Q − S) / unités par run)`, matériaux recalculés pour ces runs avec les mêmes ME et bonus de structure que l'arbre actuel.
- **R2 — Stock consommé une seule fois.** Le stock d'un type est un réservoir commun à tout le projet (inchangé) : ce qui est consommé à un endroit de l'arbre n'est plus disponible ailleurs, y compris pour un type présent dans plusieurs branches ou sous plusieurs produits racines.
- **R3 — Même règle pour la quantité des structures non optimales.** Le calcul de `extraQuantity` (structures assignées différentes de la meilleure) applique R1 et R2 de la même façon.
- **R4 — Produit en erreur signalé.** Un produit racine dont l'arbre ne peut pas être construit n'est plus ignoré en silence : la liste des autres produits reste calculée, et le résultat porte ce produit dans `failedProducts`, chaque entrée `{typeId, reason}` où `reason` est le message de l'erreur (`No blueprint or reaction found for product type ID …`, `Types missing from the SDE: …`). Une entrée par produit racine, triées par typeId croissant.
- **R5 — API.** `GET /industry/projects/{id}/shopping-list` expose `failedProducts` (`[]` quand tout est construit).
- **R6 — Frontend.** L'onglet liste d'achats du projet affiche un avertissement listant les produits en erreur et leur raison.

## Exemples chiffrés

Harnais des caractérisations existantes (`IndustryShoppingListBuilderGoldenCharacterizationTest`, SDE golden) : 1 run de Fernite Carbide, qui demande 100 Fernite Alloy (réaction).

| # | Situation | Attendu |
|---|---|---|
| E1 | 50 Fernite Alloy en stock sur 100 | les matériaux sous Fernite Alloy sont ceux d'une réaction de Fernite Alloy pour 50 unités (runs `ceil(50 / unités par run)`), et non pour 100 ; la liste diffère de celle sans stock |
| E2 | 0 en stock (garde-fou) | liste identique à aujourd'hui |
| E3 | 100 en stock (garde-fou) | aucun matériau sous Fernite Alloy (inchangé) |
| E4 | stock partiel réparti sur deux étapes (30 + 20) | identique à E1 |
| E5 | stock partiel d'un intermédiaire présent dans deux branches | le stock est consommé à la première rencontre et le reste de la demande de la seconde branche est entier (R2) |
| E6 | projet de deux produits racines, l'un sans blueprint (Robotics) | la liste du produit valide est inchangée ; `failedProducts = [{typeId: Robotics, reason: "No blueprint or reaction found for product type ID …"}]` |
| E7 | projet dont le seul produit n'a pas de blueprint | liste de matériaux vide, `failedProducts` contient le produit |
| E8 | produit racine avec un type absent du SDE | `failedProducts` porte `reason = "Types missing from the SDE: …"` |
| E9 | structure non optimale + stock partiel | `extraQuantity` calculé sur le sous-arbre réduit (R3) |
| E10 | provider : réponse API | `failedProducts` présent, `[]` sans erreur |

Le `test-writer` calcule les quantités exactes d'E1, E4, E5 et E9 sur le SDE golden (reconstruction de l'arbre pour la quantité restante) et les met dans des variables nommées.

## Cas limites

- Intermédiaire acheté avec stock partiel : déjà traité par #102 (acheter le reste, ne pas descendre), inchangé.
- Intermédiaire blacklisté avec stock partiel : feuille, acheter le reste (inchangé).
- Le produit racine lui-même en stock : hors périmètre (le builder ne lit pas le stock des produits racines aujourd'hui).

## Hors périmètre

- `StockpileStatus` : la même règle R9 sur les cibles de stockpile (`StockpileStatusCharacterizationTest::testPartialStockOfAnIntermediateDoesNotReduceTheTargetsBelowItIssue20`) est un autre service ; issue séparée #118.
- `ProductionCostService` (autre appelant de `getShoppingList()`) : il garde son comportement (les produits en erreur n'entrent pas dans le coût) ; seul l'accès aux matériaux s'adapte au nouveau format de retour.
- L'interprétation des runs comme quantité pour les produits à plusieurs unités par run (#2), la règle de bonus de structure (#6).
- `TypeNameResolver` et noms de types (#115, en cours dans un autre thread).

## Décision prise en autonomie

- **Forme du signalement (R4).** Sylvain absent : choix le plus conservateur, la liste des autres produits reste calculée et l'erreur est ajoutée à la réponse (`failedProducts` avec `typeId` et le message d'erreur brut), plutôt que de faire échouer toute la liste (4xx) ou d'inventer un nom de produit. Pas de nouveau terme de glossaire.
- **Calcul du sous-arbre réduit (R1).** Reconstruction de l'arbre de l'intermédiaire pour la quantité restante (runs entiers, ME et bonus inchangés), plutôt qu'une réduction proportionnelle des quantités : c'est la règle R9 du noyau (« stock consommé avant le calcul des runs ») et elle garde l'arrondi par run.
- **Stockpile.** Exclu et reporté dans une issue séparée pour garder un ticket limité à la liste d'achats.

## Glossaire

Aucun terme ajouté.

## Tests impactés

- `IndustryShoppingListBuilderGoldenCharacterizationTest::testShoppingListCurrentlyIgnoresThePartialStockOfAnIntermediateIssue20` → remplacé par E1.
- `…testShoppingListCurrentlySilentlyDropsARootProductWithoutBlueprintIssue20` → remplacé par E6.
- `…testShoppingListOfAProductWithoutBlueprintIsCurrentlyEmptyIssue20` → remplacé par E7.
- Tous les tests qui lisent le retour de `getShoppingList()` (builder, `ShoppingListProviderGoldenCharacterizationTest`, `ProductionCostService`) s'adaptent au nouveau format de retour s'il change.
