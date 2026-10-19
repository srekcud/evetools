# Spec #118 — Stockpile : le stock partiel d'un intermédiaire réduit les cibles de son sous-arbre

## Contexte

L'import d'un produit dans le Stockpile (`StockpileService::importFromBlueprint()`) aplatit l'arbre de production en une cible par type (`IndustryStockpileTarget` : typeId, quantité cible, étape, produit source). Les runs et le ME de l'import ne sont pas conservés, ni la structure de l'arbre. Au statut (`getStockpileStatus()`), chaque cible est comparée à son stock de façon indépendante : avec 5 Frames sur 10 en stock, les 700 Ore nécessaires aux 10 Frames restent dus en entier (`StockpileStatusCharacterizationTest::testPartialStockOfAnIntermediateDoesNotReduceTheTargetsBelowItIssue20`).

Règle cible : spec du noyau `docs/specs/industry-engine.md`, R9 (« un intermédiaire couvert en partie par le stock réduit d'autant son propre sous-arbre ; couvert en totalité : aucun matériau en dessous »), déjà appliquée à la liste d'achats d'un projet par #20.

## Règles métier

- **R1 — Import de stockpile enregistré.** Chaque import enregistre une ligne `IndustryStockpileImport` (utilisateur, produit, runs, ME, date de création). Le mode `replace` supprime les imports existants de l'utilisateur avant d'enregistrer le sien ; le mode `merge` ajoute une ligne. Les cibles sont créées ou fusionnées comme aujourd'hui (inchangé).
- **R2 — Réduction par le stock de départ.** Au statut, pour chaque import, dans l'ordre de création :
  - l'arbre complet est reconstruit avec les runs et le ME de l'import (`IndustryTreeService::buildProductionTree()`, structures actuelles de l'utilisateur) ;
  - l'arbre réduit est le même arbre où le stock des types fabricables (produit racine compris) est consommé avant le calcul des runs : couvert en totalité, rien en dessous ; couvert en partie (`0 < S < Q`), sous-arbre reconstruit pour `Q − S` (runs `ceil((Q − S) / unités par run)`, même ME et bonus de structure, comme `IndustryTreeService::buildIntermediateTree()` dans #20) ;
  - la réduction d'un type est `besoin dans l'arbre complet − besoin dans l'arbre réduit`, sommée sur tous les imports.
- **R3 — Stock consommé une seule fois.** Le stock d'un type est un réservoir commun à tous les imports, consommé dans l'ordre de création des imports puis dans l'ordre topologique de chaque arbre : ce qui est consommé pour un import n'est plus disponible pour le suivant.
- **R4 — Cible restante.** Pour chaque cible : `cible restante = max(0, quantité cible − réduction du type)`. La quantité cible stockée n'est jamais modifiée par le statut. Une cible éditée à la main garde son écart (Ore 1000 au lieu de 700 → cible restante 650). Le stock d'un type ne réduit jamais sa propre cible : il est comparé à sa cible restante (Frame : cible restante 10, stock 5).
- **R5 — Tout le statut sur la cible restante.** Pourcentage, statut (`met`/`partial`/`critical`), coût du déficit, liste d'achats, santé des étapes, santé du pipeline, goulot et produit prêt (`estOutput`, cible remplie) utilisent la cible restante au lieu de la quantité cible. Les règles d'arrondi de #96 s'appliquent sur la cible restante ; une cible restante de 0 est remplie.
- **R6 — Import sans données (ancien import).** Une cible avec produit source dont aucun import enregistré ne porte ce produit n'est pas réduite (cible restante = quantité cible). Le statut liste ces produits dans `importsWithoutPlan` (typeIds triés, sans doublon) pour inviter à réimporter. Une cible manuelle (sans produit source) n'est jamais réduite et n'y figure pas.
- **R7 — Produit en erreur.** Si l'arbre d'un import ne peut pas être reconstruit (aucun blueprint ni réaction, type absent du SDE), cet import ne réduit rien, le reste du statut est calculé, et le statut porte `failedProducts` : `{typeId, reason}` par import en erreur (message brut de l'exception), trié par typeId, sans doublon. Il ne consomme pas de stock du réservoir.
- **R8 — API.** `GET` du statut de stockpile expose, par item, `remainingTargetQuantity` (en plus de `targetQuantity`, inchangé) ; au niveau du statut, `importsWithoutPlan` et `failedProducts` (`[]` quand rien à signaler).
- **R9 — Frontend.** Une cible dont la cible restante diffère de la quantité cible affiche « restante / cible » (ex. `350 / 700`). Un avertissement liste les produits de `importsWithoutPlan` (« réimporter pour tenir compte du stock des intermédiaires ») et ceux de `failedProducts` avec leur raison.

## Exemples chiffrés

Fixture : Widget (produit final, 1 unité par run) demande 10 Frames par run ; Frame (intermédiaire, 1 unité par run) demande 70 Ore par run. Import de 1 run de Widget, ME 0 : cibles Widget 1, Frame 10, Ore 700. Prix : Widget 1000, Frame 50, Ore 2.

| # | Situation | Attendu |
|---|---|---|
| E1 | stocks Widget 0, Frame 5, Ore 0 | Ore : cible restante 350, 0 %, `critical`, déficit 700.0 ; Frame : cible restante 10, 50 %, `partial`, déficit 250.0 ; Widget : cible restante 1 |
| E2 | stocks Widget 0, Frame 5, Ore 350 | Ore `met` 100 %, absent de la liste d'achats ; `estOutput` : Widget non prêt (Frame non remplie) |
| E3 | stocks Widget 0, Frame 10, Ore 0 | Ore : cible restante 0, `met`, déficit 0.0, absent de la liste d'achats ; Widget prêt |
| E4 | stocks Widget 1, Frame 0, Ore 0 | Frame et Ore : cible restante 0, `met` ; santé du pipeline 100.0 ; aucun goulot |
| E5 | Frame à 2 unités par run et 140 Ore par run (import identique : Frame 10, Ore 700), stock Frame 5 | reste 5 Frames → 3 runs → 420 Ore : Ore cible restante 420 |
| E6 | cible Ore éditée à 1000, stock Frame 5 | Ore cible restante 650 |
| E7 | deux imports `merge` de 1 run de Widget (cibles Frame 20, Ore 1400), stock Frame 5 | le 1er import consomme les 5 Frames (réduction Ore 350), le 2nd n'en a plus : Ore cible restante 1050 ; Frame cible restante 20 |
| E8 | imports Widget puis Gadget (Gadget demande aussi 10 Frames → Frame 20, Ore 1400), stock Frame 15 | Widget consomme 10 Frames (Ore −700), Gadget 5 (Ore −350) : Ore cible restante 350 |
| E9 | cibles du fixture sans import enregistré (ancien import), stock Frame 5 | aucune réduction (Ore 700, comme aujourd'hui) ; `importsWithoutPlan = [Widget]` |
| E10 | import dont l'arbre ne se reconstruit plus | ses cibles gardent leur quantité cible ; `failedProducts = [{typeId: Widget, reason: "No blueprint or reaction found for product type ID …"}]` |
| E11 | import `replace` après deux imports | une seule ligne d'import, celle du dernier ; import `merge` : une ligne de plus |
| E12 | API : statut sans réduction | `remainingTargetQuantity = targetQuantity` sur chaque item, `importsWithoutPlan = []`, `failedProducts = []` |
| E13 | front : E1 | la cible Ore affiche `350 / 700` ; la cible Frame affiche `10` ; E9 et E10 affichent l'avertissement |

## Cas limites

- ME d'import > 0 : arbres complet et réduit reconstruits avec le même ME ; la réduction suit les arrondis du ME.
- Stock supérieur à la demande d'un intermédiaire : couvert en totalité, le surplus reste dans le réservoir pour les imports suivants.
- Réduction supérieure à la quantité cible (cible éditée à la baisse) : cible restante 0, jamais négative.
- Cible supprimée par l'utilisateur : l'import reste ; la réduction ne s'applique qu'aux cibles qui existent.
- Structures de l'utilisateur modifiées depuis l'import : arbres complet et réduit reconstruits tous deux avec les structures actuelles, la réduction reste cohérente.
- Aucune cible : statut vide inchangé, `importsWithoutPlan = []`, `failedProducts = []`.

## Hors périmètre

- Aperçu d'import (`previewImport`) : inchangé.
- Liste d'achats et projets industriels (#20, fermée).
- Agrégation de la demande d'un intermédiaire entre branches (#69) : l'arbre reste celui de `buildProductionTree()`.
- Runs comme quantité pour les produits à plusieurs unités par run (#2) et règle de bonus de structure (#6).
- Migration des anciens imports : aucune déduction du ME depuis les cibles (R6).

## Glossaire

Termes ajoutés à [`docs/glossary.md`](../glossary.md) :

- **Import de stockpile** (*Stockpile import*) : produit, runs et ME d'un import d'arbre de production dans le Stockpile ; un par import, vidés par un import `replace`. `IndustryStockpileImport`.
- **Cible restante** (*Remaining target*) : quantité cible d'une cible de Stockpile diminuée de la réduction due au stock de départ des types fabricables au-dessus d'elle, jamais négative (spec #118). `remainingTargetQuantity`.

## Tests impactés

- `StockpileStatusCharacterizationTest::testPartialStockOfAnIntermediateDoesNotReduceTheTargetsBelowItIssue20` → remplacé par E1.
- Les tests de `StockpileService` et du provider qui construisent le service s'adaptent à la nouvelle dépendance (repository des imports) ; leurs cibles sans import enregistré relèvent de R6 (comportement inchangé, `importsWithoutPlan` non vide pour celles qui ont un produit source).
