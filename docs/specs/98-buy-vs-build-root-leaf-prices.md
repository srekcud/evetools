# Spec #98 — Buy vs Build : prix des feuilles du job final

## Contexte

`BuyVsBuildService::analyze()` ne demande à Jita que le prix du produit, des composants fabricables et de leurs matériaux. Les feuilles du job final (matériaux non fabricables consommés directement par le produit) ne sont pas dans la demande : elles sont ensuite comptées `?? 0.0`, donc gratuites même quand Jita les price, sauf si un composant consomme aussi le matériau.

## Règles métier

- **R1 — Les feuilles du job final sont pricées.** La demande de prix Jita inclut chaque feuille du job final. Le coût des feuilles du job final vaut la somme de `prix Jita × quantité`.
- **R2 — Inchangé sinon.** Prix des composants, coût d'installation et marge suivent les règles actuelles.

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Nitrogen Fuel Block, 1 run, ME 10, prix Jita de tous ses matériaux connus, système sans indice de coût | coût total 0, marge inconnue | coût total = somme des matériaux × prix Jita (installation 0) ; marge calculée sur ce coût |
| E2 | Hail L, Morphite (15 unités, 10 000 ISK à Jita) feuille du job final | coût total 362 150 | 512 150 (+ 150 000) |
| E3 | Marshal, minéraux consommés par le job final et par le Capital Jump Drive | pricés à Jita | inchangé |

## Cas limites

- Feuille du job final sans prix Jita : toujours comptée 0. C'est #8 (prix réellement absent → coût inconnu), hors périmètre ici.

## Hors périmètre

- Coût inconnu quand un prix manque (#8) ; formule du coût d'installation (#7).
- Migration de Buy vs Build sur le noyau (branche `feat/buy-vs-build-on-engine`, qui couvre aussi ce cas).

## Tests existants appelés à changer

- `BuyVsBuildServiceCharacterizationTest::testRootLeavesCurrentlyWrongAreNeverPricedSoNitrogenFuelBlockCostsNothing` : remplacé par E1.
- `BuyVsBuildServiceCharacterizationTest::testRootLeafCurrentlyWrongIsFreeWhenNoComponentConsumesIt` : remplacé par E2.
- Hail L 10 runs (650 300), prix de vente structure à 0 (512 150) et arrondis (517 150,02) : la Morphite du job final y est désormais comptée.

## Décision prise en autonomie

Spec validée en mode nuit. Choix conservateur : seul le prix jamais demandé est corrigé ; le repli `?? 0.0` d'un prix réellement absent reste en place, parce que le coût inconnu relève de #8 et du noyau.

## Glossaire

Aucun terme ajouté (« feuille » existe).
