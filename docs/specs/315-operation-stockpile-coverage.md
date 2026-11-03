# Spec #315 — Minage : part du déficit du Stockpile couverte par une opération

**Statut : validée en autonomie en mode nuit le 2026-11-03, à relire par Sylvain.**

Lot `group:mining-ops`, partie valeur du minerai. Circuit `circuit:s` avec logique métier : rouge puis vert, sans migration. Dépend de #263 (quantités minées d'un participant, dans `main`) et de #269 (rendement du profil de retraitement, dans `main`). Le Stockpile est lu seulement : `StockpileService::getStockpileStatus()` n'est pas modifié.

## Contexte

Après une opération, un participant veut savoir ce que sa part de minerai, retraitée à son rendement, apporte au déficit en minéraux de son Stockpile (« cette opération couvre 38 % du déficit de Pyerite de ton Stockpile »). C'est **sa propre part** (les quantités de ses personnages inscrits, `MinedQuantityCalculator::ofParticipant`), pas celle de toute l'opération.

## Règles métier

- **D1 — Périmètre.** `GET /api/mining-operations/{operationId}/stockpile-coverage` (`ROLE_USER`, `uriVariables` avec `Link` sur `MiningOperationResource`) : l'utilisateur doit participer à l'opération (`MiningOperationAccess::assertCanParticipate`), il ne lit que sa propre part. Opération inexistante : 404 ; non participant : l'erreur d'accès existante.
- **D2 — Quantité minée.** Part de l'utilisateur = `MinedQuantityCalculator::ofParticipant` de son membre. État `known` : calcul. État `measuring` ou `unknown` : aucune couverture, `state` repris tel quel et `unknownReason` (`MinedQuantityUnknownReason`) quand il existe ; jamais une couverture à 0.
- **D3 — Minéraux obtenus.** Pour chaque type miné de quantité > 0 qui a des matériaux de retraitement dans le SDE (`InvTypeMaterialRepository::findByTypeIds`), chaque minéral reçoit `quantité minée × quantité du matériau × rendement / portionSize du type miné`, additionné entre les types minés (valeur décimale, non arrondie). Un type miné sans matériau (gaz) n'apporte rien et n'est pas une erreur. Un `portionSize` absent ou ≤ 0 rend le minéral de ce type inconnu : `coverageUnknownReason` `portion_size_unknown` pour les minéraux concernés.
- **D4 — Rendement.** Le rendement vient du profil de retraitement de l'utilisateur (`ReprocessingProfile::getReprocessYield()`). Sans profil, ou rendement `null` : `yieldKnown` false, aucune couverture (`coveragePercent` `null`, `producedQuantity` `null` pour chaque minéral), jamais un rendement par défaut.
- **D5 — Déficit.** Déficit d'un minéral = `deficit` de la ligne correspondante de `StockpileService::getStockpileStatus($user)['shoppingList']` (`typeId` du minéral). Un minéral absent de la liste (cible satisfaite ou pas de cible) n'a pas de déficit : `hasDeficit` false, `deficit` 0, `coveragePercent` `null`.
- **D6 — Part couverte.** Pour un minéral avec déficit > 0 : `coveragePercent = min(100, producedQuantity / deficit × 100)`, arrondi à 2 décimales, et `coversWholeDeficit` vrai quand `producedQuantity ≥ deficit`. Aucun arrondi sur `producedQuantity`.
- **D7 — Réponse.** `operationId`, `state`, `unknownReason`, `yieldKnown`, `reprocessYield`, `minerals` : une ligne par minéral produit (`typeId`, `typeName`, `producedQuantity`, `deficit`, `hasDeficit`, `coveragePercent`, `coversWholeDeficit`), triée par `typeId`. Les minéraux sans déficit y figurent (`hasDeficit` false) pour que le front les signale. Part sans aucun minéral (rien miné, ou rien de retraitable) : `minerals` vide.
- **D8 — Front.** Dans le panneau de l'opération (vue de clôture, étape 19 de la maquette) : une carte « Ton Stockpile » listant « cette opération couvre N % du déficit de {minéral} » pour chaque minéral avec déficit, « sans déficit dans ton Stockpile » pour les autres, « rendement inconnu : configure ton rendement de retraitement » avec lien vers le panneau de #269 quand `yieldKnown` est faux, « quantités en cours de mesure » ou « quantités inconnues » selon `state`. Le front n'est ajouté que si l'écran de clôture de l'opération existe dans `main` (voir hors périmètre).

