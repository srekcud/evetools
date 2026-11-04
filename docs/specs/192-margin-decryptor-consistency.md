# #192 — Profit Margins : le decryptor choisi reste cohérent

Groupe `margin-display`, circuit:m, mode nuit. Contexte : `docs/modules/industry.md` (ProfitMarginService, noyau `ProductionCostCalculator`).

## État constaté (audit du 2026-10-22 en partie périmé)

- `copyCost` n'existe plus dans `ProfitMarginService` ni dans `ProfitMarginTab.vue` : les copies T1 font partie de chaque tentative d'invention (`inventionCost`), déjà couvert par `profitMarginTabSelectedDecryptorFees.spec.ts`. Rien à faire.
- Le courtage sur la vente à l'ordre d'achat de la structure est déjà corrigé (7c8f3491). Rien à faire.
- Restent deux défauts réels, décrits ci-dessous.

## Défaut 1 : la répartition du coût ne fait pas 100 % avec un decryptor sélectionné

Au clic sur un decryptor (`selectDecryptor`), la vue remplace `totalCost` et `inventionCost` par ceux de l'option (`totalProductionCost`, `inventionCost`) mais garde `materialCost` et `jobInstallCost` de l'analyse initiale. Le ME/TE du BPC inventé dépend du decryptor (`ProductionCostCalculator` : le `BlueprintEfficiency` d'un produit inventé vient de `invention->outcome`), donc matériaux et installation changent d'une option à l'autre. Les trois parts (matériaux, installation, invention) divisées par le total de l'option ne somment plus à 100 %.

### Règles

1. R1. Chaque option de `invention.options` expose en plus `materialCost` (float|null) et `jobInstallCost` (float|null), issus du `ProductionCost` de cette option (`$optionCost->materialCost`, `$optionCost->installCost`), nuls si le montant est inconnu (jamais 0 par défaut, `amountOf`).
2. R2. Pour une option connue : `materialCost + jobInstallCost + inventionCost = totalProductionCost` (aux arrondis flottants près, `assertEqualsWithDelta` 0.01).
3. R3. Avec une option sélectionnée localement, la carte « Matériaux », la carte « Installation » et leurs pourcentages utilisent `materialCost` et `jobInstallCost` de l'option, pas ceux de l'analyse. Sans option sélectionnée, rien ne change.
4. R4. Les pourcentages de la barre sont calculés sur le total affiché : leur somme vaut 100 % (delta 0.1) quand le total et les trois parts sont connus, pour l'analyse initiale comme pour toute option sélectionnée.
5. R5. Une part inconnue (`null`) rend son pourcentage inconnu et masque la barre, comme aujourd'hui. Pas de repli sur l'analyse initiale.

### Exemple chiffré (fixture Sabre de `profitMarginTabSelectedDecryptorFees.spec.ts` étendue)

| | analyse (sans decryptor) | option Attainment |
|---|---|---|
| materialCost | 100 000 | 96 000 |
| jobInstallCost | 5 000 | 9 000 |
| inventionCost | 26 000 | 45 000 |
| totalProductionCost | 131 000 | 150 000 |

Option Attainment sélectionnée : carte Matériaux `96.00 K` (64.0 %), Installation `9.00 K` (6.0 %), Invention `45.00 K` (30.0 %), total `150.00 K`.
Analyse sans sélection : 76.3 % / 3.8 % / 19.8 % (somme 100 %).

## Défaut 2 : « Rafraîchir les prix » efface le decryptor choisi

`analyze()` (donc `refreshPrices`) remet `selectedOption` et `selectedDecryptorTypeId` à zéro et ne transmet jamais `decryptorTypeId` à `analyzeMargin`, alors que le ME/TE du decryptor reste écrit dans `meLevel`/`teLevel`. Après un rafraîchissement, l'affichage revient au cas sans decryptor avec le ME/TE d'un decryptor.

### Règles

6. R6. `refreshPrices` et `analyze()` relancent l'analyse du même produit en transmettant `decryptorTypeId` du decryptor choisi, quand l'utilisateur en a choisi un (`selectedDecryptorTypeId` défini et non nul) : le paramètre `decryptorTypeId` est dans la requête `GET /industry/profit-margin/{typeId}`.
7. R7. Le choix de « No Decryptor » (`decryptorTypeId` null) ne transmet pas le paramètre (comportement actuel de `analyzeMargin`).
8. R8. Après le rafraîchissement, le decryptor reste marqué comme sélectionné dans le panneau des options (`selectedDecryptorTypeId` conservé) et le nom affiché dans la carte d'invention est le sien ; les montants viennent du résultat serveur de ce decryptor (`selectedOption` remis à `null`, le résultat de premier niveau étant déjà celui de ce decryptor, avec ses `missingData` et `warnings`).
9. R9. Choisir un autre produit (`onProductSelect`, `openType`) ou relancer le formulaire sur un nouveau produit repart sans decryptor : `selectedDecryptorTypeId` indéfini, ME/TE par défaut du niveau technologique (comportement actuel, à garder).
10. R10. Aucun `?? 0` ni repli : si l'option choisie n'existe plus dans le nouveau résultat, le panneau ne marque aucune option, sans lever d'erreur.

### Exemple

Sabre (T2) analysé, Attainment (34202) cliqué, puis « Rafraîchir les prix » : la requête est `/industry/profit-margin/22456?runs=4&me=1&te=8&decryptorTypeId=34202` (ME/TE pour mémoire : le backend les ignore pour un produit inventé, `ProductionCostCalculator` ligne 67) ; la ligne Attainment reste en surbrillance.

## Cas limites

- Produit T1 : aucune option, aucun changement.
- Total inconnu (`totalCost` null) : pas de barre, pourcentages inconnus (déjà le cas).
- Option dont `materialCost` est null : carte Matériaux « inconnu » (`industry.inventionUnknown.label`), pourcentage inconnu.

## Hors périmètre

- Recalcul des marges par lieu avec le decryptor (déjà couvert, voir `profitMarginTabSelectedDecryptorFees.spec.ts`).
- Retirer l'écriture de `meLevel`/`teLevel` au clic (inoffensive : champs désactivés pour un T2, ignorés par le backend).
- `warnings` et `missingData` d'une option sélectionnée localement avant rafraîchissement : ils restent ceux de l'analyse (le rafraîchissement les aligne, R8). Décision ouverte, non traitée ici.

## Décisions prises en autonomie

- Garder la sélection locale sans appel API (conception actuelle) et enrichir les options côté serveur plutôt que de rappeler l'API à chaque clic.
- Le rafraîchissement transmet le decryptor choisi plutôt que de revenir au ME/TE saisis.

## Fichiers touchés attendus

- `src/Service/Industry/ProfitMarginService.php` (`inventionDetails`).
- `frontend/src/stores/industry/types.ts` (`ProfitMarginInventionOption`).
- `frontend/src/components/industry/ProfitMarginTab.vue` (parts du coût, `analyzeType`).
- Tests : `ProfitMarginServicePartialPriceTest`/`ProfitMarginGoldenCharacterizationTest` si leur forme exacte fige les options ; fixtures front des options.
