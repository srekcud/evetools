# Spec #99 — Buy vs Build : système des réactions

## Contexte

Sans `solarSystemId` dans la requête, `BuyVsBuildProvider` prend le système favori de fabrication (repli : Jita) et `BuyVsBuildService::analyze()` l'utilise pour le coût d'installation de chaque job, réactions comprises. Le système favori des réactions (`IndustryUserSettings::getFavoriteReactionSystemId()`) n'est jamais lu : les réactions prennent l'indice de coût « reaction » du système de fabrication.

## Règles métier

- **R1 — Système par activité.** Sans `solarSystemId` dans la requête, un job de fabrication prend le système favori de fabrication, un job de réaction le système favori des réactions. Cela vaut pour les composants et pour le produit final.
- **R2 — Repli.** Sans système favori des réactions, une réaction prend le système retenu pour la fabrication (système favori de fabrication, sinon Jita), comme aujourd'hui.
- **R3 — Paramètre explicite.** Avec `solarSystemId` dans la requête, ce système sert aux deux activités (inchangé).

## Exemples chiffrés

Indices de coût du harnais : Jita (30000142) fabrication 0,02, réaction 0,04 ; système d'usine (30002187) fabrication 0,05, réaction 0,1.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Fernite Carbide, favori fabrication = usine, favori réaction = Jita | réactions au taux 0,1 (composant 16656 : 9 000 ISK d'installation) | réactions au taux 0,04 de Jita ; composants de fabrication (Hydrogen Fuel Block) au taux 0,05 de l'usine (787,5 ISK, inchangé) |
| E2 | Hail L (produit de fabrication), mêmes réglages | fabrication au taux 0,05 | jobs de fabrication inchangés (R.A.M. 111,2, job final 14 250) ; sa réaction Fernite Carbide suit R1 |
| E3 | Fernite Carbide, favori fabrication = usine, sans favori réaction | réactions au taux 0,1 | inchangé (R2) |
| E4 | Fernite Carbide, `solarSystemId` = usine en paramètre, favori réaction = Jita | réactions au taux 0,1 | inchangé (R3) |
| E5 | Produit final de réaction (Fernite Carbide), favori réaction = Jita | job final au taux 0,1 de l'usine | job final au taux 0,04 de Jita |

Les montants attendus suivent la formule de `EsiCostIndexService::calculateJobInstallCost()` ; le test les calcule à partir de l'EIV du harnais.

## Hors périmètre

- Formule du coût d'installation (#7) et migration de Buy vs Build sur le noyau (branche `feat/buy-vs-build-on-engine`).
- Structure de production des jobs.

## Tests existants appelés à changer

- `BuyVsBuildProviderTest::testReactionProductCurrentlyWrongUsesTheFavoriteManufacturingSystem` : remplacé par E1/E5.

## Décision prise en autonomie

Spec validée en mode nuit. Choix conservateurs : `analyze()` reçoit le système des réactions en dernier paramètre optionnel (`?int $reactionSolarSystemId = null`, repli sur le système de fabrication), pour rester compatible avec les appels de la branche `feat/buy-vs-build-on-engine` ; le paramètre explicite `solarSystemId` garde son sens actuel (un seul système pour tout) ; aucun nouveau paramètre de requête.

## Glossaire

Aucun terme ajouté (« système favori » existe).