## Exemples chiffrés

Types : Veldspar (portion 100, matériau Tritanium 400), Scordite (portion 100, matériaux Tritanium 150 et Pyerite 90), Fullerite-C50 (gaz, aucun matériau). Rendement 0,8.

| # | Cas | Attendu |
|---|---|---|
| C1 | Part : 10 000 Veldspar ; déficit Tritanium 200 000 | Tritanium produit 10 000 × 400 × 0,8 / 100 = 32 000 ; `coveragePercent` 16,0 |
| C2 | Part : 10 000 Veldspar et 5 000 Scordite ; déficit Tritanium 100 000 et Pyerite 3 000 | Tritanium 32 000 + 5 000 × 150 × 0,8 / 100 = 6 000 → 38 000, `coveragePercent` 38,0 ; Pyerite 3 600 ≥ 3 000, `coveragePercent` 100,0, `coversWholeDeficit` true |
| C3 | Pyerite produit, aucune cible Pyerite dans le Stockpile | ligne Pyerite `hasDeficit` false, `deficit` 0, `coveragePercent` `null` |
| C4 | Part : 2 000 Fullerite-C50 seulement | `minerals` vide, `state` known |
| C5 | Pas de profil de retraitement | `yieldKnown` false, chaque minéral `producedQuantity` `null` et `coveragePercent` `null` |
| C6 | Profil dont `reprocessYield` `null` (compétences illisibles) | idem C5 |
| C7 | Part en état `measuring` | `state` `measuring`, `minerals` vide, aucune couverture |
| C8 | Part en état `unknown` (`reconnect_required`) | `state` `unknown`, `unknownReason` `reconnect_required`, `minerals` vide |
| C9 | Quantité minée 0 pour un type | aucun minéral de ce type |
| C10 | Type miné avec `portionSize` null | minéraux de ce type `producedQuantity` `null`, `coverageUnknownReason` `portion_size_unknown` |
| C11 | Utilisateur non participant | refus d'accès existant |
| C12 | Opération inexistante | 404 |
| C13 | Deux participants : la part d'un autre n'est jamais comptée | seules les quantités de l'utilisateur courant |
| C14 | Déficit Tritanium 20 000, produit 32 000 | `coveragePercent` 100,0 (plafonné), `coversWholeDeficit` true |

## Cas limites

- Stockpile vide (aucune cible) : toutes les lignes `hasDeficit` false.
- Plusieurs types donnent le même minéral : additionnés (C2).
- Quantités corrigées (`MiningQuantityCorrection`) : déjà intégrées par `ofParticipant`.

## Hors périmètre

- Modification de `StockpileService` ou de tout service Industry (lecture seule).
- Écran de clôture de l'opération (#265) : le front de D8 est ajouté seulement s'il existe dans `main` ; sinon l'API seule est livrée et l'issue reste ouverte pour le front.
- Prise en compte du stock déjà détenu au-delà de ce que le Stockpile calcule (le déficit est celui du Stockpile).

## Termes proposés au glossaire (non ajoutés, la PR #326 modifie `docs/glossary.md`)

| Terme | Définition |
|---|---|
| **Couverture du déficit** (*deficit coverage*) | Part du déficit d'un minéral du Stockpile que la part minée d'un participant, retraitée à son rendement, apporte : `min(100, produit / déficit × 100)`. Inconnue si le rendement ou la quantité minée l'est. |
