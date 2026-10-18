# Spec #100 — Buy vs Build sans structure de marché

## Contexte

Sans paramètre `structureId` ni structure de marché préférée, `BuyVsBuildProvider` passe `EveConstants::JITA_STATION_ID` à `BuyVsBuildService::analyze()` comme identifiant de structure. Le service lit alors le cache de marché d'une « structure » qui est une station NPC : le cache n'existe jamais, les prix « structure » sont toujours vides, et rien n'indique qu'aucune structure n'est choisie.

## Règles métier

- **R1 — Pas de structure choisie.** Sans `structureId` en paramètre et sans structure de marché préférée, aucune structure n'est choisie : le service reçoit `null`, ne lit aucun prix de structure (`StructureMarketService` n'est pas appelé), et chaque prix « structure » d'un composant est `null`. Le prix de vente du produit est le prix Jita.
- **R2 — La réponse indique la structure utilisée.** `BuyVsBuildResource::$sellStructureId` (`?int`) vaut la structure retenue (paramètre, sinon structure préférée), `null` quand aucune n'est choisie.
- **R3 — Inchangé avec une structure.** Avec un paramètre `structureId` ou une structure préférée, le comportement ne change pas.
- **R4 — Frontend.** Quand `sellStructureId` est `null`, l'onglet Buy vs Build affiche « Aucune structure de marché choisie : prix Jita uniquement » (FR) / « No market structure chosen: Jita prices only » (EN).

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Hail L, 1 run, ME 10, aucun paramètre, aucun réglage | `analyze(…, JITA_STATION_ID, …)`, cache de structure lu pour 60003760 | `analyze(…, null, …)`, aucune lecture de structure, `sellStructureId` null |
| E2 | Même cas, prix Jita du Hail L 450 | prix de vente 450 | prix de vente 450, prix structure de chaque composant `null` |
| E3 | Structure préférée 1035466617946 | `analyze(…, 1035466617946, …)` | inchangé, `sellStructureId` 1035466617946 |
| E4 | Paramètre `structureId` = 1035466617946, structure préférée différente | paramètre retenu | inchangé, `sellStructureId` = paramètre |

## Cas limites

- Paramètre `structureId` égal à l'identifiant d'une station NPC : hors périmètre (valeur de l'utilisateur transmise telle quelle).

## Hors périmètre

- Système de coût d'installation des réactions : #99.
- Libellé « Vente C-J6MT » codé en dur dans les traductions (`structureSell`).
- Migration de Buy vs Build sur le noyau (branche `feat/buy-vs-build-on-engine`).

## Tests existants appelés à changer

- `BuyVsBuildProviderTest::testCurrentlyWrongWithoutPreferredStructureSellsAtTheJitaStationAsIfItWereAStructure` : remplacé par E1.
- `testWithoutParametersNorSettingsAnalyzesOneRunAtMe10InJitaWithDefaultFees` et `testRunsQueryParameterIsForwardedAsRuns` : structure attendue `null` au lieu de `JITA_STATION_ID`.

## Décision prise en autonomie

Spec validée en mode nuit. Choix conservateurs : la signature de `analyze()` passe seulement de `int` à `?int` (compatible avec les appels de la branche `feat/buy-vs-build-on-engine`) ; le paramètre `structureId` explicite reste transmis tel quel ; l'information « aucune structure » passe par un champ nullable plutôt que par un nouveau motif d'erreur.

## Glossaire

Aucun terme ajouté (« structure de marché préférée » existe déjà).
