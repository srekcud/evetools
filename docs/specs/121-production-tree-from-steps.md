# Spec #121 (arbre affiché) — L'arbre de production est dérivé des étapes

## Contexte

La liste d'achats et le coût de production sont dérivés des étapes persistées (`docs/specs/121-shopping-list-from-steps.md`). L'arbre affiché dans la vue « arbre » des étapes (`ProjectProvider::provide()` → `IndustryTreeService::buildProductionTree()`) ne l'est pas : un nœud par produit et par branche, runs arrondis par branche. Il ignore le découpage d'un groupe (#73) et l'agrégation de la demande d'un intermédiaire consommé par plusieurs branches (#69, côté arbre). L'arbre, les étapes et la liste d'achats divergent.

L'arbre est dérivé des étapes avec la même passe que la liste d'achats (une classe, deux appelants). `IndustryTreeService` reste en place pour ses autres appelants (factory, Stockpile, Group Industry, catalogue SDE). La migration sur le noyau (rang 4) remplacera cette dérivation ; le correctif reste ciblé.

## Règles métier

- **R1 — Source.** L'arbre se calcule sur les étapes de production du projet (hors copie), avec les besoins et les runs effectifs de R4 et R5 de la spec de la liste d'achats. `IndustryTreeService` n'est plus appelé par `ProjectProvider`.
- **R2 — Un nœud par produit.** Tous les jobs d'un produit forment un seul nœud. `runs` = Σ des runs effectifs de ses jobs (la racine garde ses runs persistés) ; `quantity` = besoin avant stock. La racine est le produit du projet ; les autres racines (étapes ajoutées à la main) ne sont pas affichées.
- **R3 — Matériaux d'un nœud.** Matériaux SDE du blueprint, dans l'ordre du SDE. `quantity` d'une ligne = Σ sur les jobs de R1 du noyau (runs effectifs, ME de l'étape, bonus de la structure de l'étape) ; un job à 0 run ne compte pas. `isBuildable` = le type a une étape de production et n'est pas blacklisté.
- **R4 — Intermédiaire à plusieurs consommateurs.** Un seul nœud, sous son premier consommateur (parcours en profondeur). Chez les autres consommateurs : une ligne de référence (`isBuildable` vrai, sans sous-arbre) portant la quantité consommée par ce parent ; la vue l'affiche comme « voir plus haut ».
- **R5 — Étape achetée, stock, blacklist.** Étape achetée : le nœud est gardé (la case « Acheté » reste décochable), `runs` 0, aucun matériau. Stock : `runs` après déduction du stock, `quantity` = besoin ; un nœud à 0 run n'a pas de matériaux. Produit blacklisté : ligne feuille (`isBuildable` faux).
- **R6 — Structure.** `structureName` et `structureBonus` = ceux de l'étape (job 0 pour un groupe découpé).
- **R7 — Erreurs.** Racine sans recette ou sans étape de production, ou type sans nom dans le SDE dans un nœud affiché : `tree` est `null` (comportement actuel). Intermédiaire sans recette : ligne feuille.
- **R8 — Forme.** Les champs du nœud lus par le front sont inchangés (`productTypeId`, `productTypeName`, `quantity`, `runs`, `activityType`, `blueprintTypeId`, `structureName`, `structureBonus`, `materials[]`).

## Exemples (SDE golden, sans structure, ME 10 / TE 20)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| X1 | Quantum Microprocessor 32 runs, durée max 0,01 j (11 + 11 + 10), Titanium Carbide blacklisté | ligne Titanium Carbide 490 ; Phenolic Composites 173 ; 23 nœuds | 491 ; 174 ; 16 nœuds ; racine 32 runs |
| X2 | X1 sans blacklist | nœud Titanium Carbide 490 u., 1 run ; Oxygen Fuel Block : 7 nœuds de 5 u. | Titanium Carbide 491 u., 1 run ; Oxygen Fuel Block : 1 nœud de 25 u., 1 run, et 4 références |
| X3 | X2, étape Titanium Carbide achetée (ou 491 en stock) | 490 u., 1 run, 3 matériaux | 491 u., 0 run, aucun matériau |
| X4 | Nomad 1 run, durée max 2 j | 286 nœuds ; Hydrogen Fuel Block 50 nœuds (345 runs) | 66 nœuds ; Hydrogen Fuel Block 1 nœud, 12855 u., 322 runs (161 + 161), et 12 références ; Nitrogen Fuel Block 4225 u., 106 runs, et 7 références |
| X5 | Nitrogen Fuel Block 25 runs, étape racine seule | 1 run, ×25 | 25 runs, ×1000 |
| X6 | Étapes de la factory, Nitrogen Fuel Block 25 runs | 1 run, ×25 | inchangé |

Chiffres issus d'un prototype jetable sur le SDE golden ; à recouper par les tests rouges.

## Cas limites

- Projet sans étape de production : `tree` null.
- Groupe découpé aux jobs de structures différentes : structure du job 0.
- Groupe découpé dont les runs effectifs tombent sous le nombre de jobs : jobs à 0 run ignorés.
- Cycle dans les étapes : l'erreur remonte (comme dans la liste d'achats).

## Hors périmètre

`IndustryTreeService` et ses autres appelants (factory, Stockpile, Group Industry, catalogue SDE) ; liste d'achats et coût (déjà dérivés) ; agrégation à la création des étapes par la factory (#69, spec `69-steps-aggregate-intermediate-need.md`) ; bonus par catégorie de matériau (#6).

## Effets annoncés

L'arbre affiché change pour les projets à groupe découpé ou à intermédiaire partagé : moins de nœuds, un seul nœud par intermédiaire, runs et quantités égaux à ceux des étapes.

## Glossaire

Ajout proposé : **Runs effectifs** (runs d'une étape après besoin agrégé et stock, R4 de la spec de la liste d'achats).
