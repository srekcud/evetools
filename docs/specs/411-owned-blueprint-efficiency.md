# Spec #411 — ME/TE possédés dans le plan et plafond de runs d'un BPO

Circuit M, mode nuit. Suite de #357 (bibliothèque de blueprints). Issue **partielle** : le signalement d'un blueprint utilisé par un job en cours est hors tranche (Blocage au journal : `CachedIndustryJob` ne porte que `blueprintTypeId`, pas l'`itemId` du blueprint ; un signal par type serait faux dès que deux exemplaires existent).

## Vocabulaire
**Blueprint possédé utilisé** (`OwnedBlueprintUse`) : ME, TE et nature (BPO ou BPC) du meilleur blueprint possédé qui produit un produit. Bibliothèque et visibilité des divisions corpo : celles de #357 (`OriginalBlueprintFinder`).

## Règles
R1. Choix du blueprint d'un produit : parmi les blueprints possédés visibles (personnels et corpo), le plus haut ME, puis le plus haut TE, puis le BPO avant le BPC. Un blueprint dont le produit est celui du **producteur** retenu par le SDE.
R2. Le plan d'un projet (`ProductionPlanning`) utilise le ME et le TE du blueprint choisi à la place des valeurs saisies (cibles) ou par défaut (ME 10 / TE 20 des intermédiaires fabriqués). Une cible dont le produit est possédé et une autre du même produit avec un autre ME saisi fusionnent en une seule cible (runs additionnés) une fois les valeurs remplacées.
R3. Une réaction garde ME 0 et TE 0 : sa formule n'est pas recherchable, la valeur possédée n'est pas lue.
R4. Le blueprint choisi est un BPO : aucun plafond de runs, le job n'est pas découpé (cible comme intermédiaire, fabrication comme réaction). Un BPC : le plafond SDE (`maxProductionLimit`) reste.
R5. Produit sans blueprint possédé : comportement actuel inchangé (valeurs saisies ou par défaut, plafond SDE).
R6. La BOM porte le ME/TE possédés (`meLevel`, `teLevel` des lignes de job) ; les produits du projet (`IndustryProjectProduct`) gardent la valeur saisie, rien n'est écrasé.
R7. Le Stockpile, la marge de profit et l'analyse achat/fabrication ne lisent pas les blueprints possédés (hors tranche).

## Exemples (produit à plafond 5, ME saisi 0, TE saisi 0, 12 runs)
| Blueprint possédé | Jobs (runs) | ME / TE des jobs |
|---|---|---|
| aucun | 4, 4, 4 | 0 / 0 |
| BPC ME 8 TE 16 | 4, 4, 4 | 8 / 16 |
| BPO ME 9 TE 18 | 12 | 9 / 18 |
| BPO ME 5 et BPC ME 8 | 4, 4, 4 | 8 / 16 (R1 : ME d'abord) |
| BPO ME 8 et BPC ME 8 | 12 | 8 / TE du BPO (R1 : BPO avant BPC à ME et TE égaux) |

## Hors périmètre / décisions
- Signalement « utilisé par un job en cours » (voir plus haut) : commentaire sur l'issue, pas de `Closes`.
- Runs restants d'un BPC possédé : non lus, le plafond SDE reste.
- Un produit à plusieurs blueprints de fabrication dans le SDE : la correspondance se fait par produit du blueprint possédé, non par producteur (approximation connue, rare).
