# Spec #264 — Minage groupé : valorisation et répartition des gains d'une opération

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-03, à valider par Sylvain avant `/tdd-red`.
- **Circuit** : `circuit:m`, sans note de câblage séparée : le harnais de test est en annexe (section 12).
- **État du code de référence** : `fix/night-mining-ops` @ `2caae5fd` (#262 et #263 fusionnés, #259, #260, #268, #269 sur `main`).
- **Maquette de référence** : `mockups/grouped-mining-v4.html` (branche `docs/mining-specs`) : `computeShares()`, `renderShares()`, `renderSoloResults()`, stepper (`stepper()`), modale `create` (champs « Base de valorisation », « Part du booster », « Part corporation », « Booster »). Complément #264 : `mockups/mining-operation-shares.html` (réglages dans le détail, aperçu en clôture, bouton « Figer les parts », état figé, chacun pour soi).
- **Dépend de** : #262 (opération, membres), #263 (quantités minées, corrections), #259/#260 (prix équivalent compressé, prix inconnu), #268/#269 (valeur par forme et marché, rendement de retraitement).

## 1. Contexte

#263 donne, après la clôture, la quantité minée par personnage, par participant et par type, corrections incluses, ou une quantité inconnue. Il manque le passage de l'unité à l'ISK. Ce lot ajoute trois choses :

1. **les réglages de valorisation** de l'opération : une base de valorisation unique (forme × marché × côté), la part du booster, le booster qui la reçoit, et la part corporation ;
2. **un aperçu recalculé** en Clôture : valeur minée par participant, total G, parts, ISK/h, avec les prix du cache au moment de la lecture ;
3. **la transition Clôture → Parts** (« Figer les parts ») : les prix du moment et la valeur minée de chaque participant sont copiés dans l'opération. Les parts ne changent plus ensuite, et on peut les recalculer à l'identique.

Paiements, marquage payé, archive et état Terminée restent à #265. Le fleet storage reste à #266.

Constat dans le code (lu le 2026-11-03) :

| Élément | État | Usage dans #264 |
|---|---|---|
| `OreValueService::getBatchOrePrices(typeIds, ?structureId, reprocessYield, exportTax, MarketSide)` | rend par type `rawUnitPrice`, `compressedEquivalentPrice`, `reprocessValue`, `structureUnitPrice`, `structureCompressedEquivalentPrice`, `structureReprocessValue` ; `null` = sans prix (#260) | seule source de prix unitaire, appelée avec la base de l'opération |
| `OreValueTableService` | constantes privées `JITA_PRICE_KEYS` / `STRUCTURE_PRICE_KEYS` (forme → clé de prix) | la correspondance passe dans un enum partagé (R6) |
| `MiningBestValueCalculator` | meilleur prix parmi plusieurs sources | **non utilisé** : une opération a une seule base, pas de « meilleur prix » |
| `JitaMarketService::getLastSyncTime()`, `StructureMarketService::getLastSyncTime(int)` | heure de la dernière sync en cache | heure de sync des prix lus ou figés |
| `MinedQuantityCalculator::ofParticipant()` / `ofCharacter()` | quantités par type, corrections incluses ; état `measuring` / `known` / `unknown` | base des valeurs minées |
| `ReprocessingProfile::$reprocessYield` (#269) | rendement de l'utilisateur, `null` si inconnu | pré-remplit le rendement au front ; jamais lu par le serveur pour l'opération |
| Montants ISK des entités | `float` partout (`GroupIndustrySale`, `GroupIndustryContribution`, `PveExpense`…) | montants figés en `float`, valeurs entières d'ISK (R11) |
| `MiningOperationStatus` | `planned`, `in_progress`, `closing`, `completed` ; `match` exhaustif dans `MiningOperationLifecycle` (`:132`) | nouveau cas `shares` |
| Topic `/user/{id}/mining-operations` (#262 R25) | actions `member_*`, `operation_*`, `quantity_corrected` | deux actions de plus, pas de nouveau topic |

## 2. Modèle

### Une seule migration Doctrine

| Entité / table | Changement | Contraintes |
|---|---|---|
| `MiningOperation` / `mining_operations` | + `valuationForm` varchar(20) `MiningValuationForm`, défaut `compressed` ; + `valuationMarket` varchar(20) `MiningValuationMarket`, défaut `jita` ; + `valuationSide` varchar(10) `MarketSide` (enum existant), défaut `buy` ; + `valuationStructureId` bigint nullable ; + `valuationStructureName` varchar(255) nullable ; + `reprocessYield` float nullable ; + `boosterCutPercent` smallint défaut 0 ; + `corporationCutPercent` smallint défaut 0 ; + `boosterMember` → `mining_operation_members` nullable ; + `sharesFrozenAt` datetime_immutable nullable ; + `pricesSyncedAt` datetime_immutable nullable | `booster_member_id` `ON DELETE SET NULL` ; lignes existantes : valeurs par défaut |
| `MiningFrozenUnitPrice` / `mining_frozen_unit_prices` (nouveau) | `id` UUID ; `operation` ; `typeId` int ; `unitPrice` float | `operation_id` `ON DELETE CASCADE` ; `UNIQUE(operation_id, type_id)` |
| `MiningFrozenMinedValue` / `mining_frozen_mined_values` (nouveau) | `id` UUID ; `operation` ; `member` → `mining_operation_members` nullable ; `participantName` varchar(255) nullable ; `position` smallint ; `minedValue` float (entier d'ISK) | `operation_id` `ON DELETE CASCADE` ; `member_id` `ON DELETE SET NULL` ; `UNIQUE(operation_id, position)` |

`MiningOperation` porte ses prix figés et ses valeurs figées (`OneToMany`, `cascade: persist, remove`, `orphanRemoval`).

Les parts ne sont pas stockées. Elles sont recalculées par `MiningShareCalculator` (fonction pure, R10) à partir des valeurs figées, dans l'ordre `position`, et des pourcentages, qui ne changent plus après le gel. C'est la garantie de reproductibilité. Une copie des parts pourrait diverger du calcul.

### Enums (`src/Enum/`)

| Enum | Cas (valeur) | Méthodes |
|---|---|---|
| `MiningValuationForm` | `Raw` (`raw`), `Compressed` (`compressed`), `Reprocessed` (`reprocessed`) | `priceKeyOn(MiningValuationMarket): string` : clé de `getBatchOrePrices()` (R6) |
| `MiningValuationMarket` | `Jita` (`jita`), `Structure` (`structure`) | — |
| `MiningOperationStatus` | + `Shares` (`shares`) | `isOpen()` : vrai pour `planned`, `in_progress`, `closing` seulement (R16) ; `acceptsParticipants()` inchangé |
| `MiningShareBlocker` | `NotClosing` (`not_closing`), `EachForThemselves` (`each_for_themselves`), `QuantitiesNotKnown` (`quantities_not_known`), `PricesMissing` (`prices_missing`), `BoosterMissing` (`booster_missing`) | — |

## 3. Règles métier

### Réglages de valorisation

- **R1 — Réglages par défaut.** Une opération naît avec la base « compressé, Jita, achat » (défaut de la maquette), `boosterCutPercent` 0, `corporationCutPercent` 0, aucun booster. `POST /api/mining-operations` (#262) ne change pas.
- **R2 — Modifier les réglages.** `PATCH /api/mining-operations/{operationId}/valuation` (`application/merge-patch+json`) met à jour un ou plusieurs champs : `valuationForm`, `valuationMarket`, `valuationSide`, `structureId`, `structureName`, `reprocessYield`, `boosterCutPercent`, `corporationCutPercent`, `boosterMemberId`. Les champs absents restent inchangés. Accès administration (#262 R17). Statut `planned`, `in_progress` ou `closing`, sinon `400` « The shares of this operation are frozen ». Réponse `200`, la valorisation (R20).
- **R3 — Forme de l'entrée** (`422`, violation sur le champ) : `valuationForm` dans `raw|compressed|reprocessed` ; `valuationMarket` dans `jita|structure` ; `valuationSide` dans `buy|sell` ; `structureId` entier > 0 ou `null` ; `structureName` 1 à 255 caractères ou `null` ; `reprocessYield` > 0 et ≤ 1 ou `null` (bornes de #268 V5) ; `boosterCutPercent` et `corporationCutPercent` entiers de 0 à 100 ; `boosterMemberId` UUID ou `null`.
- **R4 — Cohérence des réglages** (`400`, contrôlés sur l'état obtenu après fusion, dans cet ordre) :
  1. marché `structure` sans `structureId` ou sans `structureName` : « A structure market needs a structure » ;
  2. forme `reprocessed` sans `reprocessYield` : « A reprocessed valuation needs a reprocessing yield » ;
  3. opération « Chacun pour soi » avec une part du booster > 0, une part corporation > 0 ou un booster : « An each-for-themselves operation has no booster or corporation cut » ;
  4. `boosterCutPercent + corporationCutPercent` > 100 : « Booster and corporation cuts cannot exceed 100 % » ;
  5. `corporationCutPercent` > 0 avec `corporationId` `null` : « A corporation cut needs a known corporation » ;
  6. `boosterMemberId` qui n'est pas un membre `accepted` de l'opération : « The booster must be an accepted participant ».
- **R5 — Champs remis à `null`.** Avec le marché `jita`, `valuationStructureId` et `valuationStructureName` sont enregistrés à `null`. Avec une forme autre que `reprocessed`, `reprocessYield` est enregistré à `null` (même règle que #269 R13). Le nom de structure est un libellé d'affichage fourni par le front, via la recherche `GET /api/shopping-list/search-structures` utilisée par `MiningTab`. Il n'est pas vérifié.

### Prix unitaire de la base

- **R6 — Une clé de prix par base.** `MiningValuationForm::priceKeyOn()` :

  | Forme | Jita | Structure |
  |---|---|---|
  | `raw` | `rawUnitPrice` | `structureUnitPrice` |
  | `compressed` | `compressedEquivalentPrice` | `structureCompressedEquivalentPrice` |
  | `reprocessed` | `reprocessValue` | `structureReprocessValue` |

  `OreValueTableService` lit cette méthode au lieu de ses deux constantes privées. C'est un refactor sans changement de comportement : ses tests restent verts.
- **R7 — Lecture des prix.** `MiningOperationUnitPrices::read(MiningOperation, list<int> $typeIds)` appelle une seule fois `OreValueService::getBatchOrePrices($typeIds, structureId ou null, reprocessYield ou 1.0, 0.0, valuationSide)`. Le rendement 1.0 n'est pas utilisé hors de la forme retraitée (`UNUSED_REPROCESS_YIELD` de #268). Pas de taxe d'export. Le résultat porte, par type, la valeur de la clé de R6 (`null` = sans prix). Il porte aussi `pricesSyncedAt` : `JitaMarketService::getLastSyncTime()` ou `StructureMarketService::getLastSyncTime(structureId)`, `null` si inconnue. Aucun appel ESI : on ne lit que les prix en cache.
- **R8 — Pas de repli entre bases.** Un type sans prix sur la base de l'opération est **sans prix**. On ne prend pas un autre marché, une autre forme ou le meilleur prix. Un prix présent à 0 est un prix connu (#260 M9). Un gaz valorisé en « retraité » est sans prix (#268 V6) : pour une opération qui mélange gaz et minerai, il faut choisir « brut » ou « compressé ».

### Valeur minée

- **R9 — Valeur minée d'un participant.** Les participants sont les membres `accepted` de l'opération, propriétaire compris, dans l'ordre du ledger (#263 R24 : propriétaire, puis `joinedAt`). Pour chacun : `MinedQuantityCalculator::ofParticipant()`, puis :
  - état `measuring` ou `unknown` : valeur minée **inconnue** (`null`) ;
  - état `known` : `Σ_type quantité × prix unitaire`, sur ses quantités par type, tous personnages réunis. Elle est inconnue si un type de quantité > 0 est sans prix. Elle est arrondie **une fois** à l'ISK entier, au plus proche (`round()`, demi vers l'extérieur) ;
  - un participant sans quantité vaut 0, connu (booster qui ne mine pas, personnages supprimés).

  La valeur d'un personnage se calcule de la même façon, sur ses propres quantités. Elle sert à l'affichage et n'entre pas dans le calcul : la somme des valeurs arrondies des personnages peut s'écarter d'un ISK de la valeur du participant (exemple V4).
- **R10 — Valeur totale G.** `G = Σ valeurs minées des participants`, entier. Elle est inconnue dès qu'une valeur minée est inconnue. Une opération sans participant valorisé vaut 0.

### Parts

- **R11 — Calcul des parts** (`MiningShareCalculator::split(list<?int> $minedValues, int $boosterCutPercent, int $corporationCutPercent)`, pur, sans base). Avec `k = 100 − booster − corporation` :
  - montant exact de la part du participant i : `V_i × k / 100` ; part du booster : `G × booster / 100` ; part corporation : `G × corporation / 100`. La somme des montants exacts vaut G ;
  - chaque montant est d'abord arrondi à l'ISK inférieur, en arithmétique entière (`intdiv(numérateur, 100)`, reste = `numérateur % 100`). Les numérateurs restent sous 10^13 pour une opération de 100 milliards d'ISK, donc pas de dépassement d'entier 64 bits ;
  - le **reste d'arrondi** `R = G − Σ montants arrondis` (0 ≤ R < nombre de destinataires) est donné un ISK à la fois aux destinataires de plus grand reste. À reste égal, l'ordre est : participants dans l'ordre de R9, puis part du booster, puis part corporation ;
  - résultat : `totalValue`, `boosterCut`, `corporationCut`, `shares` (une part par participant, dans l'ordre), et pour chaque destinataire `roundingAdjustment` (0 ou 1 ISK reçu du reste) ;
  - contrôle, vrai par construction : `Σ shares + boosterCut + corporationCut = G`.
- **R12 — Part inconnue, jamais 0.** Si une valeur minée est inconnue, G, la part du booster, la part corporation, toutes les parts et tous les `% de G` sont inconnus (`null`). Les valeurs minées connues restent affichées. Une part ne s'affiche pas tant qu'elle peut encore bouger : le reste d'arrondi dépend de tous les participants. Un participant connu garde aussi son ISK/h (R14).
- **R13 — Part du booster.** Elle va au membre `boosterMember`, en plus de sa propre part s'il a miné. Le booster est un participant comme un autre pour R9. Part du booster > 0 sans booster : l'aperçu la calcule quand même, mais le gel est refusé (R17).
- **R14 — ISK/h.**
  - **Participant** : valeur minée / durée, en ISK/h, non arrondi dans l'API. La durée va du plus ancien `referenceAt` des instantanés de début `taken` de ses personnages jusqu'à son `departedAt`, ou à défaut `endedAt`. Elle est inconnue si aucun instantané de début n'est `taken` (personnages tous corrigés), et l'ISK/h l'est alors aussi. Durée nulle : ISK/h inconnu.
  - **Opération** : G / (`endedAt` − `startedAt`). Inconnu si G l'est.
  - `% de G` d'un participant : `V_i / G × 100`, non arrondi dans l'API. Inconnu si G est inconnu ou vaut 0.
- **R15 — Chacun pour soi.** Pour une opération `each_for_themselves` : aucune part, aucune part du booster ni corporation (R4.3). Dans la valorisation, `boosterCut`, `corporationCut`, `shares` et `% de G` valent `null`. On affiche la valeur minée et l'ISK/h de chaque participant, G et l'ISK/h de l'opération (exemple CS1). Les prix sont lus à chaque lecture : ils ne sont jamais figés dans ce lot, puisque l'état Parts n'existe pas pour ce mode. Le gel des prix au passage à Terminée revient à #265.

### Figer les parts (Clôture → Parts)

- **R16 — Statut `shares`.** `MiningOperationStatus::Shares` (`shares`), libellé « Parts ». Ce n'est plus une opération ouverte (`isOpen()` faux) : ses personnages sont libres pour une autre opération (#262 R7), puisque le minage est mesuré et figé. Elle n'accepte plus de participants. On ne peut plus y faire de correction (#263 R19), de relance (#263 R15), de retrait ni de modification des réglages. Dans `MiningOperationLifecycle` (`:132`), le `match` gagne `Shares => null` (pas d'instantané relançable).
- **R17 — Figer.** `POST /api/mining-operations/{id}/freeze-shares` (corps vide) : accès administration. Les refus sont contrôlés dans cet ordre (`400`) :
  1. statut autre que `closing` : « Only a closing operation can have its shares frozen » ;
  2. mode `each_for_themselves` : « An each-for-themselves operation has no shares » ;
  3. un participant `measuring` ou `unknown` : « Every mined quantity must be known before freezing the shares: {noms des participants, séparés par une virgule} » ;
  4. un type de quantité > 0 sans prix : « Some ores have no price on the valuation basis: {noms des types, triés par typeId} » ;
  5. part du booster > 0 sans booster, ou booster qui n'est plus `accepted` : « Choose the booster who receives the booster cut ».

  Effet, dans une seule transaction : un `MiningFrozenUnitPrice` par type de quantité > 0 (prix de R7) ; un `MiningFrozenMinedValue` par participant (valeur de R9, `position` = rang dans l'ordre de R9, `participantName` = nom du personnage principal) ; `pricesSyncedAt` (R7) ; `sharesFrozenAt` = heure de l'horloge ; statut `shares`. Réponse `200`, l'opération.
- **R18 — Quantité inconnue : on ne passe pas outre.** Le gel attend que toutes les quantités soient connues. La décision explicite d'un Admin pour un personnage illisible est la **correction de quantité** de #263 (R19-R21), 0 compris. Elle est déjà tracée : auteur, heure, valeur d'origine `null`. Ce lot n'ajoute aucune option « figer malgré tout ».
- **R19 — Après le gel.** La valorisation est servie depuis les données figées : prix figés, valeurs figées (ordre `position`), pourcentages et booster de l'opération, parts recalculées par R11. Les quantités par personnage et par type restent lues par `MinedQuantityCalculator` et valorisées aux prix figés, pour l'affichage seulement. La sync Jita qui suit ne change rien. Défiger n'est pas prévu dans ce lot (décision 9).

### Ressources renvoyées

- **R20 — `MiningOperationValuation`** (`GET /api/mining-operations/{operationId}/valuation`, accès participation #262 R17, réponse aussi de R2) :
  - opération : `operationId`, `status`, `splitMode`, `frozen` (bool), `sharesFrozenAt`, `pricesReadAt` (heure de l'horloge pour un aperçu, `sharesFrozenAt` une fois figée), `pricesSyncedAt` ;
  - réglages : `settings` = `{valuationForm, valuationMarket, valuationSide, structureId, structureName, reprocessYield, boosterCutPercent, corporationCutPercent, boosterMemberId, boosterCharacterName}` ;
  - prix : `unitPrices` = `[{typeId, typeName, unitPrice}]`, types de quantité > 0, triés par `typeName`, `unitPrice` `null` si sans prix ; `missingPriceTypeIds` (triés, sans doublon) ;
  - totaux : `totalValue`, `boosterCut`, `corporationCut`, `iskPerHour`, `durationSeconds`, `roundingRemainder` (R de R11, `null` si inconnu), `boosterRoundingAdjustment`, `corporationRoundingAdjustment` ;
  - participants : `[{memberId, characterName, isBooster, quantityState, totalQuantity, minedValue, valuePercent, share, roundingAdjustment, iskPerHour, durationSeconds, departedAt, characters: [{id, name, quantityState, totalQuantity, minedValue}]}]`, dans l'ordre de R9 ;
  - gel : `canFreeze` (vrai si l'utilisateur est administrateur et qu'aucun blocage ne s'applique), `freezeBlockers` (`list<MiningShareBlocker>`, dans l'ordre de R17, calculés pour tous les lecteurs).

  Une fois l'opération figée, un membre dont le compte a été supprimé apparaît avec `memberId` `null` et son `participantName` figé.
- **R21 — `MiningOperation`** (#262 R22, #263 R22) gagne `valuationSettings` (même objet que `settings` de R20) et `sharesFrozenAt`. **`MiningOperationInvitation`** (#262 R24) gagne `valuationSettings` sans `boosterMemberId` : la page d'adhésion affiche les règles de l'opération (carte « Règles de l'opération » de la maquette `join`).

### Temps réel

- **R22 — Actions publiées.** `publishMiningOperationEvent()` (#262 R26) publie aussi `valuation_updated` (`{}`, après R2) et `shares_frozen` (`{}`, après R17), vers l'audience habituelle. Il n'y a pas de nouveau topic et `getTopicsForUser()` ne change pas (17 topics). Les prix du cache ne sont pas poussés : l'aperçu reflète les prix au moment de la lecture.
- **R23 — Front.** La vue détail recharge la valorisation sur `valuation_updated`, `shares_frozen`, `quantity_corrected`, `operation_ended`, `member_departed` de l'opération affichée, et sur tout message `mining-operation-snapshot` de cette opération (motif #263 R28). Elle recharge aussi l'opération sur `shares_frozen`.

## 4. Endpoints API Platform

Toutes les opérations : `security: "is_granted('ROLE_USER')"`, `paginationEnabled: false`, préfixe `/api`, même exigence UUID qu'en #262 sur `{id}` / `{operationId}`. Ordre des refus : `401`, `404` (`UuidOrNotFound`), `403`, `422`, puis règles métier `400`.

| Ressource | Opération | `uriTemplate` | `uriVariables` | Entrée | Provider / Processor | Réponse |
|---|---|---|---|---|---|---|
| `MiningOperationValuationResource` (sans identifiant) | `Get` | `/mining-operations/{operationId}/valuation` | `operationId` → `Link(fromClass: MiningOperationResource::class)` | — | `MiningOperationValuationProvider` | 200 (R20) |
| | `Patch` | `/mining-operations/{operationId}/valuation` | idem | `UpdateMiningOperationValuationInput`, `inputFormats: ['json' => ['application/merge-patch+json']]` | `MiningOperationValuationProvider` / `UpdateMiningOperationValuationProcessor` | 200 (R2-R5) |
| `MiningOperationResource` | `Post` | `/mining-operations/{id}/freeze-shares` | `id` → `Link(fromClass: self::class)` | `EmptyInput` | `FreezeMiningSharesProcessor` | 200 (R17) |

`MiningOperationSubResourceUriVariablesTest` reçoit les trois lignes.

Répartition du code :
- dans les entités : `MiningOperation::updateValuationSettings()` (R4, R5), `MiningOperation::freezeShares()` (R16, R17 effets) ;
- dans `src/Service/MiningOperation/` : `MiningOperationUnitPrices` (R6-R8), `MiningShareCalculator` et son résultat `MiningShareSplit` (R11), `MiningOperationValuator` (R9, R10, R12-R15, R19 : assemble quantités, prix et parts pour le provider et le processor de gel), `MiningShareFreezer` (R17, contrôles et écriture).

Les processors restent minces. Le mapping vers la ressource passe par `MiningOperationResourceMapper`.

## 5. Écrans (front)

Direction : HUD EVE de la v4 et maquette complémentaire `file:///home/sdu/.t3/worktrees/evetools/fix-night-mining-ops/mockups/mining-operation-shares.html` (quatre états commutables, noms longs). Montants au format `fr-FR` / `en-US` en ISK entiers, `% de G` à une décimale, ISK/h à l'ISK. Une valeur `null` s'affiche « inconnue » en ambre via `UnknownValue` (jamais « 0 », jamais « – » pour une donnée manquante). Noms tronqués avec infobulle.

| Où | Contenu |
|---|---|
| Détail, en-tête (tous statuts) | nouveau composant `MiningOperationStepper.vue` : « Planifiée → En cours → Clôture → Parts → Réglée » (prorata) ou « Planifiée → En cours → Clôture → Terminée » (chacun pour soi), étape courante marquée (`aria-current="step"`). « Réglée » et « Terminée » ne sont jamais atteintes dans ce lot. Méta : « {base} · booster {n} % ({nom}) · corporation {n} % », ou « chacun pour soi · boost sans part » |
| Détail, carte « Règles » (#262) | + base de valorisation, part du booster et booster, part corporation |
| Page d'adhésion, carte « Règles de l'opération » | mêmes lignes (R21) |
| Détail, `planned` / `in_progress` / `closing`, colonne droite | carte « Valorisation » (`MiningValuationSettings.vue`, administration ; lecture seule sinon) : radio Brut / Compressé / Retraité ; Marché (Jita, Structure suivie avec recherche de structure comme `MiningTab`) ; Côté (Achat, Vente) ; Rendement (si Retraité, pré-rempli depuis `GET /api/mining/reprocessing-profile` quand il est connu, sinon vide et obligatoire) ; Part du booster % ; Part corporation % (désactivée sans corporation) ; Booster (participants `accepted`, ou « Aucun ») ; texte de règle ; « Enregistrer les réglages ». En chacun pour soi, booster et corporation désactivés à 0 avec l'aide « sans part en chacun pour soi » |
| Détail, `closing` | carte « Aperçu de la répartition au prorata de la valeur » (`MiningShareTable.vue`) : Participant (badge Booster, « vous », « parti HH:MM »), Quantité (u), Valeur minée, % de G, Part (+ « + booster {montant} » sur la ligne du booster), ISK/h et durée ; lignes personnages dépliables (valeur du personnage) ; ligne Total ; pied « prix lus à {HH:MM} · sync {marché} {HH:MM} · recalculé à chaque lecture », bouton « Relire les prix » (recharge) ; ligne de contrôle « Σ parts {x} + booster {y} + corporation {z} = {G} = G ✓ · reste d'arrondi {R} ISK », ou « contrôle impossible : G inconnue ». Carte « Prix unitaires lus » (type, ISK/u, « sans prix » en ambre). Carte « Figer les parts » (administration) : aide, bouton, motifs de `freezeBlockers` en liste ambre, confirmation « Les prix du moment sont copiés dans l'opération et les parts ne changent plus. » Bandeau ambre si G est inconnue (participants inconnus, types sans prix) |
| Détail, `closing`, chacun pour soi | même tableau sans colonne Part, titre « Valeur minée par participant », bandeau « Chacun pour soi », pas de carte « Figer les parts » |
| Détail, `shares` | bandeau émeraude « Parts figées le {date HH:MM} EVE » ; quatre KPI : Valeur totale G, Part du booster {n} %, À répartir {100 − b − c} % (avec corporation), ISK/h de l'opération ; même tableau titré « Répartition au prorata de la valeur », marque « +1 » sur les destinataires du reste d'arrondi ; carte « Prix unitaires figés » ; plus de carte « Valorisation » ni de bouton « Corriger une quantité » |

Store `stores/miningOperation.ts` : `fetchValuation(id)`, `updateValuation(id, patch)` (`application/merge-patch+json`), `freezeShares(id)`. Le type `MiningOperationStatus` gagne `'shares'`.

### Clés i18n (fr / en)

| Clé | fr | en |
|---|---|---|
| `miningOperation.status.shares` | Parts | Shares |
| `miningOperation.steps.planned` / `.in_progress` / `.closing` / `.shares` / `.settled` / `.completed` | Planifiée / En cours / Clôture / Parts / Réglée / Terminée | Planned / In progress / Closing / Shares / Settled / Completed |
| `miningOperation.valuation.title` | Valorisation | Valuation |
| `miningOperation.valuation.form` / `.form.raw` / `.form.compressed` / `.form.reprocessed` | Base de valorisation / Brut / Compressé / Retraité | Valuation basis / Raw / Compressed / Reprocessed |
| `miningOperation.valuation.market` / `.market.jita` / `.market.structure` | Marché / Jita / Structure suivie | Market / Jita / Tracked structure |
| `miningOperation.valuation.side` / `.side.buy` / `.side.sell` | Côté / Achat (buy) / Vente (sell) | Side / Buy / Sell |
| `miningOperation.valuation.basis` | {form}, {market} {side} | {form}, {market} {side} |
| `miningOperation.valuation.yield` / `.yieldFromProfile` | Rendement de retraitement / pré-rempli depuis votre profil de retraitement | Reprocessing yield / prefilled from your reprocessing profile |
| `miningOperation.valuation.boosterCut` / `.corporationCut` / `.booster` / `.noBooster` | Part du booster / Part corporation / Booster / Aucun | Booster cut / Corporation cut / Booster / None |
| `miningOperation.valuation.noCutEachForThemselves` | sans part en chacun pour soi | no cut when everyone keeps their own |
| `miningOperation.valuation.corporationUnknown` | Corporation inconnue : pas de part corporation. | Unknown corporation: no corporation cut. |
| `miningOperation.valuation.rule` | G = Σ quantités × prix unitaire de la base. Booster : G × {booster} %. Corporation : G × {corporation} %. Chaque participant : sa valeur × {rest} %, à l'ISK près ; le reste d'arrondi va aux plus grandes fractions. | G = Σ quantities × unit price of the basis. Booster: G × {booster} %. Corporation: G × {corporation} %. Each participant: their value × {rest} %, to the ISK; the rounding remainder goes to the largest fractions. |
| `miningOperation.valuation.ruleEachForThemselves` | Valeur minée = Σ quantités × prix unitaire de la base, pour chacun. Aucune part, aucun paiement. | Mined value = Σ quantities × unit price of the basis, for each participant. No share, no payment. |
| `miningOperation.valuation.save` | Enregistrer les réglages | Save settings |
| `miningOperation.shares.previewTitle` / `.title` / `.valueTitle` | Aperçu de la répartition au prorata de la valeur / Répartition au prorata de la valeur / Valeur minée par participant | Value prorata split preview / Value prorata split / Mined value by participant |
| `miningOperation.shares.columns.participant` / `.quantity` / `.minedValue` / `.valuePercent` / `.share` / `.iskPerHour` | Participant / Quantité (u) / Valeur minée / % de G / Part / ISK/h | Participant / Quantity (u) / Mined value / % of G / Share / ISK/h |
| `miningOperation.shares.total` / `.boosterBadge` / `.plusBooster` | Total / Booster / + booster {amount} | Total / Booster / + booster {amount} |
| `miningOperation.shares.pricesRead` / `.pricesFrozen` / `.reread` | prix lus à {time} · sync {market} {synced} · recalculé à chaque lecture / prix figés le {date} · sync {market} {synced} / Relire les prix | prices read at {time} · {market} sync {synced} · recomputed on every read / prices frozen on {date} · {market} sync {synced} / Re-read prices |
| `miningOperation.shares.check` / `.checkRemainder` / `.checkUnknown` / `.checkEachForThemselves` | Σ parts {shares} + booster {booster} + corporation {corporation} = {total} = G ✓ / reste d'arrondi {remainder} ISK / contrôle impossible : G inconnue / Σ valeurs minées {total} = G ✓ · aucune part calculée | Σ shares {shares} + booster {booster} + corporation {corporation} = {total} = G ✓ / rounding remainder {remainder} ISK / check impossible: G unknown / Σ mined values {total} = G ✓ · no share computed |
| `miningOperation.shares.roundingMark` | reste d'arrondi | rounding remainder |
| `miningOperation.shares.unknownBanner` | Valeur totale et parts inconnues. Rien n'est compté pour 0. Causes : {causes}. | Total value and shares unknown. Nothing is counted as 0. Causes: {causes}. |
| `miningOperation.shares.unitPricesRead` / `.unitPricesFrozen` / `.noPrice` | Prix unitaires lus / Prix unitaires figés / sans prix | Unit prices read / Frozen unit prices / no price |
| `miningOperation.shares.eachForThemselvesBanner` | Chacun pour soi : chacun garde son minerai, pas de part, pas de paiement. Les prix sont lus maintenant. | Everyone keeps their own ore: no share, no payment. Prices are read now. |
| `miningOperation.shares.kpi.total` / `.booster` / `.toShare` / `.iskPerHour` | Valeur totale G / Part du booster, {percent} % / À répartir, {percent} % / ISK/h de l'opération | Total value G / Booster cut, {percent} % / To share, {percent} % / Operation ISK/h |
| `miningOperation.freeze.title` / `.action` / `.hint` / `.confirm` | Figer les parts / Figer les parts / Les prix du moment sont copiés dans l'opération et les parts ne changent plus. Les paiements viennent ensuite. / Figer les parts maintenant ? Les réglages ne pourront plus changer. | Freeze shares / Freeze shares / Current prices are copied into the operation and the shares stop changing. Payments come next. / Freeze the shares now? Settings can no longer change. |
| `miningOperation.freeze.frozenBanner` | Parts figées le {date} EVE. Quantités, prix et parts ne bougent plus. | Shares frozen on {date} EVE. Quantities, prices and shares no longer change. |
| `miningOperation.freeze.blockers.not_closing` / `.each_for_themselves` / `.quantities_not_known` / `.prices_missing` / `.booster_missing` | L'opération doit être en clôture. / Chacun pour soi : pas de parts. / Quantité inconnue ou en mesure : {names} / Sans prix sur la base : {types} / Choisissez le booster qui reçoit la part du booster. | The operation must be closing. / Everyone keeps their own: no shares. / Unknown or still measured quantity: {names} / No price on the basis: {types} / Choose the booster who receives the booster cut. |

## 6. Exemples chiffrés (tests rouges)

Données communes : celles de #262/#263. Aria Solberg propriétaire d'O « Anomalie Spodumain — 20:00 » (personnages `Aria Solberg`, `Aria II`). Brann Holt `accepted` (`Brann Holt`, `Brann II`). Cyra Lune `accepted` (`Cyra Lune`), booster. O : mode `value_prorata`, base compressé / Jita / **vente** (pour lire les prix de vente du harnais), démarrée à 20:00, terminée à 21:30, instantanés de début pris à 20:00. Types et prix : harnais `BuildsOreValueServiceOnSdeOres`. Veldspar 1230 et Bitumens 45492 ont pour prix équivalent compressé Jita vente 19,61 et 221,45. Horloge `MockClock`. Quantités en unités, montants en ISK.

Quantités (après corrections) de l'exemple de référence **E1** :

| Participant | Personnage | Quantités | Valeur du personnage |
|---|---|---|---|
| Aria Solberg | Aria Solberg | Veldspar 100 000 | 1 961 000 |
| | Aria II | Bitumens 3 333 | 738 093 (738 092,85) |
| Brann Holt (parti à 21:05) | Brann Holt | Bitumens 10 001 | 2 214 721 (2 214 721,45) |
| | Brann II | Veldspar 33 337 | 653 739 (653 738,57) |
| Cyra Lune (booster) | Cyra Lune | Veldspar 9 | 176 (176,49) |

### Prix de la base (`MiningOperationUnitPrices`, harnais de prix)

| # | Base | Types | Prix attendus |
|---|---|---|---|
| P1 | compressé / Jita / vente | Veldspar, Bitumens | 19.61, 221.45 ; `getBatchOrePrices` appelé une fois, `structureId` `null`, rendement 1.0, côté `sell` |
| P2 | brut / Jita / vente | Veldspar, Bitumens | 18.37, 213.9 |
| P3 | retraité / Jita / vente, rendement 0,78 | Veldspar, Bitumens | 13.3224, 831.2148 (#259 A11, A13) |
| P4 | compressé / Jita / vente, sans prix Jita pour Compressed Bitumens | Bitumens | `null` (pas de repli sur le brut 213,9) |
| P5 | retraité / Jita / vente, rendement 0,78 | Fullerite-C50 | `null` (gaz non retraitable, R8) |
| P6 | compressé / structure 1000000000001 / vente, Compressed Veldspar à 1 100 dans la structure | Veldspar | 1100.0 ; `structureId` 1000000000001 transmis |
| P7 | compressé / Jita / achat, Compressed Veldspar acheté 18,00 | Veldspar | 18.0 ; côté `buy` transmis |
| P8 | `getLastSyncTime()` Jita 2026-11-05T21:00:00Z | — | `pricesSyncedAt` 21:00:00 ; structure : `StructureMarketService::getLastSyncTime(1000000000001)` |
| P9 | `MiningValuationForm::priceKeyOn()` | 6 combinaisons | tableau de R6 |

### Valeur minée (`MiningOperationValuator`, sans base)

| # | Situation | Attendu |
|---|---|---|
| V1 | E1, prix P1 | Aria 2 699 093 (2 699 092,85 arrondi), Brann 2 868 460 (2 868 460,02), Cyra 176 ; G 5 567 729 |
| V2 | E1, `Brann II` début `reconnect_required`, sans correction | Brann `unknown`, `minedValue` `null` ; Aria 2 699 093 et Cyra 176 connus ; G `null` ; toutes les parts, `boosterCut`, `valuePercent` `null` ; `freezeBlockers` [`quantities_not_known`] |
| V3 | V2 puis correction de `Brann II` Veldspar = 33 337 par Aria | identique à V1 |
| V4 (multi-personnages, arrondi unique) | Brann : `Brann Holt` Bitumens 10 001, `Brann II` Bitumens 1 | personnages 2 214 721 et 221 (affichage) ; participant 2 214 943 (10 002 × 221,45 = 2 214 942,90), pas 2 214 942 |
| V5 (prix inconnu) | E1, prix P4 | Aria `null`, Brann `null` (Bitumens), Cyra 176 ; G `null` ; `missingPriceTypeIds` [45492] ; `unitPrices` Bitumens `null`, Veldspar 19.61 ; `freezeBlockers` [`prices_missing`] |
| V6 (participant sans minerai) | Cyra sans quantité | Cyra 0, connue ; G = 5 567 553 |
| V7 (mesure en cours) | O `closing`, fin de `Aria II` `waiting` | Aria `null` (`measuring`) ; `freezeBlockers` [`quantities_not_known`] |

### Parts (`MiningShareCalculator`, pur)

| # | Valeurs (ordre) | Booster / corporation | Attendu |
|---|---|---|---|
| S1 (3 participants, booster 10 %, reste 2) | [2 699 093, 2 868 460, 176] | 10 / 0 | G 5 567 729. Arrondis inférieurs : 2 429 183 (reste 70), 2 581 614 (0), 158 (40), booster 556 772 (90), corporation 0 (0) ; R = 2 → booster +1, Aria +1. **Parts [2 429 184, 2 581 614, 158], booster 556 773, corporation 0** ; `roundingAdjustment` [1, 0, 0], booster 1 ; contrôle 5 010 956 + 556 773 + 0 = 5 567 729 |
| S2 (avec part corporation) | idem | 10 / 5 | arrondis 2 294 229 (5), 2 438 191 (0), 149 (60), booster 556 772 (90), corporation 278 386 (45) ; R = 2 → booster +1, Cyra +1. **Parts [2 294 229, 2 438 191, 150], booster 556 773, corporation 278 386** ; somme 5 567 729 |
| S3 (égalité de restes) | [1, 1, 1] | 50 / 0 | G 3 ; arrondis 0, 0, 0, booster 1, tous de reste 50 ; R = 2 → Aria +1, Brann +1 (participants d'abord, dans l'ordre). Parts [1, 1, 0], booster 1 |
| S4 (sans part) | [2 699 093, 2 868 460, 176] | 0 / 0 | parts identiques aux valeurs, booster 0, R 0 |
| S5 (tout au booster et à la corporation) | [1 000, 2 000] | 60 / 40 | parts [0, 0], booster 1 800, corporation 1 200 |
| S6 (valeur inconnue) | [2 699 093, `null`, 176] | 10 / 0 | `totalValue`, `boosterCut`, `corporationCut`, toutes les parts et `roundingAdjustment` `null` |
| S7 (vide ou nul) | [0, 0] | 10 / 0 | G 0, parts [0, 0], booster 0 |
| S8 (grand montant) | [90 000 000 000, 10 000 000 001] | 7 / 3 | aucun dépassement d'entier ; somme des parts + booster + corporation = 100 000 000 001 |

### ISK/h (E1, S1)

| # | Situation | Attendu |
|---|---|---|
| I1 | Aria, début 20:00, fin d'opération 21:30 | `durationSeconds` 5 400, `iskPerHour` 1 799 395,33 (à 0,01 près) |
| I2 | Brann parti à 21:05 | 3 900 s, 2 647 809,23 |
| I3 | opération 20:00 → 21:30, G 5 567 729 | 3 711 819,33 |
| I4 | V2 | Brann `null` ; Aria 1 799 395,33 ; opération `null` |
| I5 | participant dont tous les personnages sont corrigés sans instantané de début `taken` | `durationSeconds` et `iskPerHour` `null`, valeur minée connue |
| I6 | `% de G` S1 | Aria 48,477…, Brann 51,519…, Cyra 0,0032 (à 0,001 près) ; V2 : `null` partout |

### Chacun pour soi

| # | Situation | Attendu |
|---|---|---|
| CS1 | E1 en `each_for_themselves`, réglages par défaut, O `closing` | valeurs minées et G de V1, ISK/h de I1-I3 ; `share`, `valuePercent`, `boosterCut`, `corporationCut` `null` ; `freezeBlockers` [`each_for_themselves`], `canFreeze` faux |
| CS2 | Aria, `PATCH` `{boosterCutPercent: 10}` | `400` « An each-for-themselves operation has no booster or corporation cut » |
| CS3 | Aria, `PATCH` `{boosterMemberId: Cyra}` | `400` idem |
| CS4 | Aria, `POST …/freeze-shares` | `400` « An each-for-themselves operation has no shares » |
| CS5 | Aria, `PATCH` `{valuationForm: "raw"}` | `200`, base brut ; valeurs recalculées au prix brut |

### Réglages (`PATCH …/valuation`)

| # | Entrée | Sortie attendue |
|---|---|---|
| A1 | lecture d'O neuve | `compressed`, `jita`, `buy`, booster 0, corporation 0, `boosterMemberId` `null`, `reprocessYield` `null` |
| A2 | Aria, `{valuationSide: "sell", boosterCutPercent: 10, boosterMemberId: Cyra}` | `200` ; `settings` à jour, `boosterCharacterName` « Cyra Lune » ; événement `valuation_updated` vers Aria, Brann, Cyra |
| A3 | Aria, `{valuationMarket: "structure"}` sans structure | `400` « A structure market needs a structure » |
| A4 | Aria, `{valuationMarket: "structure", structureId: 1000000000001, structureName: "Refinery B-1"}` puis `{valuationMarket: "jita"}` | après le second : `structureId` et `structureName` `null` |
| A5 | Aria, `{valuationForm: "reprocessed"}` sans rendement | `400` « A reprocessed valuation needs a reprocessing yield » |
| A6 | Aria, `{valuationForm: "reprocessed", reprocessYield: 0.72358}` puis `{valuationForm: "compressed"}` | après le second : `reprocessYield` `null` |
| A7 | Aria, `{reprocessYield: 0}` ; `{reprocessYield: 1.5}` ; `{boosterCutPercent: 101}` ; `{boosterCutPercent: -1}` ; `{valuationForm: "best"}` ; `{valuationSide: "mid"}` | `422`, violation sur le champ |
| A8 | Aria, `{boosterCutPercent: 60, corporationCutPercent: 41}` | `400` « Booster and corporation cuts cannot exceed 100 % » |
| A9 | Dorn propriétaire d'une opération privée sans corporation, `{corporationCutPercent: 5}` | `400` « A corporation cut needs a known corporation » |
| A10 | Aria, `{boosterMemberId: Cyra pending}` ; identifiant d'un membre d'une autre opération | `400` « The booster must be an accepted participant » |
| A11 | Brann `member`, `{boosterCutPercent: 10}` | `403` « Only the operation owner or an admin can perform this action » |
| A12 | Aria, O `shares` | `400` « The shares of this operation are frozen » |
| A13 | Aria, O `in_progress`, `{boosterCutPercent: 10}` | `200` (réglage permis avant la clôture) |
| A14 | Cyra `pending`, `GET …/valuation` | `403` « You do not have access to this operation » |

### Figer (`POST …/freeze-shares`)

| # | Entrée | Sortie attendue |
|---|---|---|
| F1 | Aria, E1, réglages A2, horloge 2026-11-05T21:52:00Z, sync Jita 21:00 | `200`, `status` `shares`, `sharesFrozenAt` 21:52 ; 2 prix figés (Veldspar 19.61, Bitumens 221.45) ; 3 valeurs figées (positions 0, 1, 2 : Aria 2 699 093, Brann 2 868 460, Cyra 176) ; `pricesSyncedAt` 21:00 ; événement `shares_frozen` |
| F2 | F1 puis `GET …/valuation` après une sync Jita qui passe Compressed Veldspar à 25,00 | `frozen` vrai ; parts, booster et G de S1 inchangés ; `unitPrices` Veldspar 19.61 ; `pricesReadAt` = `sharesFrozenAt` |
| F3 | F1 puis suppression du compte de Brann | valeur figée gardée, `memberId` `null`, `characterName` « Brann Holt » ; parts de S1 inchangées |
| F4 | Aria, O `in_progress` | `400` « Only a closing operation can have its shares frozen » |
| F5 | Aria, V2 | `400` « Every mined quantity must be known before freezing the shares: Brann Holt » ; O reste `closing`, aucune ligne figée |
| F6 | Aria, V5 | `400` « Some ores have no price on the valuation basis: Bitumens » |
| F7 | Aria, booster 10 % sans booster | `400` « Choose the booster who receives the booster cut » |
| F8 | Brann `member` | `403` « Only the operation owner or an admin can perform this action » |
| F9 | Aria, O `shares` | `400` « Only a closing operation can have its shares frozen » |
| F10 | F1 puis Aria corrige une quantité | `400` « Quantities can only be corrected while the operation is closing » (#263 C-3 inchangé) |
| F11 | F1 puis Brann rejoint une autre opération avec `Brann Holt` | `200` : le personnage n'est plus dans une opération ouverte (R16) |
| F12 | F1, hub Mercure en panne | `200`, `warning` journalisé |

### Statuts

| # | Entrée | Sortie attendue |
|---|---|---|
| D1 | `MiningOperationStatus::isOpen()` | `planned`, `in_progress`, `closing` vrai ; `shares`, `completed` faux |
| D2 | `acceptsParticipants()` | `planned`, `in_progress` vrai ; `closing`, `shares`, `completed` faux |
| D3 | `POST …/characters/{id}/snapshot-retry` sur O `shares` | `400` « This snapshot cannot be retried » |

### Front

| # | Entrée | Sortie attendue |
|---|---|---|
| FR1 | détail `closing`, valorisation S1 | tableau : Aria 2 699 093 / 48,5 % / 2 429 184 / 1 799 395/h ; Cyra « Booster », « + booster 556 773 » ; contrôle « Σ parts 5 010 956 + booster 556 773 + corporation 0 = 5 567 729 = G ✓ · reste d'arrondi 2 ISK » |
| FR2 | valorisation V2 | Brann « inconnue », parts et % « inconnu(e) », bandeau ambre, contrôle « contrôle impossible : G inconnue », bouton « Figer les parts » désactivé avec « Quantité inconnue ou en mesure : Brann Holt » ; jamais « 0 » |
| FR3 | Brann `member` | carte « Valorisation » en lecture seule, pas de carte « Figer les parts » |
| FR4 | réglages, radio Retraité, profil de retraitement 0,72358 | champ rendement visible, pré-rempli 72,36 % ; enregistrer → `updateValuation(O, {valuationForm: 'reprocessed', reprocessYield: 0.72358})` en `application/merge-patch+json` |
| FR5 | chacun pour soi | pas de colonne Part, booster et corporation désactivés à 0, stepper à 4 étapes, pas de carte « Figer les parts » |
| FR6 | clic « Figer les parts », confirmation | `freezeShares(O)` ; `shares_frozen` → `fetchDetail(O)` et `fetchValuation(O)` |
| FR7 | détail `shares` | bandeau « Parts figées le … », KPI G 5 567 729, booster 556 773, à répartir 5 010 956, marques « +1 » sur Aria ; pas de carte « Valorisation » |
| FR8 | stepper `closing` prorata | 5 étapes, « Clôture » courante (`aria-current="step"`), « Planifiée » et « En cours » faites |
| FR9 | message `valuation_updated` pour O puis pour P2 | `fetchValuation(O)` appelé une fois |
| FR10 | textes de chaque nouveau composant en fr puis en en | uniquement les clés i18n de la locale (`expectOnlyLocaleTexts`) |
| FR11 | noms de 60 caractères | tronqués avec infobulle, colonnes de montants intactes |

## 7. Cas limites

- **Prix manquant au moment de figer.** Refus (F6). On peut changer de base (côté, forme, marché) ou attendre la prochaine sync (Jita : 1 h). Il n'y a pas de prix saisi à la main dans ce lot (décision 6).
- **Prix qui change entre l'aperçu et le gel.** Les prix figés sont ceux de la lecture faite par le gel, pas ceux affichés juste avant. L'écart possible est signalé par « prix lus à » ; après le gel, la page affiche les prix figés.
- **Structure sans marché synchronisé.** Tous les prix sont inconnus, sans erreur (#268 V4), et le gel est refusé (F6).
- **Booster qui part plus tôt.** Il reste participant (#263 R8) et garde la part du booster.
- **Booster retiré avant le démarrage** (#262 R14). `boosterMember` pointe un membre `removed` : refus R4.6 à la prochaine modification, refus F7 au gel.
- **Booster ou membre dont le compte est supprimé avant le gel.** Membership supprimée (cascade #262), `boosterMember` remis à `null` (`SET NULL`), donc gel refusé tant qu'un nouveau booster n'est pas choisi. Après le gel : valeur figée gardée (F3).
- **Propriétaire qui supprime son compte.** L'opération et ses données figées disparaissent (cascade #262). Le transfert de propriété reste hors périmètre (décision 10).
- **Opération sans aucun minerai.** G 0, parts 0, gel permis. `valuePercent` `null` (division par 0).
- **Un seul participant.** Sa part vaut `V − arrondis des parts booster et corporation` ; le reste lui revient s'il a la plus grande fraction.
- **Rendement de retraitement du profil inconnu ou absent.** Champ vide et obligatoire pour « Retraité » ; le serveur n'invente pas de rendement.
- **Taxe d'export, taxe de retraitement, prix pondéré par la profondeur du carnet.** Non appliqués : prix unitaire du meilleur ordre, comme la table de #268.
- **Sommes affichées des personnages.** Elles peuvent s'écarter d'un ISK de la valeur du participant (V4). Seule la valeur du participant compte.

## 8. Hors périmètre

- Paiements entre joueurs, motif de don, vérification par wallet, marquage payé, état Réglée / Terminée, archive et récapitulatif (#265). Gel des prix d'une opération « Chacun pour soi » (#265, au passage à Terminée).
- Fleet storage et « minerai détenu par » (#266).
- Temps pondéré (#270).
- Défiger les parts (décision 9) ; prix saisi à la main pour un type sans prix (décision 6) ; transfert de propriété à la suppression du compte (décision 10).
- Réglages de valorisation dans la modale de création (décision 2).
- ISK/h indicatif par les scans pendant l'opération et comparaison scans / ledger (#267).
- Valorisation des sites, des chunks lunaires, taxe lunaire (#273, #274).
- Export CSV de la maquette.

## 9. Termes du glossaire

Utilisés tels quels (section Minage) : **Base de valorisation** (`MiningValuationForm` × `MiningValuationMarket` × `MarketSide` ; une seule par opération, figée avec les parts), **Part** (`MiningShareSplit::$shares`), **Part du booster** (`boosterCutPercent`, montant `boosterCut`), **Rendement de retraitement** (`MiningOperation::$reprocessYield`), **Participant**, **Quantité minée d'une opération**, **Fleet storage** (hors lot, #266).

Termes proposés, à ajouter au glossaire après validation (non ajoutés ici) :

| Terme FR | Term EN | Définition proposée | Symbole(s) |
|---|---|---|---|
| **Part corporation** | Corporation cut | Pourcentage de la valeur d'une opération de minage réservé à la corporation de l'opération avant répartition. 0 en chacun pour soi. | `MiningOperation::$corporationCutPercent`, `corporationCut` |
| **Booster** | Booster | Participant d'une opération de minage qui reçoit la part du booster, en plus de sa propre part. | `MiningOperation::$boosterMember` |
| **Valeur minée** | Mined value | Quantité minée d'un participant valorisée sur la base de l'opération, arrondie à l'ISK. Inconnue si une quantité ou un prix l'est. | `MiningOperationValuator`, `MiningFrozenMinedValue` |
| **Valeur totale d'une opération (G)** | Operation total value | Somme des valeurs minées des participants. Inconnue si l'une l'est. | `MiningShareSplit::$totalValue` |
| **Reste d'arrondi** | Rounding remainder | ISK perdus en arrondissant à l'ISK inférieur les parts et les parts du booster et corporation, redonnés un par un aux plus grandes fractions. | `MiningShareSplit::$roundingRemainder` |
| **Parts figées** | Frozen shares | État Parts d'une opération : prix unitaires et valeurs minées copiés au moment du gel, parts recalculables à l'identique. | `MiningOperationStatus::Shares`, `MiningFrozenUnitPrice`, `MiningFrozenMinedValue` |

## 10. Décisions prises en autonomie

| # | Décision | Alternative écartée | Raison |
|---|---|---|---|
| 1 | La base se choisit dans les réglages et se fige au passage Clôture → Parts, avec les prix du moment | prix figés à la lecture du ledger de fin (maquette « figé à la lecture du ledger de fin ») | Brief de l'issue : figer à l'entrée dans l'étape Parts. Entre la fin du ledger et le gel, l'Owner corrige encore des quantités : figer plus tôt aurait valorisé des corrections à un prix d'avant. |
| 2 | Réglages modifiables de Planifiée à Clôture par un endpoint dédié (`PATCH …/valuation`), création inchangée | champs dans `POST /mining-operations` (modale `create` de la maquette) | Le booster est un participant, qui n'existe pas encore à la création. Ne pas toucher l'entrée de #262 ni ses tests. Les participants voient les règles dès la page d'adhésion (R21). |
| 3 | Défaut « compressé, Jita achat » | aucun défaut, choix obligatoire | Défaut affiché par la maquette (« Compressé · Jita buy (défaut) »). Le compressé vaut une unité brute (#259). |
| 4 | Rendement de retraitement saisi dans les réglages, pré-rempli au front depuis le profil #269 | lu par le serveur dans le profil de l'admin au moment du gel | Valeur explicite, visible de tous et reproductible. Elle ne change pas si l'admin modifie son profil. |
| 5 | Pas de repli entre bases ni de « meilleur prix » | `MiningBestValueCalculator` | Le glossaire définit une seule base par opération. Un meilleur prix différent par type rendrait les parts incontestables pour personne. |
| 6 | Pas de prix saisi à la main ; on débloque en changeant de base ou en attendant la sync | prix figé saisi par l'Owner (bandeau `missing_price` de la maquette) | Moins d'éléments, aucun prix inventé. Un minerai avec matériaux a presque toujours une valeur retraitée ou compressée (#260). À reprendre si le cas se présente. |
| 7 | Quantité inconnue : gel refusé ; la décision de l'Admin est la correction de quantité de #263 (0 compris) | option « figer en excluant le participant » ou « compter 0 » | La correction existe déjà et est tracée (auteur, heure, valeur d'origine `null`). Aucun nouveau concept, et rien n'est compté 0 en silence. |
| 8 | Parts à l'ISK entier ; arrondi inférieur, puis reste aux plus grandes fractions (participants, puis booster, puis corporation, à égalité dans l'ordre du ledger) ; arithmétique entière sur des valeurs déjà arrondies à l'ISK | parts en `float` non arrondies ; reste au propriétaire | Le contrôle Σ = G de la maquette est exact. Le reste est réparti de façon déterministe et équitable. Aucun dépassement d'entier jusqu'à 10^11 ISK. |
| 9 | Pas de défigeage dans ce lot | `DELETE …/freeze-shares` tant qu'aucun paiement n'existe | #265 introduit les paiements et décidera quand défiger reste possible. Ici il n'y a rien à protéger, mais rien non plus à corriger après coup (le gel est confirmé). |
| 10 | Transfert de propriété à la suppression du compte reporté | le traiter ici (décision 12 de #262 le renvoyait à #264) | Sans paiements, la perte est limitée à l'historique. Le transfert touche `AccountDeletionService` et mérite un lot propre avec #265. |
| 11 | Montants figés en `float` (valeurs entières d'ISK, exactes sous 2^53) | `bigint` ou `decimal` | Convention du projet pour les montants ISK. Le calcul reste en entiers. |
| 12 | Parts non stockées : valeurs minées et prix figés, parts recalculées par la fonction pure | stocker aussi chaque part | Une seule source de vérité. Mêmes entrées, même résultat : la reproductibilité est vérifiée par S1 et F2. |
| 13 | Valeur minée arrondie une fois par participant, valeurs des personnages pour l'affichage seulement | somme des valeurs arrondies des personnages | Une seule règle d'arrondi pour le calcul. V4 fige l'écart d'un ISK. |
| 14 | Statut `shares` pas « ouvert » : les personnages sont libérés au gel | libérer à Terminée (#265) | Le minage est mesuré et figé. Attendre les paiements bloquerait l'opération suivante des mêmes personnages. |
| 15 | Part inconnue pour tous dès qu'une valeur l'est, valeurs connues affichées | part connue des autres (maquette `computeShares()`) | Le reste d'arrondi dépend de tous : une part affichée bougerait encore. Conforme à « une somme qui contient un inconnu est inconnue ». |
| 16 | Chacun pour soi : aperçu seulement, pas de gel dans ce lot | figer les prix sans passer par Parts | L'état Parts n'existe pas pour ce mode, et Terminée appartient à #265. |
| 17 | Part corporation incluse (champ de la maquette), 0 par défaut, refusée sans corporation connue | l'ignorer (la maquette la montre à 0) | La maquette la porte. Le destinataire est la corporation figée sur l'opération (#262 R3). |
| 18 | Pourcentages entiers de 0 à 100 | pourcentages décimaux | La maquette saisit des entiers. Arithmétique entière exacte (dénominateur 100). |
| 19 | Pas de nouveau topic Mercure : actions `valuation_updated` et `shares_frozen` sur `/user/{id}/mining-operations` | topic dédié ou push à chaque sync de prix | Même mécanisme que #262/#263. Un push de prix réveillerait toutes les opérations à chaque sync Jita. |
| 20 | Correspondance forme × marché → clé de prix dans `MiningValuationForm::priceKeyOn()`, utilisée aussi par `OreValueTableService` | dupliquer les constantes de #268 | Une seule règle pour la valeur « brut / compressé / retraité » d'un marché. |
| 21 | Maquette complémentaire `mockups/mining-operation-shares.html`, la v4 reste la référence d'ensemble | aucune nouvelle maquette (choix de #262/#263) | Réglages dans le détail, aperçu et bouton de gel n'existent pas dans la v4. La maquette complémentaire reprend ses tokens visuels. |

## 11. Fichiers

Backend : `src/Enum/{MiningValuationForm,MiningValuationMarket,MiningShareBlocker}.php` (nouveaux), `src/Enum/MiningOperationStatus.php` ; `src/Entity/MiningOperation.php`, `src/Entity/{MiningFrozenUnitPrice,MiningFrozenMinedValue}.php` (nouveaux) ; une migration `migrations/Version2026110…php` ; `src/Service/MiningOperation/{MiningOperationUnitPrices,MiningShareCalculator,MiningShareSplit,MiningOperationValuator,MiningShareFreezer}.php` (nouveaux), `MiningOperationLifecycle.php` (`match`) ; `src/Service/OreValueTableService.php` (R6) ; `src/ApiResource/MiningOperation/MiningOperationValuationResource.php` (nouveau), `MiningOperationResource.php`, `MiningOperationInvitationResource.php` ; `src/ApiResource/Input/MiningOperation/UpdateMiningOperationValuationInput.php` (nouveau) ; `src/State/Provider/MiningOperation/MiningOperationValuationProvider.php` (nouveau), `MiningOperationResourceMapper.php`, `MiningOperationInvitationProvider.php` ; `src/State/Processor/MiningOperation/{UpdateMiningOperationValuationProcessor,FreezeMiningSharesProcessor}.php` (nouveaux).

Front : `frontend/src/stores/miningOperation.ts` ; `frontend/src/components/mining-operation/{MiningOperationStepper,MiningValuationSettings,MiningShareTable,MiningFreezeShares}.vue` (nouveaux), `MiningStatusBadge.vue` ; `frontend/src/views/MiningOperation/{MiningOperationDetail,MiningOperationJoin}.vue` ; `frontend/src/i18n/locales/{fr,en}.json`.

## 12. Annexe — Harnais de test

### Dépendances de l'implémentation et ce que le harnais fournit

| Dépendance | Utilisée par | Fournie par le harnais |
|---|---|---|
| `OreValueService` (classe non finale) | `MiningOperationUnitPrices` | **réelle**, construite par `BuildsOreValueServiceOnSdeOres::createOreValueService()` (SDE et matériaux bouchonnés, `jitaMarket()` pour les prix vente et achat) ; P1-P7 |
| `JitaMarketService` (non finale) | prix via `OreValueService`, `getLastSyncTime()` | bouchon du harnais `jitaMarket()` ; P8 ajoute `getLastSyncTime()` au bouchon |
| `StructureMarketService` (non finale) | prix structure, `getLastSyncTime(int)` | `createStub()` (motif #259 A2) ; P6, P8 |
| `MinedQuantityCalculator` (`final readonly`, pur) | `MiningOperationValuator`, `MiningShareFreezer` | **réel**, sur des entités montées par `BuildsLedgerSnapshots` et `BuildsSpodumainOperation` (instantanés et corrections en mémoire) |
| `MiningShareCalculator` (pur) | valuator, provider figé | **réel** ; S1-S8 l'appellent seul, sans entité |
| `MiningOperationUnitPrices` (`final readonly`) | valuator, freezer | **réel** dans les tests de service (sur le harnais de prix) ; non bouchonnable par PHPUnit : les tests du valuator le construisent sur `createOreValueService()` |
| `Psr\Clock\ClockInterface` | `pricesReadAt`, `sharesFrozenAt` | `MockClock` (motif #263) |
| `MiningOperationAccess` | provider, processors | réel (règles #262 R17) |
| `MercurePublisherService` → `HubInterface` | processors (R22) | `HubInterface` bouchonné qui capture les `Update` (motif `BuildsSpodumainOperation`) ; logger `NullLogger` (motif du commit `331688b9`) |
| `EntityManagerInterface` / repositories | processors | mock dans les tests unitaires de processors (motif `EndMiningOperationProcessorTest`) ; base réelle `eve_app_test` dans les tests fonctionnels |
| Prix en cache (Redis `cache.app`) | tests fonctionnels | `self::getContainer()->set(JitaMarketService::class, $stub)` (motif `DeleteAccountConfirmationTest`) ; types Veldspar, Compressed Veldspar, Bitumens, Compressed Bitumens (avec `portionSize`) persistés par le test, comme Spodumain dans `MiningOperationLedgerApiTest` |
| ESI | — | **aucun appel** : ni `EsiClient` ni `TokenManager` dans ce lot |

Frontières mockées : le marché (bouchon Jita/structure), le hub Mercure, l'horloge. Tout le calcul (prix par base, valeurs, parts, arrondis, ISK/h) tourne en vrai.

Classement des tests : S1-S8, P9, D1-D2 en `tests/Unit` sans base ; P1-P8, V1-V7, I1-I6, CS1 en `tests/Unit/Service/MiningOperation` ; A1-A14, F1-F12, CS2-CS5, D3 en `tests/Functional/Api/MiningOperation/MiningOperationValuationApiTest.php` (ordre des refus, migration et données figées réelles) avec quelques cas de processor en unitaire ; FR1-FR11 en `frontend/tests` (store, composants, vue détail, motif `miningOperationLedgerFixtures.ts`). Groupe `#[Group('issue-264')]`.

### Tests existants appelés à changer

| Test | Changement | Raison |
|---|---|---|
| `tests/Unit/Enum/MiningOperationStatusTest.php` : `testEveryStatusButCompletedIsAnOpenOperation` | attendu + `'shares' => false` ; à renommer (`…ButSharesAndCompleted…`) | nouveau cas parcouru par `cases()`, R16 |
| même fichier : `testOnlyPlannedAndInProgressOperationsAcceptParticipants` | attendu + `'shares' => false` | idem |
| `tests/Unit/ApiResource/MiningOperationSubResourceUriVariablesTest.php` | + 3 lignes au fournisseur (`GET` et `PATCH …/valuation`, `POST …/freeze-shares`) | nouveaux endpoints sous un parent |
| `tests/Integration/Migrations/MigrationsReplayTest.php` | doit rester vert avec la nouvelle migration (FK `SET NULL`, index uniques non partiels) | schéma Doctrine = migrations |
| `tests/Unit/Service/MiningOperation/MiningOperationLifecycleTest.php`, `RetryMiningLedgerSnapshotProcessorTest.php` | verts ; D3 ajouté | `match` de `MiningOperationLifecycle` étendu à `Shares` |
| `tests/Unit/State/Provider/MiningOperation/MiningOperationProviderTest.php`, `MiningOperationInvitationProviderTest.php` | verts ; nouvelles assertions sur `valuationSettings`. `array_keys($invitation->characters[0])` (`:80`) porte sur les personnages, pas sur la ressource : inchangé | R21 additif |
| `tests/Unit/Service/MiningOperation/BuildsSpodumainOperation.php` (trait) | + Cyra `accepted` et booster dans une variante ; aucun test existant modifié | données communes de #264 |
| `tests/Unit/Service/Mining/OreValueTableServiceTest.php` (T1-T13 de #268) | verts, sans modification | R6 est un refactor sans changement de comportement |
| `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php` | vert (17 topics) | pas de nouveau topic (R22) |
| Front : `frontend/tests/views/MiningOperation/MiningOperationJoin.spec.ts`, `MiningOperationDetailLifecycle.spec.ts`, `frontend/tests/stores/miningOperation.spec.ts` | fixtures complétées de `valuationSettings` et `sharesFrozenAt` si le contrôle de types l'exige ; comportements inchangés | type `MiningOperation` élargi, statut `'shares'` |

### Questions de spec ouvertes

- Décisions 6 (prix saisi à la main), 9 (défiger) et 10 (transfert de propriété) : à confirmer, ou à rattacher explicitement à #265.
- Pourcentages entiers (décision 18) : un booster à 7,5 % existe-t-il en pratique ? [À CONFIRMER]
- Comportement de `round()` en PHP 8.5 sur une valeur à ,5 exacte en `float` : les exemples l'évitent volontairement ; la règle reste « au plus proche, demi vers l'extérieur ».
