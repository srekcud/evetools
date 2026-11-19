# Plan : stock d'intermédiaires dans la BOM d'un projet

2026-11-11. Brouillon avant `/spec`. Décisions D1 à D3 tranchées par Sylvain le 2026-11-11 : les trois options recommandées.

## Constat (code lu le 2026-11-11)

| Endroit | Ce qui est lu aujourd'hui |
|---|---|
| Plan du projet, `IndustryProjectService::planOf` | aucun `startingStock` : tous les jobs intermédiaires et leurs feuilles sont prévus, même si l'intermédiaire est déjà en hangar |
| Vérification du container, `ProjectContainerService::verifyContainer` | lignes `isJob = false` seulement (`findMaterialsByProject`) |
| « Approvisionné » de la Chaîne, `ChainMaterialSupply::allocate` | apports des lignes de matériaux ; un intermédiaire consommé par un job est sauté (`continue`) |
| Apport `material` sur une ligne de job | accepté par `ProjectContributionService::submit` (pas de contrôle `isJob`), incrémente `fulfilledQuantity`, mais ne change pas le plan : le sous-arbre reste à produire et à acheter |
| Stockpile | déduit bien le stock des intermédiaires (`StockpileStartingStockReducer`) |

Le noyau sait déjà faire : R9 de `docs/specs/industry-engine.md` (stock consommé avant les runs, sous-arbre réduit, couvert en totalité = aucun job ni matériau en dessous). Le travail est côté projet.

## Périmètre : projets solo seulement (Sylvain, 2026-11-11)

L'apport d'intermédiaire (lots 1 et 3) n'existe que sur un projet solo (`IndustryProject::isSoloProjectOf` : aucun autre membre accepté que le propriétaire).
- Projet de groupe : apport `material` sur une ligne de job refusé (`\DomainException` explicite), bouton et proposition absents du front.
- Projet solo qui devient de groupe (invitation acceptée) : les apports d'intermédiaire déjà approuvés restent comptés dans le plan (sinon la BOM regrossirait) et restent des apports du propriétaire dans la répartition ; on ne peut plus en ajouter.
- Lots 2 (Chaîne, container) et 4 (surplus visible) : corrections d'affichage valables pour tous les projets, elles ne déduisent rien.

## Principe proposé

Un intermédiaire en stock entre dans le projet comme un **apport `material` approuvé sur sa ligne de job**. La somme de ces apports par type devient le `startingStock` de `planOf`. Effets :
- un seul calcul : tous les appelants de `planOf` (BOM, achat/fabrication, structures, besoins des jobs, recherche) voient le même plan réduit ;
- la valorisation est déjà décidée (prix Jita du jour d'apport, mémoire « module industrie unifié »), le coût du projet compte donc l'intermédiaire à sa valeur ;
- en solo, l'apport est approuvé tout de suite (`submit` l'approuve déjà), donc la BOM se réduit à la saisie, sans file d'approbation.

## Lots

### Lot 1 : le plan consomme les intermédiaires apportés (backend)
1. `planOf` passe `startingStock` = apports `material` approuvés des lignes de job `component` et `final`, par typeId (le produit final compris : 3 vaisseaux en stock = 3 de moins à produire).
2. Approbation ou annulation d'un tel apport : `buildBom(refuseToDropWorkedLines: true)`.
3. Ligne de job : `requiredQuantity` reste la **demande** (stock + produit), sinon l'apport dépasse le reste de la ligne. Nouveau champ ou dérivé « dont stock » à fixer dans la spec ; les runs et le surplus viennent du plan réduit.
4. Garde-fous : apport qui ferait tomber un job déjà lié à un job ESI, ou une ligne de feuille déjà apportée au-delà du nouveau besoin → refus explicite (D3).
5. Projet de groupe : apport sur une ligne de job refusé ; apports déjà approuvés du temps du solo toujours comptés.
6. Tests : un exemple chiffré sur le golden (Fernite Carbide / Hydrogen Fuel Block, déjà utilisé par le Stockpile), couverture partielle, couverture totale, refus D3, refus en groupe, projet passé en groupe.

### Lot 2 : Chaîne et container voient les intermédiaires
1. `ChainMaterialSupply` : un job n'est « approvisionné » que si ses intermédiaires sont couverts (jobs liés livrés + apports de stock), plus de `continue`.
2. `verifyContainer` : vérifie aussi les intermédiaires attendus dans le container (quantité requise des lignes de job consommées par un autre job).
3. Front : badge et bannière du container sur ces lignes.

### Lot 3 : proposer le stock détecté (D2)
Projet solo seulement, à l'ouverture d'un projet en brouillon (ou bouton « Utiliser mon stock ») : intermédiaires du plan présents dans les assets du propriétaire (personnels + container du projet), quantité proposée = min(stock, demande). Le propriétaire valide, ce qui crée l'apport du lot 1. Rien n'est déduit sans action.

### Lot 4 : rendre visible le surplus d'intermédiaires
`ProjectSurplusBuilder` liste aussi le surplus des jobs intermédiaires (`surplusQuantity`, runs entiers) d'un projet terminé, pour que le joueur sache ce qu'il pourra réinjecter au projet suivant via le lot 3. Lecture seule, comme #352.

## Décisions (tranchées : option recommandée retenue pour chacune)

- **D1, mécanisme** : apport approuvé sur la ligne de job (retenu) plutôt qu'un stock de départ saisi à part. Il reste utile en solo : coût du projet valorisé, et l'apport survit au passage en groupe.
- **D2, détection** : proposition depuis les assets (lot 3, recommandé) ou saisie manuelle seule.
- **D3, projet démarré** : refuser un apport qui supprime un job déjà lié ou une feuille déjà apportée (recommandé) ou réduire seulement les jobs non lancés et laisser le reste en surplus.

## Glossaire

« Stock de départ » dit aujourd'hui « La BOM d'un projet n'en passe aucun » : à mettre à jour au lot 1. Terme proposé pour l'apport : **apport d'intermédiaire** (à ajouter).

## Risques

- Une BOM qui bouge à chaque approbation : les lignes de feuilles apportées, réservées ou achetées doivent survivre au recalcul (`reconcileBomLines`), à couvrir par test.
- Plafond `MAX_BOM_LINES` : sans effet, le plan ne fait que rétrécir.
