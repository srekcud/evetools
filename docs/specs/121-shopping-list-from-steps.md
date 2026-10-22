# Spec #121 — La liste d'achats et le coût de production sont dérivés des étapes

## Contexte

La liste d'achats (`IndustryShoppingListBuilder::getShoppingList()`) relit `IndustryTreeService::buildProductionTree()` : un nœud par produit et par branche, sur les runs totaux. Elle ignore le découpage des étapes (#73) et leur agrégation (#69), et lit les runs d'une racine comme une quantité (#2). Le coût de production (`ProductionCostService`) et l'estimation figée à la création du projet (`CreateProjectProcessor`) la lisent telle quelle. Les étapes et la liste d'achats divergent.

Approche retenue (A, décision du 2026-10-22) : la liste d'achats se calcule sur les étapes persistées du projet. Approche B (rang 5 sur le noyau `src/Industry`) écartée : elle oblige à étendre le Domain (durée de job, structure par job) pour un seul appelant et contredit les étapes éditées à la main.

## Règles métier

- **R1 — Source.** La liste d'achats se calcule sur les étapes de production du projet (hors copie), jamais sur l'arbre. Chaque étape est un job : ses runs, son ME, sa structure (`getStructureBonusForStep`).
- **R2 — Produit fabriqué / matière première.** Un type produit par une étape de production non achetée, et absent de la blacklist actuelle, est **fabriqué**. Tout autre matériau d'un job est une **matière première** : quantité = R1 du noyau (`max(runs, ceil(round(base × runs × modificateurs, 2)))`), job par job, sommée sur tous les jobs.
- **R3 — Ordre.** Les produits sont traités consommateurs avant matériaux. Une **racine** (produit consommé par aucune étape) garde ses runs persistés ; son stock n'est pas lu (inchangé).
- **R4 — Besoin d'un produit consommé.** Besoin = Σ quantités de ses étapes + Δ, avec Δ = Σ sur les jobs consommateurs de [R1(runs effectifs) − R1(runs persistés)]. À fabriquer = max(0, besoin − stock). Si Δ = 0 et stock = 0 : runs persistés de chaque job (découpage conservé). Sinon : runs = `ceil(à fabriquer / unités par run)`, répartis sur le même nombre de jobs (`SplitGroupQuantities::runsBySplitIndex`) ; un job à 0 run ne consomme aucun matériau.
- **R5 — Acheté ou blacklisté.** Une étape achetée, ou dont le produit est blacklisté depuis, ne fabrique rien : on achète max(0, besoin − stock), sans sous-arbre ; ses matériaux reçoivent un Δ négatif.
- **R6 — Stock.** Stock d'un type = Σ `inStockQuantity` de ses étapes, consommé une seule fois.
- **R7 — `extraQuantity`.** Les matières premières sont calculées avec le bonus de la meilleure structure (`quantity`) et avec celui de l'étape ; `extraQuantity = max(0, écart)`, clé présente seulement si une étape est sous-optimale. Les runs des intermédiaires sont ceux de R4 dans les deux calculs.
- **R8 — Produit en erreur.** Étape de production, à toute profondeur, dont la recette est introuvable ou dont un type (produit, matériau) n'a pas de nom dans le SDE : `failedProducts` porte `{typeId du produit de l'étape, reason}` (messages actuels), typeId croissant, une fois par produit. Ses matériaux sont absents ; les étapes en dessous gardent leur besoin persisté.
- **R9 — API et coût.** Forme du retour inchangée ; #119 s'applique tel quel (un produit en erreur rend le coût matériaux inconnu).

## Exemples (SDE golden, sans structure sauf mention)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Quantum Microprocessor 32 runs ME 10 / TE 20, durée max 0,01 j (11 + 11 + 10), Titanium Carbide blacklisté | Titanium Carbide 490 | **491** (169 + 169 + 153) |
| E2 | E1 : autres feuilles (agrégation #69) | Oxygen Isotopes 1620, Robotics 11, Liquid Ozone 3465 | 405, 4, 1260 |
| E3 | E1 sans découpage (durée max 2 j) | 490 | 490 (garde-fou) |
| E4 | E1 sans blacklist, étape Titanium Carbide achetée | 490 | 491 achetés, aucune matière sous Titanium Carbide |
| E5 | E1 sans blacklist, 491 Titanium Carbide en stock | — | liste de E1 sans la ligne Titanium Carbide (Titanium Chromide, Silicon Diborite et Oxygen Fuel Block passent de 200 / 200 / 25 à 100 / 100 / 20, toujours 1 run) |
| E6 | E1 sans blacklist ni stock | — | identique à E3 hors blacklist : le découpage ne change aucune feuille (1 run de 10 000) |
| E7 | Nomad 1 run ME 10, étapes de la factory sans découpage | 29 feuilles sur 63 fausses | 63 feuilles = Ravworks |
| E8 | Nitrogen Fuel Block 25 runs, étape racine seule | Nitrogen Isotopes 405 | 10125 (#2) |
| E9 | Nitrogen Fuel Block 1 run en station, Raitaru meilleure | 401 + 4 | inchangé |
| E10 | Étape Robotics (sans recette) + Nitrogen Fuel Block | `failedProducts` Robotics | inchangé |

## Cas limites (validés le 2026-10-22)

- Projet sans étape de production : liste vide, aucun produit en erreur.
- Matériau fabricable sans étape (étape supprimée) : acheté comme une matière première.
- Produit en erreur à toute profondeur, pas seulement à la racine (R8).
- Stock partiel qui ne change pas le nombre de runs : liste identique.
- Groupe découpé dont les runs effectifs tombent sous le nombre de jobs : jobs à 0 run ignorés.
- Après un découpage manuel, les petits-enfants gardent leur besoin persisté (#73, R3) : la liste suit les étapes, la régénération remet tout en cohérence.

## Hors périmètre

Arbre affiché (`ProjectProvider`, issue séparée) ; Buy vs Build, marges, stockpile, Group Industry ; plafond BPC ; bonus par catégorie de matériau dans la factory (#6).

## Effets annoncés

Les chiffres de la liste d'achats et du coût de production baissent en prod pour les projets à intermédiaires partagés (#2 et #69 corrigés pour la liste et le coût ; commentaire sur ces issues sans les fermer : l'arbre et les autres appelants restent).

## Glossaire

- « Produit en erreur » : « produit d'une étape de production » au lieu de « produit racine ».
- « Stock de départ » : symbole `IndustryTreeService::buildIntermediateTree()` retiré s'il n'est plus appelé.
