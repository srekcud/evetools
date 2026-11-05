# #344 — Comparaison des décrypteurs en invention

Maquette : `industry-project-v3.html#p=24` (`renderInvention`). Circuit M, mode nuit.

## Périmètre

La comparaison « sans décrypteur + un décrypteur par ligne » existe déjà dans l'analyse de marge
(`ProfitMarginService::inventionDetails`, options calculées par le noyau avec les modificateurs du SDE :
`SdeDecryptors`, port `Decryptors`) et son panneau `InventionOptionsPanel.vue`. Elle manque de quatre colonnes de la maquette.

## Règles

1. Chaque option garde ses champs actuels (`me`, `te`, `runs`, `probability`, `materialCost`, `jobInstallCost`, `inventionCost`, `totalProductionCost`, `bestMargin`) et reçoit :
   - `expectedAttempts` : tentatives attendues pour les runs demandés, `runs / (probabilité × runs du BPC)`, valeur du noyau (`InventionExpectation::expectedAttempts`), jamais arrondie ; `null` quand la probabilité du SDE manque (#74).
   - `decryptorCost` : prix du décrypteur par tentative (`InventionCost::$decryptorCost`) ; `0.0` connu sans décrypteur ; `null` si le prix de marché manque.
   - `costPerUnit` : `totalProductionCost / quantité produite` (`ProductionCost::$costPerUnit`) ; `null` si le coût total est inconnu.
   - `costPerUnitDelta` : `costPerUnit` de l'option moins celui de l'option sans décrypteur ; `0.0` pour l'option sans décrypteur ; `null` si l'un des deux est inconnu.
2. `invention.datacoresCostPerAttempt` : coût des datacores d'une tentative (identique pour toutes les options, `InventionCost::$datacoresCost`), `null` si un prix manque.
3. Une donnée inconnue reste `null`, jamais `0` (pas de `?? 0`).
4. Front, `InventionOptionsPanel` : quatre colonnes ajoutées à la suite de « Prob. » : tentatives (une décimale), prix du décrypteur par tentative, coût par unité, écart par unité (signe `+`/`-`, `—` pour l'option sans décrypteur et pour une valeur inconnue). Le coût des datacores par tentative s'affiche dans l'en-tête à côté des noms des datacores. Valeur inconnue : le libellé `industry.inventionUnknown.label` déjà utilisé.
5. Le libellé de la colonne probabilité dit « sans compétences » (la probabilité du noyau est calculée avec les compétences à 0 : `ProfitMarginService::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS`).

## Exemples chiffrés (Hail L, 1 run, jeu de test de `ProfitMarginGoldenCharacterizationTest`)

| Option | Probabilité | Runs/BPC | Tentatives | Décrypteur / tentative |
|---|---|---|---|---|
| Sans décrypteur | 0,34 | 10 | 1 / (0,34 × 10) = 0,2941 | 0,0 |
| Process Decryptor | 0,374 | 10 | 1 / (0,374 × 10) = 0,2674 | 342,05 |

Datacores par tentative : 2 × 0,11 + 2 × 0,12 = 0,46.

## Cas limites

- Probabilité absente du SDE : `expectedAttempts` `null`, `inventionCost` déjà connu comme inconnu ; `costPerUnit` suit le coût total.
- Prix de matériau manquant : `costPerUnit` et `costPerUnitDelta` `null` sur toutes les options.
- Produit non inventé (T1) : `invention` reste `null`, rien ne change.

## Hors périmètre / décisions ouvertes

- **Probabilité réelle du personnage** (question de l'issue) : non traitée, la décision produit manque (quel personnage invente, et la liste des compétences requises par activité d'invention n'est pas dans le noyau : `InventionRecipe` ne porte pas les compétences). Le libellé « sans compétences » (règle 5) rend l'écart explicite en attendant. Dépend aussi de la page Compétences (#359). L'issue reste ouverte.
- Colonne « Jobs » de la maquette : dépend du modèle de projet unifié (#362, lots 2 et 3 non fusionnés).
- Comparaison dans le projet (`BpcKitTab`, calcul historique `InventionService` aux modificateurs codés en dur) : migration du calcul historique vers le noyau, hors lot.
- Prix des décrypteurs : prix du marché Jita déjà utilisé par le noyau, inchangé.
