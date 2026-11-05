# Spec #347 : frais de vente calculés depuis le vendeur

Décisions de Sylvain : 2026-11-03 (taux calculés depuis compétences et standings du vendeur, plus de taux saisi), 2026-11-05 (scope `read_standings` ajouté à l'app ; sans données, frais inconnus, jamais de repli ; un seul lot). Maquette `industry-project-v3.html#p=25`.

## Contexte

Taxe de vente et courtage viennent de `IndustryUserSettings::$salesTaxRate` / `$brokerFeeRate` (défaut 3,6 %), lus par 4 providers Industry, `EstimatedSellPriceEstimator`, la création de projet de groupe et `GroupIndustryDistributionService`. Aucun standing n'est stocké ni demandé à l'ESI.

## Règles

1. **Vendeur** : le personnage principal de l'utilisateur (`User::getMainCharacter()`). Pour un projet de groupe : celui de son propriétaire.
2. **Taxe de vente** = `0.075 × (1 − 0.11 × Accounting)` (Accounting, type 16622, niveau 0 à 5).
3. **Courtage NPC** = `0.03 − 0.003 × Broker Relations − 0.0003 × standingFaction − 0.0002 × standingCorpo` (Broker Relations, type 3446). Standings bruts ESI, de -10 à 10. Marché NPC de référence : Jita 4-4, corporation Caldari Navy (npc_corp `1000035`), faction Caldari State (`500001`), constantes dans `EveConstants`.
4. **Inconnu** : frais inconnus (`null`, jamais 0 ni 3,6 %) si le vendeur n'a pas de personnage principal, ou n'a pas de ligne Accounting / Broker Relations en cache, ou n'a jamais synchronisé ses standings. Taxe et courtage sont jugés ensemble : la taxe dépend de Accounting seul, mais un vendeur incomplet donne les deux inconnus (un seul état « frais inconnus », comme la maquette).
5. **Standing absent de la liste ESI après une synchro réussie** = 0,0 (neutre, sémantique de l'ESI : seuls les standings non neutres sont listés). Seule exception assumée à « jamais 0 pour une donnée absente », car la synchro prouve que la donnée est neutre.
6. **Scope** `esi-characters.read_standings.v1` ajouté à `AuthenticationService::REQUIRED_SCOPES` (28 devient 29) : les personnages déjà liés apparaissent « à reconnecter ». Docs à passer à 29 : `CLAUDE.md`, `README.md`, `.claude/skills/esi-api/SKILL.md`.
7. **Synchro des standings** : `GET /characters/{id}/standings/` (`from_id`, `from_type` `faction`, `npc_corp`, `agent`, `standing`), déclenchée par `POST /industry/character-skills/sync` (`SyncCharacterSkillsProcessor`), pour chaque personnage dont le token a le scope. Sans scope : échec `missingScope` pour ses standings ; ses compétences se synchronisent quand même. Réponse ESI invalide : `UnexpectedValueException` explicite, jamais un tableau vide par défaut. Stockage : entité `CachedCharacterStanding` (personnage, `fromId`, `fromType`, `standing`, `cachedAt`) remplacée à chaque synchro, et `Character::$standingsSyncedAt` (nullable) posé à la fin. Synchro synchrone dans la requête : pas de topic Mercure.
8. **Compétences** : la synchro écrit aussi Accounting et Broker Relations, y compris à 0 (comme Science), pour distinguer niveau 0 et jamais synchronisé. Une ligne manuelle (`isManual`) est conservée telle quelle.
9. **Calcul** : `SalesFeeCalculator` pur (niveaux et standings entrés, fractions sortie) ; `SellerSalesFeesResolver` lit la base et rend `SalesFees` (`brokerFeeRate`, `salesTaxRate`, fractions) ou `null`.
10. **Consommateurs** (`?SalesFees`, `null` = inconnu) : les marges par lieu de `ProfitMarginService`, le scan de lots, `BuyVsBuild`, `PivotAdvisor` rendent leur montant de frais et leur marge inconnus (null) ; les prix, coûts et autres champs restent calculés. `EstimatedSellPriceEstimator` : taxe estimée `null` quand les frais sont inconnus (colonne déjà nullable ; `ReactionProjectSellPriceRecalculation` garde sa garde).
11. **Projet de groupe** : `brokerFeePercent` / `salesTaxPercent` deviennent nullables (migration). À la création : valeur du corps de la requête si donnée (réglage explicite du projet, conservé), sinon frais du vendeur en pourcents, sinon `null`. Un projet `completed` garde ce qu'il a. `GroupIndustryDistributionService` : frais et revenu net inconnus (`?float`) si un des deux pourcents est `null`. `GroupProjectFees::rejectEffectiveFeesAboveMaximum` ne s'applique qu'à deux valeurs non nulles. Le PATCH de projet continue d'accepter les deux champs.
12. **Réglages utilisateur** : `brokerFeeRate` / `salesTaxRate` disparaissent de `IndustryUserSettings` (colonnes supprimées par migration), de `UserSettingsResource`, de l'input et de `FeesConfig.vue`. Aucun appelant ne les relit.
13. **Ressources** : `ProfitMarginResource::$brokerFeeRate/$salesTaxRate` et les champs de projet de groupe deviennent nullables ; les montants de frais d'une répartition aussi. Une nouvelle lecture « frais du vendeur » n'est pas ajoutée à l'API : la carte de la maquette (`FeesCard`) lit les taux de `ProfitMarginResource`.
14. **Front** : types nullables, `venueMargin.ts` rend une marge inconnue (`null`) sans taux, affichage « frais inconnus » avec la raison « synchroniser compétences et standings », `FinancialSummary.vue` et `GroupIndustryDetail.vue` gèrent `null`. Textes i18n FR et EN.

## Noms retenus (lot 1)

`App\Service\Industry\SalesFees\` : `SalesFees` (readonly, `brokerFeeRate`, `salesTaxRate`), `SalesFeeCalculator::calculate(int $accountingLevel, int $brokerRelationsLevel, float $factionStanding, float $corporationStanding): SalesFees`, `SellerSalesFeesResolver::forUser(User): ?SalesFees`. `App\Service\ESI\CharacterStandingsSynchronizer::sync(Character, EveToken): void`. Entité `App\Entity\CachedCharacterStanding` (+ `CachedCharacterStandingRepository`), `Character::getStandingsSyncedAt()`. Constantes : `CachedCharacterSkill::SKILL_ACCOUNTING` (16622), `SKILL_BROKER_RELATIONS` (3446), `EveConstants::JITA_NPC_CORPORATION_ID` (1000035), `JITA_NPC_FACTION_ID` (500001).

## Lots

1 : calculateur, résolveur, scope, entité, synchro (règles 1 à 9). 2 : consommateurs, projet de groupe, réglages, migrations (10 à 13). 3 : front (14).

## Exemples chiffrés

| Accounting | Broker Relations | Standing faction | Standing corpo | Taxe | Courtage |
|---|---|---|---|---|---|
| 5 | 5 | 2,0 | 3,0 | 3,375 % | 1,38 % |
| 0 | 0 | 0 | 0 | 7,5 % | 3,0 % |
| 4 | 3 | 0 | 0 | 4,2 % | 2,1 % |
| 5 | 5 | 10 | 10 | 3,375 % | 1,0 % |
| 5 | 5 | -5 | 0 | 3,375 % | 1,65 % |

Projet de groupe créé sans pourcents par le propriétaire du 1er exemple : `salesTaxPercent` 3,375, `brokerFeePercent` 1,38. Propriétaire sans standings synchronisés : les deux `null`, répartition à frais et revenu net inconnus.

## Cas limites

- Standing faction absent de la liste, standing corpo présent (synchro faite) : faction 0,0.
- `from_type` `agent` ignoré. Même `from_id` en deux types : on compare id et type.
- Niveau de compétence hors 0 à 5 : le calculateur lève une exception, il ne borne pas.
- Re-synchro : les standings disparus de la liste ESI sont supprimés.
- Utilisateur avec un token sans le scope standings : frais inconnus, `missingScope` dans la réponse de synchro.
- Courtage de structure de joueur : non couvert (hors périmètre), le même courtage NPC s'applique aux lieux où l'ancien taux s'appliquait.

## Accès et fraîcheur

Les frais se lisent pour l'utilisateur connecté (son vendeur) et, pour un projet, via le propriétaire déjà contrôlé par les providers du projet. Aucun nouvel identifiant reçu du client. Fraîcheur : la dernière synchro à la demande ; `CachedCharacterStanding::$cachedAt` et `Character::$standingsSyncedAt` la portent. Pas de synchro planifiée (hors périmètre).

## Tests existants modifiés

`UpdateUserSettings*`, `UserSettingsProvider`, `ProfitMargin*`, `BuyVsBuild*`, `PivotAdvisor*`, `BatchProfitScanRulesTest`, `EstimatedSellPriceEstimator*`, `GroupIndustryDistributionService*`, `GroupProjectDefaultFeesTest`, `CreateGroupProject*`, `SyncCharacterSkills*` : l'injection du taux saisi devient `?SalesFees`.

## Hors périmètre

Courtage d'une structure de joueur, frais réels du journal wallet, standings effectifs avec Connections / Diplomacy ([TO CONFIRM] : la maquette utilise les standings bruts), synchro planifiée, autre marché NPC que Jita 4-4, `GroupIndustryProject` fees édités par PATCH (inchangé).

## Glossaire

Ajouter : **Frais du vendeur** (Seller fees), **Standing** (Standing). Modifier **Taxe de vente / Courtage** : calculés depuis le vendeur, plus saisis, inconnus sans données.
