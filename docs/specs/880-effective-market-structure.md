# #880 — Effective market structure: the list chosen in Settings › Market must be read

Statut : validée en autonomie (mode nuit). Circuit `circuit:l`, niveau de technicité 3 (code Industry touché).

## Problème

`User` porte `preferredMarketStructureId` / `preferredMarketStructureName` (hérité, plus écrit par le front depuis V0.12) et `marketStructures` (liste éditée par `MarketStructuresSettings.vue`). Presque tous les lecteurs ne lisent que le champ hérité : un compte avec un `preferred` nul et une liste non vide (2 comptes en prod) n'a aucune structure effective.

## Règles

1. **Un seul point de résolution**, sur `User` :
   - `getEffectiveMarketStructureId(): ?int` = `preferredMarketStructureId` s'il est posé, sinon l'id de la première entrée de `marketStructures`, sinon `null` ;
   - `getEffectiveMarketStructureName(): ?string` = `preferredMarketStructureName` si le `preferred` est posé (peut être `null`), sinon le nom de la première entrée de la liste, sinon `null` ;
   - `followsMarketStructure(int $structureId): bool` = `preferred === $structureId` ou l'id figure dans `marketStructures`.
2. **Tous les lecteurs du champ hérité passent par `getEffectiveMarketStructureId()` / `…Name()`** (le repli propre à chaque lecteur quand le résultat est `null` ne change pas : Jita pour `ProfitMarginProvider` et `ProjectInventionComparator`, structure par défaut pour `MarketHistoryProvider` et `SyncMarketProcessor`, « aucune structure » pour les autres) :
   `ProjectMaterialSourcingProvider` (id, nom, synchro), `BuyVsBuildProvider`, `ProfitMarginProvider`, `BatchScanProvider` (venues `structure` et `contracts`), `MarketTypeDetailProvider` (`hasPreferredStructure`, `structureName`, prix), `MarketHistoryProvider`, `CreateAlertProcessor` (structure d'une alerte sans structure demandée), `ParseProcessor`, `SyncMarketProcessor`, `ProjectInventionComparator`, `ProjectSellStructures::defaultFor`, `UserSettingsProvider` et `UpdateUserSettingsProcessor` (`effectiveMarketStructureId` / `…Name` = effective `??` structure par défaut).
3. `MarketAlertResourceMapper::structureNameOf` et `MarketTypeDetailProvider::structurePrices` lisent déjà la liste : inchangés.
4. **La synchro des marchés de structure couvre chaque structure de chaque liste** : `UserRepository::findDistinctPreferredMarketStructureIds()` devient `findDistinctMarketStructureIds()` et renvoie l'union dédoublonnée des `preferred` non nuls et de tous les ids des listes `marketStructures` (ordre : `preferred` d'abord, puis listes). Ses trois appelants (`TriggerStructureMarketSyncHandler`, `SyncRegionalMarketVolumesHandler`, `PublicContractRegions`) l'utilisent.
5. **Demandeur d'une synchro** : `CharacterRepository::findStructureMarketRequester($structureId, $isDefaultStructure)` retient un personnage dont l'utilisateur `followsMarketStructure($structureId)`, ou, pour la structure par défaut, dont l'utilisateur n'a aucune structure effective. Même tri qu'aujourd'hui (connexion la plus récente d'abord, jamais connecté en dernier), même contrôle du scope.
6. **Front** : `SellStructureSelect.vue` présélectionne `preferredMarketStructureId ?? marketStructures[0]?.id`. `BatchScanTab.vue` fait déjà ce calcul.

## Exemples

| Compte | preferred | marketStructures | effectif (id / nom) |
|---|---|---|---|
| A | 1049588174021 « C-J6MT » | [] | 1049588174021 / « C-J6MT » |
| B | null | [{1049588174021, « C-J6MT »}, {1035466617946, « 4-HWWF »}] | 1049588174021 / « C-J6MT » |
| C | 1035466617946 | [{1049588174021, « C-J6MT »}] | 1035466617946 / son nom préféré |
| D | null | [] | null / null |

- Compte B : `GET /api/industry/projects/{id}/material-sourcing` renvoie `structureId = 1049588174021`, `structureName = « C-J6MT »` ; buy vs build, marges et scanner (venue `structure`) analysent cette structure.
- `findDistinctMarketStructureIds()` avec A, B, C, D → `[1049588174021, 1035466617946]` sans doublon (l'ordre est celui de la règle 4).
- `findStructureMarketRequester(1049588174021, false)` avec seulement le compte B (token au scope marché) → son personnage ; `findStructureMarketRequester(1035466617946, true)` avec seulement le compte D → son personnage, avec seulement B → `null`.

## Cas limites

- Liste dont la première entrée est aussi le `preferred` : pas de doublon dans `findDistinctMarketStructureIds()`.
- `preferred` posé sans nom : le nom effectif est `null`, comme avant (les lecteurs gardent leur repli d'affichage).
- Aucun compte avec structure : comportement identique à aujourd'hui partout.

## Hors périmètre

- `preferredMarketStructureId` et `…Name` restent en base, dans l'API de réglages et dans `UpdateUserSettingsProcessor` (aucune suppression sans accord de Sylvain). Aucune migration.
- Pas de repli sur la structure par défaut pour `ProjectMaterialSourcingProvider`, `BuyVsBuildProvider`, `BatchScanProvider`, `ParseProcessor` (changerait les montants affichés des comptes sans réglage).
- Pas de marqueur « préférée » dans l'UI de la liste.

## Décisions ouvertes (pour Sylvain, au journal)

- Garder `preferredMarketStructureId` comme marqueur « préférée » de l'UI, ou le migrer dans la liste puis le supprimer ?
- Repli sur la structure par défaut pour les comptes sans aucune structure : non fait ici.

## Actions prod

Aucune migration. Au prochain cycle planifié, les marchés des structures présentes seulement dans une liste (dont C-J6MT pour les 2 comptes concernés) sont synchronisés ; aucune commande console à lancer.
