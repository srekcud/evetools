# Spec #266 — Minage groupé : fleet storage déclaré et vérifié par container corpo

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-03, à valider par Sylvain avant `/tdd-red`.
- **Circuit** : `circuit:m`, sans note de câblage séparée : le harnais de test est en annexe (section 12).
- **État du code de référence** : `fix/night-mining-ops` @ `7060e3c6` (#262 à #265 fusionnés).
- **Maquette de référence** : `mockups/grouped-mining-v4.html` (branche `docs/mining-specs`) : `computeShares()` (lignes `holder`), colonne « Minerai détenu par », carte « Fleet storage », champ « Destinataire du fleet storage » de la modale de création, carte « Règles de l'opération » de la page d'adhésion. Complément #266 : `mockups/mining-operation-fleet-storage.html` (clôture avec déclarations, parts avec paiements, vérification vérifiée / écart / inconnue, chacun pour soi, noms longs, vue d'un simple membre).
- **Dépend de** : #264 (valorisation, parts, gel), #265 (paiements, `MiningPaymentPlanner`), sync à la demande des assets corpo (`POST /api/me/corporation/assets/refresh`, existante).
- **Périmètre interdit** : aucune classe Industry, Group Industry ou Stockpile n'est importée ni modifiée (refonte en cours dans un autre fil).

## 1. Contexte

En opération, les mineurs versent souvent leur minerai à un seul pilote (Orca, Porpoise, hauler) qui le compresse, le stocke et le vend. Sans ce lot, #265 suppose que chacun garde ce qu'il a miné : chacun verse au booster `valeur minée − part`. Dès qu'un pilote détient le minerai d'un autre, les montants sont faux.

Ce lot ajoute :

1. **le destinataire du fleet storage** de l'opération (un participant), et un nom de container corpo facultatif ;
2. **la déclaration** de chaque participant : il garde tout, verse tout, ou verse une partie (quantités par type) ;
3. **la dette du fleet storage** : `dette(destinataire → i) = part_i − valeur gardée par i`, figée au gel avec les parts et ajoutée aux paiements de #265 ;
4. **la vérification** (constat, sans effet sur les montants) : le minerai versé se trouve-t-il dans le container corpo nommé, d'après les assets corpo partagés ? « vérifié », « écart de X unités » ou « inconnu ».

Constat dans le code (lu le 2026-11-03) :

| Élément | État | Usage dans #266 |
|---|---|---|
| `MiningPaymentPlanner::plan(list<int> $minedValues, MiningShareSplit, ?int $boosterPosition, bool)` | `montant dû = valeur − part`, lignes `booster_cut` / `corporation_cut` / `collected_corporation_cut` | **inchangé** : il reçoit les valeurs détenues après fleet storage au lieu des valeurs minées (R12) |
| `MiningShareFreezer::freeze()` | fige prix et valeurs, crée les paiements ; dépendances pures en paramètres par défaut (`paymentPlanner`) | contrôles R14, valeur versée figée, lignes de fleet storage (R13) |
| `MiningOperationValuator::value()` | aperçu ou valeurs figées ; `freezeBlockers` | valeurs versée et gardée, dette par participant (R16) |
| `MiningFrozenMinedValue` | `member`, `participantName`, `position`, `minedValue` | + `deliveredValue` (R15) |
| `MiningPaymentKind` | `booster_cut`, `corporation_cut`, `collected_corporation_cut` | + `fleet_storage_share` |
| `VisibleCorporationAssetsFinder::findVisibleAssets(int)` (`src/Service/Assets/`) | assets corpo des divisions visibles et contenu de leurs containers ; `[]` sans réglage de visibilité | source de la vérification (R19) |
| `CachedAssetRepository::hasCorporationAssets(int)`, `CorpAssetVisibilityRepository::findByCorporationId(int)` | dépôts partagés, hors Industry | raisons « inconnu » (R20) |
| `CachedAsset::$cachedAt` | posé à chaque sync (la sync corpo supprime puis réinsère : `AssetsSyncService:121`) | heure de lecture des assets (R20.7) |
| `OreValueService::getBatchOrePrices()` | rend `compressedTypeId` par type brut | variante compressée comptée (R21) |
| `GroupIndustryContainerService::getContainerQuantities()` | parcours des containers nommés, privé, namespace Group Industry | **non importé** : même parcours réécrit dans `src/Service/Assets/CorporationContainerContents` (décision 8) |
| Topic `/user/{id}/mining-operations` | actions `member_*`, `operation_*`, `quantity_corrected`, `valuation_updated`, `shares_frozen`, `payment_updated`, `operation_settled`, `operation_completed` | une action de plus, pas de nouveau topic |

## 2. Modèle

### Une seule migration Doctrine

| Entité / table | Changement | Contraintes |
|---|---|---|
| `MiningOperation` / `mining_operations` | + `fleetStorageRecipient` → `mining_operation_members` nullable ; + `fleetStorageContainerName` varchar(100) nullable | `fleet_storage_recipient_id` `ON DELETE SET NULL` |
| `MiningOperationMember` / `mining_operation_members` | + `fleetStorageDelivery` varchar(10) `MiningFleetStorageDelivery`, défaut `none` ; + `fleetStorageQuantities` json nullable (`array<int typeId, int quantity>`, renseigné seulement pour `partial`) | lignes existantes : `none`, `null` |
| `MiningFrozenMinedValue` / `mining_frozen_mined_values` | + `deliveredValue` float, défaut 0 (entier d'ISK) | lignes existantes : 0 |

Aucune table nouvelle. Les lignes de paiement du fleet storage sont des `MiningPayment` (#265) de type `fleet_storage_share`.

### Enums (`src/Enum/`)

| Enum | Cas (valeur) |
|---|---|
| `MiningFleetStorageDelivery` (nouveau) | `None` (`none`, garde tout), `All` (`all`, verse tout), `Partial` (`partial`, verse les quantités déclarées) |
| `MiningPaymentKind` | + `FleetStorageShare` (`fleet_storage_share`, destinataire → déposant) |
| `MiningShareBlocker` | + `FleetStorageRecipientMissing` (`fleet_storage_recipient_missing`), `FleetStorageExceedsMined` (`fleet_storage_exceeds_mined`) |
| `MiningFleetStorageCheck` (nouveau) | `Verified` (`verified`), `Short` (`short`), `Unknown` (`unknown`), `NothingDelivered` (`nothing_delivered`) |
| `MiningFleetStorageUnknownReason` (nouveau) | `OperationNotEnded`, `OperationArchived`, `NoContainerName`, `NoCorporation`, `NotCorporationMember`, `CorporationAssetsNotSynced`, `NoVisibleDivision`, `AssetsSyncedBeforeEnd`, `ContainerNotFound`, `DeliveredQuantityUnknown` (valeurs en `snake_case`) |

## 3. Règles métier

### Destinataire et container

- **R1 — Un destinataire par opération.** Le destinataire du fleet storage est un participant `accepted` de l'opération, ou personne. Il est choisi par l'administration (#262 R17). C'est lui qui détient le minerai versé et qui doit les dettes. Le container corpo n'est jamais destinataire (décision 2).
- **R2 — Modifier le fleet storage.** `PATCH /api/mining-operations/{operationId}/fleet-storage` (`application/merge-patch+json`) : `recipientMemberId` (UUID ou `null`), `containerName` (1 à 100 caractères ou `null`). Champs absents inchangés. Refus `400`, dans cet ordre :
  1. mode `each_for_themselves` : « An each-for-themselves operation has no fleet storage » ;
  2. statut autre que `planned`, `in_progress`, `closing` : « The shares of this operation are frozen » ;
  3. `recipientMemberId` qui n'est pas un membre `accepted` de cette opération : « The fleet storage recipient must be an accepted participant ».

  Réponse `200`, le fleet storage (R17).
- **R3 — Le destinataire ne se verse rien.** Quand un membre devient destinataire, sa propre déclaration repasse à `none` (quantités à `null`). Les autres déclarations ne changent pas : elles visent « le destinataire de l'opération », quel qu'il soit.
- **R4 — Nom du container.** Libellé saisi tel qu'il apparaît en jeu, comparé à l'identique (casse comprise) au nom des assets corpo, comme Group Industry. Il ne sert qu'à la vérification (R19).

### Déclaration

- **R5 — Déclarer.** `PATCH /api/mining-operations/{operationId}/fleet-storage/declarations/{memberId}` (`application/merge-patch+json`) : `delivery` (`none|all|partial`, obligatoire) et `quantities` (`[{typeId, quantity}]`). Réponse `200`, le fleet storage (R17).
- **R6 — Qui.** Le participant lui-même (membre `accepted` dont l'utilisateur est celui de la membership), l'Owner et les Admins `accepted`. Autre utilisateur ayant accès : `403` « Only the participant, the operation owner or an admin can declare this fleet storage ». Sans accès : `403` « You do not have access to this operation ».
- **R7 — Forme de l'entrée** (`422`, violation sur le champ) : `delivery` dans les trois valeurs ; avec `partial`, `quantities` de 1 à 50 lignes, `typeId` entier > 0 sans doublon, `quantity` entier > 0 ; avec `none` ou `all`, `quantities` absent, `null` ou `[]`.
- **R8 — Cohérence** (`400`, dans cet ordre, après R2.1 et R2.2 appliqués à l'identique) :
  1. membre absent de l'opération (inconnu, autre opération, non UUID) : `404` « Member not found » (avant le `403`) ;
  2. membre non `accepted` : « Only an accepted participant declares a fleet storage » ;
  3. aucun destinataire et `delivery` autre que `none` : « Choose the fleet storage recipient first » ;
  4. membre destinataire et `delivery` autre que `none` : « The fleet storage recipient keeps the ore delivered to them ».
- **R9 — Quantités non contrôlées à la saisie.** La quantité minée peut être encore en mesure : une quantité versée supérieure à la quantité minée est acceptée à la saisie, signalée dans la valorisation et bloque le gel (R14.2). Un type versé que le participant n'a pas miné compte comme une quantité minée de 0.

### Valeur gardée et dette

- **R10 — Valeur versée et valeur gardée** (participant i, valeur minée `V_i` de #264 R9, prix de la base) :
  - `none` : valeur gardée `K_i = V_i`, versée 0 ;
  - `all` : `K_i = 0`, versée `V_i` ;
  - `partial` : quantité gardée par type = quantité minée (tous personnages) − quantité versée ; `K_i = round(Σ quantité gardée × prix unitaire)`, une fois, à l'ISK le plus proche ; valeur versée = `V_i − K_i` (déduite, pour que les valeurs détenues somment exactement à G) ;
  - inconnue (`null`) si `V_i` l'est, ou si une quantité versée dépasse la quantité minée du type (R9).

  Le destinataire est toujours `none`.
- **R11 — Dette du fleet storage** (`MiningFleetStorageCalculator::settle(list<int> $minedValues, list<int> $keptValues, ?int $recipientPosition, list<int> $shares)`, pur, entiers) :
  - sans destinataire : aucune dette, valeurs détenues = valeurs minées ;
  - pour chaque participant i autre que le destinataire : `dette_i = max(0, S_i − K_i)` ;
  - valeurs détenues après fleet storage : `H_i = max(K_i, S_i)` si `K_i < V_i` (il a versé), sinon `H_i = V_i` ; destinataire : `H_r = V_r + Σ_{i≠r} (V_i − K_i) − Σ dette_i` ;
  - contrôles vrais par construction : `Σ H = G` ; `H_i ≥ S_i` pour tous, destinataire compris (`H_r − S_r = (V_r − S_r) + Σ (V_i − max(K_i, S_i)) ≥ 0`) ; une paire n'a jamais deux lignes de sens opposés.
  - entrée `K_i > V_i` : `\LogicException` (impossible après R14.2).
- **R12 — Netting avec #265.** Les paiements de #265 sont planifiés par `MiningPaymentPlanner::plan()` sur les valeurs détenues `H` au lieu des valeurs minées. Un déposant à qui le destinataire doit une dette a `H_i = S_i` : il ne verse plus rien au booster. Le destinataire verse au booster (ou à la corporation) la part du booster de tout le minerai qu'il détient. Sans fleet storage, `H = V` : les paiements sont ceux de #265, à l'identique (exemple FS0).
- **R13 — Lignes au gel.** Après les lignes de #265 (mêmes règles d'ordre), une ligne `fleet_storage_share` par déposant de dette > 0, dans l'ordre des participants figés : payeur = destinataire, bénéficiaire = déposant, `amount` = dette, noms copiés comme en #265 R3. Marquage payé et règlement : #265 R7-R11, sans changement (le débiteur est le destinataire).

### Gel

- **R14 — Blocages du gel** (ajoutés après `booster_missing`, dans `freezeBlockers` et dans les refus de `POST …/freeze-shares`, `400`) :
  1. une déclaration autre que `none` et un destinataire absent ou non `accepted` : « Choose the fleet storage recipient » (`fleet_storage_recipient_missing`) ;
  2. une quantité versée supérieure à la quantité minée du type : « Some delivered quantities exceed what was mined: {noms des participants} » (`fleet_storage_exceeds_mined`).

  Une quantité minée inconnue bloque déjà le gel (`quantities_not_known`, #264 R17.3).
- **R15 — Ce qui est figé.** Dans la même transaction que #264 R17 et #265 R6 : `MiningFrozenMinedValue::$deliveredValue` = valeur versée de R10 ; lignes R13. Après le gel, déclarations, destinataire et container ne changent plus (R2.2) ; la dette se recalcule à l'identique par R11 depuis les valeurs figées (`K_i = minedValue − deliveredValue`), les parts recalculées et la position du destinataire figé. La vérification (R19) reste un constat en direct et n'est pas figée.

### Ressources renvoyées

- **R16 — `MiningOperationValuation`** (#264 R20) : chaque participant gagne `fleetStorageDelivery` (`none|all|partial`, `null` pour un membre supprimé après le gel), `isFleetStorageRecipient`, `deliveredValue`, `keptValue`, `fleetStorageDebt` (dette due par le destinataire, 0 sans versement) ; la valorisation gagne `fleetStorageRecipientMemberId` et `fleetStorageRecipientName`. Règle « inconnu, jamais 0 » : `keptValue` et `deliveredValue` `null` dans les cas de R10 ; `fleetStorageDebt` `null` dès que les parts sont inconnues (#264 R12) ou qu'une valeur gardée l'est. En chacun pour soi : `fleetStorageDelivery` `none`, `deliveredValue` 0, `keptValue` = valeur minée, `fleetStorageDebt` `null`.
- **R17 — `MiningFleetStorage`** (`GET /api/mining-operations/{operationId}/fleet-storage`, accès participation) : `{operationId, available (faux en chacun pour soi), editable (statut planned/in_progress/closing et prorata), recipientMemberId, recipientName, containerName, declarations: [{memberId, characterName, delivery, quantities: [{typeId, typeName, quantity}], canDeclare}]}`, une déclaration par participant `accepted`, dans l'ordre du ledger (#263 R24), destinataire compris (`delivery` `none`). `canDeclare` applique R6 et R8 pour l'utilisateur courant.
- **R18 — `MiningOperation`** (#262 R22) et **`MiningOperationInvitation`** (#262 R24) gagnent `fleetStorageRecipientName` (`null` sans destinataire) : carte « Règles de l'opération » de la page d'adhésion.

### Vérification par container corpo

- **R19 — Calcul.** `GET /api/mining-operations/{operationId}/fleet-storage/verification` (accès participation). Quantité attendue par type brut = Σ des quantités versées par les déposants (`all` : leurs quantités minées par type ; `partial` : leurs quantités déclarées). Quantité trouvée = Σ des quantités de ce type et de sa variante compressée dans les containers corpo nommés `containerName` (tous ceux qui portent ce nom, chaque asset compté une fois), parmi `VisibleCorporationAssetsFinder::findVisibleAssets(corporationId de l'opération)`. Le finder ne lit que les assets des hangars de division visibles et le contenu direct de leurs containers : un container nommé posé dans un hangar est lu, le contenu d'un sous-container ne l'est pas. Par type : `verified` si trouvée ≥ attendue ; sinon `short` avec `missingQuantity` = attendue − trouvée. Le surplus (minerai du destinataire, d'une autre opération) n'est pas un écart.
- **R20 — Inconnu, jamais 0.** Statut `unknown` pour toute l'opération, avec la première raison applicable, dans cet ordre :
  1. statut `planned` ou `in_progress` : `operation_not_ended` ;
  2. statut `settled` ou `completed` : `operation_archived` (le container a pu être vidé depuis le règlement) ;
  3. `containerName` `null` : `no_container_name` ;
  4. `corporationId` de l'opération `null` : `no_corporation` ;
  5. personnage principal du lecteur d'une autre corporation que l'opération : `not_corporation_member` (les assets corpo ne sont montrés qu'aux membres de la corporation) ;
  6. `hasCorporationAssets()` faux : `corporation_assets_not_synced` ;
  7. aucun réglage de visibilité ou aucune division visible : `no_visible_division` ;
  8. plus récent `cachedAt` des assets visibles antérieur à `endedAt` : `assets_synced_before_end` ;
  9. aucun asset visible de ce nom : `container_not_found` (un container absent n'est pas un container vide) ;
  10. un déposant `all` de quantité minée inconnue ou en mesure : `delivered_quantity_unknown`.

  Les quantités trouvées sont alors `null`, jamais 0.
- **R21 — Variante compressée.** Une unité compressée compte pour une unité brute (glossaire, Base de valorisation, #259). La variante est le `compressedTypeId` rendu par `OreValueService::getBatchOrePrices()` pour les types attendus, en un seul appel.
- **R22 — Sans versement.** Aucune déclaration autre que `none` : statut `nothing_delivered`, `lines: []`, sans lecture des assets (passe avant R20.3 et après R20.1-2). Chacun pour soi : toujours `nothing_delivered`.
- **R23 — Ressource `MiningFleetStorageVerification`** : `{operationId, status, unknownReason, containerName, assetsReadAt (plus récent cachedAt, null si inconnu), lines: [{typeId, typeName, expectedQuantity, foundQuantity, missingQuantity, status}]}`, lignes triées par `typeName` ; en `unknown`, `foundQuantity` et `missingQuantity` `null`, `status` `unknown` sur chaque ligne dont l'attendu est connu (aucune ligne pour R20.10).
- **R24 — Sans effet sur l'argent.** La vérification ne bloque pas le gel, ne change aucune dette et n'est pas stockée. Une dette se règle sur la déclaration ; l'écart sert à la discussion entre joueurs avant le gel.
- **R25 — Actualiser.** Le front propose « Actualiser les assets corpo », qui appelle l'endpoint existant `POST /api/me/corporation/assets/refresh` (inchangé, il demande un directeur lié), puis relit la vérification quand la sync `corporation-assets` du lecteur se termine (`stores/sync.ts`). Bouton affiché pour `corporation_assets_not_synced`, `assets_synced_before_end`, `container_not_found` et `short`.

### Temps réel

- **R26 — Actions publiées.** `publishMiningOperationEvent()` publie `fleet_storage_updated` (`{memberId}` après R5, `{memberId: null}` après R2) vers `MiningOperationAudience::userIdsOf()`. Pas de nouveau topic, `getTopicsForUser()` inchangé (17 topics). La vérification n'est pas poussée.
- **R27 — Front.** La vue détail recharge le fleet storage et la valorisation sur `fleet_storage_updated` de l'opération affichée, et la vérification aussi ; la vérification se recharge sur la fin de la sync `corporation-assets` (R25) et sur `operation_ended`.

## 4. Endpoints API Platform

Toutes les opérations : `security: "is_granted('ROLE_USER')"`, `paginationEnabled: false`, préfixe `/api`, exigence UUID comme en #262. Ordre des refus : `401`, `404`, `403`, `422`, puis règles `400`.

| Ressource | Opération | `uriTemplate` | `uriVariables` | Entrée | Provider / Processor | Réponse |
|---|---|---|---|---|---|---|
| `MiningFleetStorageResource` (sans identifiant) | `Get` | `/mining-operations/{operationId}/fleet-storage` | `operationId` → `Link(fromClass: MiningOperationResource::class)` | — | `MiningFleetStorageProvider` | 200 (R17) |
| | `Patch` | `/mining-operations/{operationId}/fleet-storage` | idem | `UpdateMiningFleetStorageInput`, `inputFormats: ['json' => ['application/merge-patch+json']]` | `MiningFleetStorageProvider` / `UpdateMiningFleetStorageProcessor` | 200 (R2-R4) |
| `MiningFleetStorageDeclarationResource` | `Patch` | `/mining-operations/{operationId}/fleet-storage/declarations/{memberId}` | `operationId` → `Link(fromClass: MiningOperationResource::class)`, `memberId` → `Link(fromClass: MiningOperationMemberResource::class)` | `DeclareMiningFleetStorageInput`, `inputFormats` merge-patch | `MiningFleetStorageDeclarationProvider` / `DeclareMiningFleetStorageProcessor`, `output: MiningFleetStorageResource::class` | 200 (R5-R9) |
| `MiningFleetStorageVerificationResource` (sans identifiant) | `Get` | `/mining-operations/{operationId}/fleet-storage/verification` | `operationId` → `Link(fromClass: MiningOperationResource::class)` | — | `MiningFleetStorageVerificationProvider` | 200 (R19-R23) |

`MiningOperationSubResourceUriVariablesTest` reçoit les quatre lignes.

Répartition du code :
- entités : `MiningOperation::assignFleetStorage(?MiningOperationMember, ?string)` (R1-R3), `getFleetStorageRecipient()`, `getFleetStorageContainerName()` ; `MiningOperationMember::declareFleetStorage(MiningFleetStorageDelivery, ?array)`, getters ; `MiningFrozenMinedValue::$deliveredValue` (paramètre de constructeur ajouté en dernier, défaut 0) ;
- `src/Service/MiningOperation/` : `MiningFleetStorageCalculator` et son résultat `MiningFleetStorageSettlement` (`heldValues`, `debts`) (R11, pur) ; `MiningFleetStorageKeeping` (R10 : valeurs gardée et versée d'un participant, pur) ; `MiningFleetStorageDeclarations` (R2-R9 : contrôles et écriture, sous verrou `LocksMiningOperation`) ; `MiningFleetStorageVerifier` (R19-R22) ; `MiningOperationValuator` (R14, R16 ; calculateur en paramètre par défaut) ; `MiningShareFreezer` (R12, R13, R15 ; calculateur en paramètre par défaut) ;
- `src/Service/Assets/CorporationContainerContents` (nouveau) : `quantitiesIn(list<CachedAsset> $visibleAssets, string $containerName): ?array<int, int>` (somme par type du contenu des containers de ce nom, `null` si aucun container de ce nom). Non utilisé par Group Industry dans ce lot (décision 8).

Processors minces ; mapping par `MiningOperationResourceMapper`.

## 5. Écrans (front)

Direction : HUD EVE de la v4, maquette `mockups/mining-operation-fleet-storage.html`. Montants en ISK entiers `fr-FR` / `en-US`, quantités en unités. `null` s'affiche « inconnu(e) » en ambre via `UnknownValue`, jamais « 0 ». Noms tronqués avec infobulle.

| Où | Contenu |
|---|---|
| Détail, colonne droite, `planned` à `closing`, prorata | carte « Fleet storage » (`MiningFleetStorage.vue`) : pour l'administration, liste « Destinataire du fleet storage » (participants `accepted`, « Aucun ») et champ « Container corpo à vérifier (facultatif) », enregistrés à la sortie du champ ; pour un membre, ligne en lecture seule. Puis une ligne par participant reliée par un rail au « Hold du destinataire » : sélecteur segmenté « Garde tout / Verse tout / Verse une partie » (actif selon `canDeclare`), champs de quantité par type miné (« {versé} / {miné} u ») en partiel, et sous la ligne la dette « {destinataire} lui doit {montant} ISK » avec « part {S} − valeur gardée {K} », ou « garde son minerai » ; quantité versée > minée : champ en ambre et « dépasse le minage ({miné} u) ». Bloc « Hold du destinataire » : valeur détenue avant dettes |
| même carte, sous le flux | vérification (`MiningFleetStorageVerification.vue`) : titre « Container corpo « {nom} » », « assets corpo lus à {HH:MM} », une ligne par type « {trouvée} / {attendue} u » avec badge « vérifié » (émeraude), « écart de {n} u » (ambre) ou « inconnu » (ambre) ; texte de la raison inconnue ; bouton « Actualiser les assets corpo » (R25) ; badge de synthèse dans l'en-tête de carte |
| Détail, `shares` | carte « Fleet storage » en lecture seule (déclarations figées, dettes, vérification en direct) |
| Tableau de répartition (`MiningShareTable.vue`) | colonne « Minerai détenu par » : « lui-même », badge « destinataire », ou nom du destinataire avec « tout versé » / « versé : {type} {quantité} u » ; ligne Total : « fleet storage : {n} déposant(s) » |
| Paiements (`MiningPayments.vue`) | lignes `fleet_storage_share` : « {destinataire} doit {montant} ISK à {déposant} », motif « fleet storage : détient le minerai versé, lui doit sa part moins ce qu'il a gardé », montant en cyan ; texte de règle complété (`payments.ruleFleetStorage`) |
| Règles (carte du détail, page d'adhésion) | ligne « Fleet storage : {nom} » ou « aucun » |
| Chacun pour soi | pas de carte « Fleet storage », colonne « Minerai détenu par » à « lui-même » ; règle « Fleet storage : aucun (chacun pour soi) » |

Store `stores/miningOperation.ts` : `fetchFleetStorage(id)`, `updateFleetStorage(id, patch)`, `declareFleetStorage(id, memberId, declaration)` (les deux en `application/merge-patch+json`), `fetchFleetStorageVerification(id)`, `refreshCorporationAssets()` (`POST /api/me/corporation/assets/refresh`, corps `{}`). Types : `MiningFleetStorage`, `MiningFleetStorageVerification`, `MiningPaymentKind` + `'fleet_storage_share'`, champs de R16.

### Clés i18n (fr / en)

| Clé | fr | en |
|---|---|---|
| `miningOperation.fleetStorage.title` | Fleet storage | Fleet storage |
| `miningOperation.fleetStorage.recipient` / `.noRecipient` / `.container` / `.containerHint` | Destinataire du fleet storage / Aucun / Container corpo à vérifier (facultatif) / nom exact du container en jeu | Fleet storage recipient / None / Corporation container to check (optional) / exact container name in game |
| `miningOperation.fleetStorage.delivery.none` / `.all` / `.partial` | Garde tout / Verse tout / Verse une partie | Keeps everything / Delivers everything / Delivers part |
| `miningOperation.fleetStorage.quantity` / `.exceedsMined` | {delivered} / {mined} u / dépasse le minage ({mined} u) | {delivered} / {mined} u / more than mined ({mined} u) |
| `miningOperation.fleetStorage.debt` / `.debtDetail` / `.keeps` | {recipient} lui doit {amount} ISK / part {share} − valeur gardée {kept} / garde son minerai | {recipient} owes them {amount} ISK / share {share} − kept value {kept} / keeps their ore |
| `miningOperation.fleetStorage.hold` / `.holdDetail` | Hold du destinataire / son minerai {own} + versé {delivered} | Recipient hold / own ore {own} + delivered {delivered} |
| `miningOperation.fleetStorage.rule` | Chaque participant déclare s'il verse son minerai au destinataire. Le destinataire doit à chacun sa part moins ce qu'il a gardé, et verse la part du booster de tout le minerai qu'il détient. | Each participant declares whether they deliver their ore to the recipient. The recipient owes each of them their share minus what they kept, and pays the booster cut on all the ore they hold. |
| `miningOperation.fleetStorage.none` / `.eachForThemselves` | aucun / aucun (chacun pour soi) | none / none (everyone keeps their own) |
| `miningOperation.fleetStorage.holder.self` / `.recipient` / `.all` / `.partial` | lui-même / destinataire / tout versé / versé : {quantities} | themselves / recipient / everything delivered / delivered: {quantities} |
| `miningOperation.fleetStorage.totalDepositors` | fleet storage : {count} déposant(s) | fleet storage: {count} depositor(s) |
| `miningOperation.fleetStorage.verification.title` / `.assetsReadAt` / `.line` / `.refresh` | Container corpo « {name} » / assets corpo lus à {time} / {found} / {expected} u / Actualiser les assets corpo | Corporation container “{name}” / corporation assets read at {time} / {found} / {expected} u / Refresh corporation assets |
| `miningOperation.fleetStorage.verification.status.verified` / `.short` / `.unknown` / `.nothing_delivered` | vérifié / écart de {missing} u / inconnu / rien à vérifier | verified / {missing} u missing / unknown / nothing to check |
| `miningOperation.fleetStorage.verification.reason.operation_not_ended` / `.operation_archived` / `.no_container_name` / `.no_corporation` / `.not_corporation_member` / `.corporation_assets_not_synced` / `.no_visible_division` / `.assets_synced_before_end` / `.container_not_found` / `.delivered_quantity_unknown` | La vérification commence à la fin de l'opération. / Opération archivée : le container a pu être vidé. / Aucun container à vérifier. / Opération sans corporation. / Les assets corpo ne sont visibles que des membres de la corporation. / Assets corpo jamais synchronisés. / Aucune division corpo partagée. / Assets corpo lus à {time}, avant la fin de l'opération ({end}). / Aucun container « {name} » dans les divisions partagées. / Quantité versée inconnue : {names}. | Checking starts once the operation has ended. / Archived operation: the container may have been emptied. / No container to check. / Operation without a corporation. / Corporation assets are only shown to corporation members. / Corporation assets never synced. / No shared corporation division. / Corporation assets read at {time}, before the operation ended ({end}). / No “{name}” container in the shared divisions. / Delivered quantity unknown: {names}. |
| `miningOperation.fleetStorage.verification.noEffect` | Le constat n'empêche pas de figer les parts : la dette reste celle déclarée. | This check does not block freezing the shares: the debt follows the declaration. |
| `miningOperation.payments.kind.fleet_storage_share` | fleet storage : détient le minerai versé, lui doit sa part moins ce qu'il a gardé | fleet storage: holds the delivered ore, owes their share minus what they kept |
| `miningOperation.payments.ruleFleetStorage` | Le destinataire du fleet storage doit à chaque déposant sa part moins ce qu'il a gardé. | The fleet storage recipient owes each depositor their share minus what they kept. |
| `miningOperation.freeze.blockers.fleet_storage_recipient_missing` / `.fleet_storage_exceeds_mined` | Choisissez le destinataire du fleet storage. / Quantité versée supérieure au minage : {names} | Choose the fleet storage recipient. / Delivered quantity above what was mined: {names} |
| `miningOperation.shares.columns.holder` | Minerai détenu par | Ore held by |
| `miningOperation.rules.fleetStorage` | Fleet storage | Fleet storage |

## 6. Exemples chiffrés (tests rouges)

Données communes : exemple E1 de #264 (Aria Solberg Owner : `Aria Solberg` Veldspar 100 000, `Aria II` Bitumens 3 333 ; Brann Holt : Bitumens 10 001, Veldspar 33 337 ; Cyra Lune booster : Veldspar 9), base compressé / Jita vente (Veldspar 19,61, Bitumens 221,45), valeurs `V = [2 699 093, 2 868 460, 176]`, G 5 567 729. Corporation C1 « Corpo Exemple » (`corporationId` de l'opération). Parts S1 (booster 10 %) : `[2 429 184, 2 581 614, 158]`, booster 556 773. Parts S2 (10 / 5) : `[2 294 229, 2 438 191, 150]`, booster 556 773, corporation 278 386. Parts PA3 (0 / 5) : `[2 564 138, 2 725 037, 167]`, corporation 278 387. Montants en ISK.

### Valeur gardée (`MiningFleetStorageKeeping`, pur)

| # | Participant, déclaration | Attendu |
|---|---|---|
| K1 | Aria `none` | gardée 2 699 093, versée 0 |
| K2 | Aria `all` | gardée 0, versée 2 699 093 |
| K3 | Aria `partial` Veldspar 100 000 | gardée 738 093 (3 333 × 221,45 = 738 092,85), versée 1 961 000 |
| K4 | Aria `partial` Veldspar 10 000 | gardée 2 502 993 (90 000 × 19,61 + 738 092,85 = 2 502 992,85), versée 196 100 |
| K5 | Aria `partial` Veldspar 100 001 | gardée et versée `null` (dépasse le minage) |
| K6 | Aria `partial` Mercoxit 5 (non miné) | `null` (minage 0 du type) |
| K7 | Brann `all`, `Brann II` début `reconnect_required` | `null` (valeur minée inconnue) |

### Dette (`MiningFleetStorageCalculator::settle()`, pur)

| # | Valeurs gardées, destinataire, parts | Dettes attendues (par position) | Valeurs détenues `H` |
|---|---|---|---|
| FC0 (sans fleet storage) | `[2 699 093, 2 868 460, 176]`, aucun, S1 | aucune | `V` à l'identique |
| FC1 (tout à Cyra) | `[0, 0, 176]`, Cyra (2), S1 | Aria 2 429 184, Brann 2 581 614 | `[2 429 184, 2 581 614, 556 931]` |
| FC2 (partiel vers Brann) | `[738 093, 2 868 460, 176]`, Brann (1), S1 | Aria 1 691 091 (2 429 184 − 738 093) | `[2 429 184, 3 138 369, 176]` |
| FC3 (gardé plus que la part) | `[2 502 993, 2 868 460, 176]`, Brann (1), S1 | aucune (Aria garde 2 502 993 > 2 429 184) | `[2 502 993, 3 064 560, 176]` |
| FC4 (tout à Cyra, avec corporation) | `[0, 2 868 460, 176]`, Cyra (2), S2 | Aria 2 294 229 | `[2 294 229, 2 868 460, 405 040]` |
| FC5 (corporation seule, tout à Brann) | `[0, 2 868 460, 176]`, Brann (1), PA3 | Aria 2 564 138 | `[2 564 138, 3 003 415, 176]` |
| FC6 (contrôles) | FC0 à FC5 | `Σ H = 5 567 729` ; `H_i ≥ S_i` partout ; aucune dette < 0 | — |
| FC7 | gardée > minée | `\LogicException` | — |

### Paiements au gel (`MiningShareFreezer::freeze()`, #265 G1 étendu)

| # | Réglages et déclarations | Lignes attendues (`position`, `kind`, payeur → bénéficiaire, montant) |
|---|---|---|
| FS0 (sans fleet storage, inchangé) | 10 / 0, Cyra booster, aucun destinataire | identiques à #265 PA1 : 0 `booster_cut` Aria → Cyra 269 909 ; 1 `booster_cut` Brann → Cyra 286 846. Avec 10 / 5 : identiques à PA2. `deliveredValue` 0 partout ; #265 G1-G4 verts |
| FS1 | 10 / 0, Cyra booster et destinataire ; Aria et Brann `all` | 0 `fleet_storage_share` Cyra → Aria 2 429 184 ; 1 `fleet_storage_share` Cyra → Brann 2 581 614. Aucune ligne `booster_cut`. Cyra finit : 5 567 729 − 5 010 798 = 556 931 = 158 + 556 773 |
| FS2 (dette par paire) | 10 / 0, Cyra booster, Brann destinataire ; Aria `partial` Veldspar 100 000 ; Cyra `none` | 0 `booster_cut` Brann → Cyra 556 755 ; 1 `fleet_storage_share` Brann → Aria 1 691 091. Brann finit : 4 829 460 − 556 755 − 1 691 091 = 2 581 614 = S ; Aria : 738 093 + 1 691 091 = 2 429 184 ; Cyra : 176 + 556 755 = 556 931 |
| FS3 | comme FS2 avec Aria `partial` Veldspar 10 000 | 0 `booster_cut` Aria → Cyra 73 809 ; 1 `booster_cut` Brann → Cyra 482 946. Aucune ligne de fleet storage. Somme 556 755 (= #265 PA1) |
| FS4 | 10 / 5, Cyra booster et destinataire ; Aria `all` | 0 `booster_cut` Brann → Cyra 430 269 ; 1 `collected_corporation_cut` Cyra → corporation 278 386 ; 2 `fleet_storage_share` Cyra → Aria 2 294 229. Cyra : 2 699 269 + 430 269 − 278 386 − 2 294 229 = 556 923 = 150 + 556 773 |
| FS5 | 0 / 5, aucun booster, Brann destinataire ; Aria `all` | 0 `corporation_cut` Brann → corporation 278 378 ; 1 `corporation_cut` Cyra → corporation 9 ; 2 `fleet_storage_share` Brann → Aria 2 564 138. Corporation : 278 387 |
| FS6 | FS2, après le gel | `deliveredValue` figées `[1 961 000, 0, 0]` ; `GET …/valuation` après une sync Jita qui passe Compressed Veldspar à 25,00 : Aria `keptValue` 738 093, `fleetStorageDebt` 1 691 091, inchangés |
| FS7 | FS2, Brann marque la ligne 1 payée | `200` (#265 R7 : le destinataire est le débiteur) ; Aria marque la ligne 1 : `200` (Owner) ; Cyra `member` : `403` |

### Gel refusé (`POST …/freeze-shares`)

| # | Entrée | Sortie attendue |
|---|---|---|
| FB1 | FS2 avec Aria `partial` Veldspar 100 001 | `400` « Some delivered quantities exceed what was mined: Aria Solberg » ; `freezeBlockers` [`fleet_storage_exceeds_mined`] ; rien n'est figé |
| FB2 | Aria `all`, destinataire Brann retiré (`removed`) avant le démarrage | `400` « Choose the fleet storage recipient » ; `freezeBlockers` [`fleet_storage_recipient_missing`] |
| FB3 | Aria `all`, destinataire Brann dont le compte est supprimé (`SET NULL`) | idem FB2 |
| FB4 | destinataire supprimé, toutes les déclarations `none` | gel permis, paiements de FS0 |
| FB5 | E1, `Brann II` inconnu et Aria `partial` Veldspar 100 001 | `freezeBlockers` [`quantities_not_known`, `fleet_storage_exceeds_mined`] ; message du premier |

### Fleet storage et déclarations (API)

| # | Entrée | Sortie attendue |
|---|---|---|
| FA1 | Aria, `PATCH …/fleet-storage` `{recipientMemberId: Brann, containerName: "OPS-HAUL"}` | `200`, `recipientName` « Brann Holt », `containerName` « OPS-HAUL » ; événement `fleet_storage_updated` `{memberId: null}` vers Aria, Brann, Cyra |
| FA2 | Brann `member`, même requête | `403` « Only the operation owner or an admin can perform this action » |
| FA3 | Aria, `{recipientMemberId: Cyra pending}` ; membre d'une autre opération | `400` « The fleet storage recipient must be an accepted participant » |
| FA4 | Aria, `{containerName: ""}` ; 101 caractères ; `{recipientMemberId: "abc"}` | `422`, violation sur le champ |
| FA5 | Aria `partial`, puis Aria désigne Aria destinataire | déclaration d'Aria `none`, quantités `null` (R3) |
| FA6 | opération chacun pour soi, `PATCH …/fleet-storage` ou déclaration | `400` « An each-for-themselves operation has no fleet storage » |
| FA7 | O `shares`, `PATCH …/fleet-storage` ou déclaration | `400` « The shares of this operation are frozen » |
| FA8 | Aria pour elle-même, `{delivery: "partial", quantities: [{typeId: 1230, quantity: 100000}]}` | `200`, déclaration d'Aria `partial`, Veldspar 100 000 ; `fleet_storage_updated` `{memberId: Aria}` |
| FA9 | Cyra `member` déclare pour elle-même `{delivery: "all"}` | `200` |
| FA10 | Cyra `member` déclare pour Aria | `403` « Only the participant, the operation owner or an admin can declare this fleet storage » |
| FA11 | Aria (Owner) déclare pour Cyra `{delivery: "all"}` | `200` |
| FA12 | déclaration pour Brann destinataire `{delivery: "all"}` | `400` « The fleet storage recipient keeps the ore delivered to them » |
| FA13 | sans destinataire, `{delivery: "all"}` ; puis `{delivery: "none"}` | `400` « Choose the fleet storage recipient first » ; puis `200` |
| FA14 | `{delivery: "half"}` ; `partial` avec `[]` ; `all` avec une ligne ; `quantity` 0 ; `typeId` en double | `422`, violation sur le champ |
| FA15 | membre `pending` ; identifiant inconnu, d'une autre opération, non UUID | `400` « Only an accepted participant declares a fleet storage » ; `404` « Member not found » |
| FA16 | Dorn sans accès, `GET …/fleet-storage` | `403` « You do not have access to this operation » |
| FA17 | Brann, `GET …/fleet-storage` après FA1 et FA8 | 3 déclarations dans l'ordre du ledger : Aria `partial` (Veldspar 100 000, `canDeclare` faux pour Brann), Brann `none` (destinataire), Cyra `none` ; `editable` vrai |
| FA18 | FA1, `GET /mining-operations/{id}` et invitation | `fleetStorageRecipientName` « Brann Holt » |
| FA19 | hub Mercure en panne | `200`, `warning` journalisé |

### Valorisation (`GET …/valuation`, aperçu en clôture)

| # | Situation | Attendu |
|---|---|---|
| VP1 | FS2 | Aria `fleetStorageDelivery` `partial`, `deliveredValue` 1 961 000, `keptValue` 738 093, `fleetStorageDebt` 1 691 091 ; Brann `isFleetStorageRecipient` vrai, `fleetStorageDebt` 0 ; Cyra `keptValue` 176, dette 0 ; `fleetStorageRecipientName` « Brann Holt » |
| VP2 | FS2 avec `Brann II` inconnu | parts inconnues → `fleetStorageDebt` `null` partout ; Aria `keptValue` 738 093 (sa quantité est connue) |
| VP3 | FB1 | Aria `keptValue`, `deliveredValue`, `fleetStorageDebt` `null` ; jamais 0 |
| VP4 | chacun pour soi E1 (#264 CS1) | `deliveredValue` 0, `keptValue` = valeur minée, `fleetStorageDebt` `null` ; reste de CS1 inchangé |

### Vérification (`GET …/fleet-storage/verification`)

Données : FS2 (attendu Veldspar 100 000), O `closing` terminée à 21:30, `containerName` « OPS-HAUL », corporation C1 avec visibilité des divisions 1 et 2, assets corpo lus à 21:41, lecteur Aria (main en C1).

| # | Assets corpo visibles | Attendu |
|---|---|---|
| VC1 | container « OPS-HAUL » : Compressed Veldspar 60 000, Veldspar 40 000 | `verified` ; ligne Veldspar attendue 100 000, trouvée 100 000, manque 0, `verified` ; `assetsReadAt` 21:41 |
| VC2 | Compressed Veldspar 96 400 | `short` ; trouvée 96 400, `missingQuantity` 3 600 |
| VC3 | Veldspar 120 000 | `verified`, trouvée 120 000 (surplus sans écart) |
| VC4 | deux containers « OPS-HAUL » de 50 000 chacun | `verified`, trouvée 100 000 |
| VC5 | « OPS-HAUL » contient un sous-container « Bin » de Veldspar 100 000 | `short`, trouvée 0, manque 100 000 (limite du finder, un seul niveau ; cas limite documenté) |
| VC6 | container « ops-haul » seulement | `unknown`, `container_not_found` ; `foundQuantity` `null` |
| VC7 | assets lus à 20:40 | `unknown`, `assets_synced_before_end` ; `assetsReadAt` 20:40 |
| VC8 | aucun asset corpo de C1 | `unknown`, `corporation_assets_not_synced` |
| VC9 | réglage de visibilité absent ; divisions `[]` | `unknown`, `no_visible_division` |
| VC10 | lecteur Brann, main dans une autre corporation | `unknown`, `not_corporation_member` (lignes attendues, trouvées `null`) |
| VC11 | `containerName` `null` | `unknown`, `no_container_name` |
| VC12 | opération privée sans `corporationId` | `unknown`, `no_corporation` |
| VC13 | O `in_progress` ; O `settled` | `unknown`, `operation_not_ended` ; `operation_archived` |
| VC14 | toutes les déclarations `none` ; chacun pour soi | `nothing_delivered`, `lines: []`, `VisibleCorporationAssetsFinder` non appelé |
| VC15 | Aria `all`, `Aria II` début `reconnect_required` | `unknown`, `delivered_quantity_unknown`, aucune ligne |
| VC16 | O `shares` (FS2), VC1 | `verified` (constat en direct) ; dettes inchangées |
| VC17 | `getBatchOrePrices` appelé | une fois, avec les types attendus `[1230]` |
| VC18 | Dorn sans accès | `403` « You do not have access to this operation » |

### Temps réel et front

| # | Entrée | Sortie attendue |
|---|---|---|
| MT1 | `getTopicsForUser('user-uuid-123')` | 17 topics, inchangé |
| FR1 | détail `closing`, FS2, Aria | carte « Fleet storage » : destinataire « Brann Holt », container « OPS-HAUL », Aria « Verse une partie », champ Veldspar « 100 000 / 100 000 u », « Brann Holt lui doit 1 691 091 ISK », « part 2 429 184 − valeur gardée 738 093 » ; tableau : Aria « Brann Holt · versé : Veldspar 100 000 u », Brann badge « destinataire », Cyra « lui-même » |
| FR2 | clic « Verse tout » sur la ligne d'Aria | `declareFleetStorage(O, Aria, {delivery: 'all'})` en `application/merge-patch+json` |
| FR3 | Cyra `member` | liste du destinataire et champ container absents ; sélecteur actif seulement sur sa ligne |
| FR4 | VP3 | champ en ambre « dépasse le minage (100 000 u) », dette « inconnue », bouton « Figer les parts » désactivé avec « Quantité versée supérieure au minage : Aria Solberg » |
| FR5 | VC2 | badge « écart » en en-tête, ligne « 96 400 / 100 000 u » avec « écart de 3 600 u », bouton « Actualiser les assets corpo » |
| FR6 | VC7 | « inconnu », « Assets corpo lus à 20:40, avant la fin de l'opération (21:30). » ; jamais « 0 » |
| FR7 | clic « Actualiser les assets corpo » puis sync `corporation-assets` terminée | `refreshCorporationAssets()`, puis `fetchFleetStorageVerification(O)` |
| FR8 | détail `shares`, FS2 | ligne « Brann Holt doit 1 691 091 ISK à Aria Solberg », motif fleet storage ; carte « Fleet storage » en lecture seule |
| FR9 | chacun pour soi | pas de carte « Fleet storage », colonne « Minerai détenu par » à « lui-même » |
| FR10 | `fleet_storage_updated` pour O puis pour P2 | `fetchFleetStorage(O)`, `fetchValuation(O)`, `fetchFleetStorageVerification(O)` appelés une fois chacun |
| FR11 | textes des nouveaux composants en fr puis en en | uniquement les clés de la locale (`expectOnlyLocaleTexts`) |
| FR12 | noms de 60 caractères (destinataire, déposant, container) | tronqués avec infobulle, montants et quantités intacts |

## 7. Cas limites

- **Quantité versée supérieure au minage.** Acceptée à la saisie (le minage peut être encore en mesure), signalée en ambre, bloque le gel (FB1). Jamais plafonnée en silence.
- **Quantité minée inconnue.** Valeur gardée et dette inconnues ; le gel est déjà bloqué par `quantities_not_known`. La correction de quantité de #263 débloque.
- **Destinataire qui part plus tôt.** Il reste participant et destinataire (#263 R8) : rien ne change.
- **Destinataire retiré ou compte supprimé avant le gel.** Plus de destinataire valide : gel bloqué tant qu'une déclaration n'est pas `none` ou qu'un autre destinataire n'est pas choisi (FB2, FB3). Les déclarations des autres restent.
- **Destinataire supprimé après le gel.** Lignes gardées avec leurs noms (#265 : `SET NULL` + nom copié) ; un administrateur les marque encore.
- **Déclaration modifiée après le gel.** Refusée (FA7) : la dette est figée avec les parts. Le défigeage reste hors périmètre (#264 décision 9).
- **Changement de destinataire.** Les déclarations visent le destinataire courant ; le nouveau destinataire repasse à `none` (FA5). Changer de destinataire après un versement réel en jeu est une erreur que les joueurs corrigent avant le gel.
- **Booster destinataire.** Cas courant (Orca) : le booster garde sans ligne la part du booster sur tout le minerai versé (FS1).
- **Déposant qui garde plus que sa part.** Pas de dette ; il verse au booster la différence entre ce qu'il garde et sa part (FS3).
- **Compression, raffinage, vente par le destinataire.** Sans effet sur la dette : elle vaut sur la base de l'opération, figée. Seule la variante compressée est reconnue par la vérification ; un minerai déjà retraité ou vendu donne un écart.
- **Minerai rangé dans un sous-container du container nommé.** Invisible : le finder partagé ne lit qu'un niveau sous les hangars (VC5). Étendre le finder toucherait Group Industry, hors périmètre.
- **Container dans une division non partagée, dans un hangar personnel ou dans le fleet hangar d'un vaisseau.** Invisible des assets corpo partagés : `container_not_found` ou écart. La vérification par les assets du personnage destinataire (maquette v4) est hors périmètre.
- **Assets corpo lus avant la fin.** `assets_synced_before_end` : il faut actualiser (cache ESI des assets corpo de l'ordre de l'heure [À CONFIRMER : durée du cache ESI `/corporations/{id}/assets/`]).
- **Minerai de plusieurs opérations dans le même container.** Le surplus n'est pas un écart (VC3), mais deux opérations peuvent compter le même minerai : la vérification est un constat, pas une preuve.
- **Opérations figées avant le déploiement.** `deliveredValue` 0, aucune déclaration : comportement de #265 à l'identique.

## 8. Hors périmètre

- Lecture des logs de flotte du client (corps de l'issue).
- Container corpo comme destinataire (sans débiteur humain), plusieurs destinataires, versements entre déposants.
- Vérification par les assets du personnage destinataire ou du fleet hangar (maquette v4 « retrouvés dans les assets de Mineur Charlie ») ; vérification par le wallet (#265 section 8).
- Planning du hauler, remplissage du fleet hangar, « fleet hangar vidé » (maquette v4, nouvelle issue liée à #266).
- Récapitulatif partagé (#316), notifications (#271).
- Toute modification de `GroupIndustryContainerService`, des classes Industry, Group Industry ou Stockpile, et de `MiningStockpileCoverage`.

## 9. Termes du glossaire

Utilisés tels quels (section Minage) : **Fleet storage** (« minerai versé par un participant à un destinataire ; il crée une dette du destinataire envers le participant »), **Participant**, **Part**, **Part du booster**, **Base de valorisation** (une unité compressée vaut une unité brute), **Quantité minée d'une opération**, **Opération de minage**.

Termes proposés, à ajouter au glossaire après validation (non ajoutés ici) :

| Terme FR | Term EN | Définition proposée | Symbole(s) |
|---|---|---|---|
| **Destinataire du fleet storage** | Fleet storage recipient | Participant unique d'une opération de minage qui reçoit le minerai versé et doit les dettes du fleet storage. | `MiningOperation::$fleetStorageRecipient` |
| **Déclaration de fleet storage** | Fleet storage declaration | Choix d'un participant : il garde tout, verse tout ou verse des quantités par type au destinataire. | `MiningFleetStorageDelivery`, `MiningOperationMember::declareFleetStorage()` |
| **Valeur gardée** | Kept value | Valeur, sur la base de l'opération, du minerai qu'un participant n'a pas versé au destinataire. | `MiningFleetStorageKeeping`, `keptValue` |
| **Dette du fleet storage** | Fleet storage debt | Montant entier que le destinataire doit à un déposant : sa part moins sa valeur gardée, si positif. Figée au gel. | `MiningFleetStorageCalculator`, `MiningPaymentKind::FleetStorageShare` |
| **Valeur détenue** | Held value | Valeur du minerai qu'un participant détient après le règlement des dettes du fleet storage ; base des paiements au booster et à la corporation. | `MiningFleetStorageSettlement::$heldValues` |
| **Vérification du container** | Container check | Constat, sur les assets corpo partagés, que le minerai versé se trouve dans le container corpo nommé : vérifié, écart ou inconnu. Sans effet sur les montants. | `MiningFleetStorageVerifier`, `MiningFleetStorageCheck` |

## 10. Décisions prises en autonomie

| # | Décision | Alternative écartée | Raison |
|---|---|---|---|
| 1 | Déclaration à trois états : garde tout, verse tout, verse une partie (quantités par type) | case « verse » seule (maquette v4, `p.fleet`) | La formule de l'issue (`part − valeur gardée`) n'a de sens que si un participant peut garder une partie (fleet hangar plein). « Verse tout » évite de ressaisir des quantités connues seulement après la fin. |
| 2 | Destinataire unique, toujours un participant ; le container corpo n'est qu'un lieu de vérification | option « Container corpo « OPS-HAUL » » comme destinataire (maquette v4) | Une dette demande un débiteur humain qui marque ses paiements (#265 R7). Le pilote qui dépose dans le container reste responsable. |
| 3 | Destinataire choisi par l'Owner ou un Admin ; chaque participant déclare pour lui-même, l'administration pour tous | déclaration par l'administration seule | Corps de l'issue : « chaque participant déclare » ; même règle que le marquage payé (#265 décision 5). |
| 4 | Netting : les paiements de #265 sont planifiés sur les valeurs détenues après fleet storage ; `MiningPaymentPlanner` inchangé | lignes brutes de la maquette (destinataire → booster pour chaque déposant) ; netting de toutes les paires | Une paire n'a jamais deux lignes de sens opposés, les montants restent entiers et exacts, aucun nouvel arrondi, et le cas sans fleet storage reste identique (FS0). |
| 5 | Lignes de fleet storage après les lignes de #265 | lignes de fleet storage en tête | Les positions de #265 ne bougent pas, ses tests restent verts. |
| 6 | Valeur gardée arrondie une fois, valeur versée déduite (`V − K`) | arrondir aussi la valeur versée | `Σ H = G` exactement ; même principe que l'arrondi unique de #264 R9. |
| 7 | Quantité versée contrôlée au gel, pas à la saisie | refus `400` à la saisie | Le minage peut être encore en mesure pendant l'opération ; aucun plafonnement silencieux. |
| 8 | Vérification possible sans Group Industry : `VisibleCorporationAssetsFinder`, `CachedAssetRepository`, `CorpAssetVisibilityRepository` (hors Industry) ; parcours des containers réécrit dans `src/Service/Assets/CorporationContainerContents` | importer ou extraire `GroupIndustryContainerService::getContainerQuantities()` | Contrainte de périmètre : Group Industry ne doit être ni importé ni modifié. Duplication assumée d'environ 25 lignes, à fusionner après la refonte Industry (TODO `.claude/tasks/todo.md`). |
| 9 | Vérification réservée aux lecteurs de la corporation de l'opération (`not_corporation_member` sinon) | montrer les quantités à tout participant | Les assets corpo partagés sont réglés pour les membres de la corporation ; une opération peut avoir des participants extérieurs. |
| 10 | Assets lus avant la fin de l'opération : « inconnu » | comparer quand même | Un container lu avant la fin ne peut pas contenir le minerai de fin d'opération ; un écart serait trompeur. |
| 11 | Variante compressée comptée à 1:1 | type brut seul | Le destinataire compresse presque toujours ; le glossaire pose déjà qu'une unité compressée vaut une unité brute. |
| 12 | Vérification non bloquante, non figée, disponible en Clôture et en Parts | bloquer le gel sur un écart | Le constat dépend du cache ESI et des droits du directeur ; un écart se discute entre joueurs. |
| 13 | Déclaration sur `mining_operation_members` (colonnes), pas de table dédiée | table `mining_fleet_storage_declarations` | Une déclaration par membre au plus, cycle de vie du membre : moins d'éléments, cascade gratuite. |
| 14 | Pas de nouveau topic Mercure, action `fleet_storage_updated` | topic dédié | Même audience que les autres événements d'opération. |
| 15 | Maquette complémentaire `mockups/mining-operation-fleet-storage.html` | v4 seule | Déclaration partielle, dette affichée et trois états de vérification diffèrent de la v4. |

## 11. Fichiers

Backend : `src/Enum/{MiningFleetStorageDelivery,MiningFleetStorageCheck,MiningFleetStorageUnknownReason}.php` (nouveaux), `MiningPaymentKind.php`, `MiningShareBlocker.php` ; `src/Entity/{MiningOperation,MiningOperationMember,MiningFrozenMinedValue}.php` ; une migration `migrations/Version2026110…php` ; `src/Service/MiningOperation/{MiningFleetStorageCalculator,MiningFleetStorageSettlement,MiningFleetStorageKeeping,MiningFleetStorageDeclarations,MiningFleetStorageVerifier,MiningFleetStorageVerification}.php` (nouveaux), `MiningOperationValuator.php`, `MiningOperationValuation.php`, `MiningParticipantValuation.php`, `MiningShareFreezer.php` ; `src/Service/Assets/CorporationContainerContents.php` (nouveau) ; `src/ApiResource/MiningOperation/{MiningFleetStorageResource,MiningFleetStorageDeclarationResource,MiningFleetStorageVerificationResource}.php` (nouveaux), `MiningOperationValuationResource.php`, `MiningOperationResource.php`, `MiningOperationInvitationResource.php` ; `src/ApiResource/Input/MiningOperation/{UpdateMiningFleetStorageInput,DeclareMiningFleetStorageInput}.php` (nouveaux) ; `src/State/Provider/MiningOperation/{MiningFleetStorageProvider,MiningFleetStorageDeclarationProvider,MiningFleetStorageVerificationProvider}.php` (nouveaux), `MiningOperationResourceMapper.php`, `MiningOperationValuationProvider.php`, `MiningOperationInvitationProvider.php` ; `src/State/Processor/MiningOperation/{UpdateMiningFleetStorageProcessor,DeclareMiningFleetStorageProcessor}.php` (nouveaux).

Front : `frontend/src/stores/miningOperation.ts` ; `frontend/src/components/mining-operation/{MiningFleetStorage,MiningFleetStorageVerification}.vue` (nouveaux), `MiningShareTable.vue`, `MiningPayments.vue`, `MiningValuationRules.vue`, `MiningFreezeShares.vue` ; `frontend/src/views/MiningOperation/{MiningOperationDetail,MiningOperationJoin}.vue` ; `frontend/src/i18n/locales/{fr,en}.json`.

Docs au vert : `CLAUDE.md`, section Mercure : rien à changer (pas de nouveau topic). Glossaire : termes de la section 9 après validation.

## 12. Annexe — Harnais de test

### Dépendances de l'implémentation et ce que le harnais fournit

| Dépendance | Utilisée par | Fournie par le harnais |
|---|---|---|
| `MiningShareCalculator`, `MiningPaymentPlanner` (purs) | calculateur, freezer | **réels** ; FC et FS les appellent sans entité |
| `MiningFleetStorageCalculator`, `MiningFleetStorageKeeping` (purs) | valuator, freezer | **réels**, `tests/Unit` sans base (K1-K7, FC0-FC7) |
| `MiningShareFreezer`, `MiningOperationValuator` | FS, FB, VP | **réels** sur `BuildsValuedSpodumainOperation::e1Operation()` et `valuatorOn()` (prix par `jitaMarketSyncedAt()`), `typeNameResolverOfSdeOres()` ; paiements lus comme dans `BuildsMiningPayments` / `MiningShareFreezerPaymentsTest` |
| `MinedQuantityCalculator` | K7, VC15, FB5 | **réel**, instantanés de `BuildsLedgerSnapshots` (`failedStartSnapshot` pour `reconnect_required`) |
| `VisibleCorporationAssetsFinder` | vérifieur | unitaire : **réel** sur des repositories mockés (`CachedAssetRepository::findByCorporationAndDivisions()` / `findContentsOfContainers()`, `CorpAssetVisibilityRepository::findByCorporationId()`) rendant des `CachedAsset` construits en test (`setItemName`, `setLocationId`, `setCachedAt`) ; fonctionnel : `CachedAsset` et `CorpAssetVisibility` persistés dans `eve_app_test` |
| `CorporationContainerContents` (pur) | vérifieur | **réel** (VC1-VC6) |
| `OreValueService::getBatchOrePrices()` | R21 | harnais `BuildsOreValueServiceOnSdeOres` (Veldspar 1230 → Compressed Veldspar), espion du nombre d'appels pour VC17 |
| `Psr\Clock\ClockInterface` | `endedAt`, heure de lecture | `MockClock` |
| `MiningOperationAccess`, `MiningOperationRequest` | providers, processors | réels |
| `MercurePublisherService` → `HubInterface` | R26 | `HubInterface` bouchonné qui capture les `Update` (motif `BuildsSpodumainOperation`), `NullLogger` |
| `EntityManagerInterface` | processors, freezer | mock en unitaire (motif `FreezeMiningSharesProcessorTest`) ; base réelle en fonctionnel |
| `stores/sync.ts` | FR7 | store Pinia de test avec `syncStatus['corporation-assets']` positionné à `completed` |
| ESI | — | **aucun appel** (`POST /me/corporation/assets/refresh` non exercé côté back dans ce lot) |

Frontières mockées : marché (bouchon Jita), hub Mercure, horloge, dépôts d'assets en unitaire. Calculs de dette, netting, contrôles de gel et parcours des containers tournent en vrai.

Classement : K1-K7, FC0-FC7, VC1-VC6 (`CorporationContainerContents`) en `tests/Unit` sans base ; FS0-FS6, FB1-FB5, VP1-VP4, VC7-VC17 en `tests/Unit/Service/MiningOperation` (`MiningFleetStorageFreezeTest`, `MiningFleetStorageVerifierTest`) ; FA1-FA19, FS7, VC1, VC18 en `tests/Functional/Api/MiningOperation/MiningOperationFleetStorageApiTest.php` (nouveau, trait `DrivesTheE1MiningOperation`) ; FR1-FR12 en `frontend/tests` (store, `MiningFleetStorage`, `MiningFleetStorageVerification`, `MiningShareTable`, `MiningPayments`, fixtures sur le motif `miningOperationValuationFixtures.ts`). Groupe `#[Group('issue-266')]`. Aucun test n'importe une classe `GroupIndustry*`, `Industry*` ou Stockpile.

### Tests existants appelés à changer

| Test | Changement | Raison |
|---|---|---|
| `tests/Unit/ApiResource/MiningOperationSubResourceUriVariablesTest.php` | + 4 lignes (`GET` et `PATCH …/fleet-storage`, `PATCH …/declarations/{memberId}`, `GET …/verification`) | endpoints sous un parent |
| `tests/Integration/Migrations/MigrationsReplayTest.php` | vert avec la nouvelle migration | schéma = migrations |
| `MiningShareFreezerTest`, `MiningShareFreezerPaymentsTest`, `FreezeMiningSharesProcessorTest`, `MiningOperationValuatorTest`, `MiningOperationValuationProviderTest` (constructions) | verts : calculateur en paramètre par défaut, `deliveredValue` défaut 0 | R12, R15 |
| `MiningPaymentPlannerTest` (PA1-PA8) | verts sans modification | planner inchangé |
| `MiningOperationValuationApiTest`, `MiningOperationSettlementApiTest`, `MiningOperationArchiveApiTest` | verts si les assertions portent sur des champs ; à revoir s'ils comparent la réponse JSON entière (nouveaux champs R16, R18) | champs ajoutés |
| `AccountDeletionServiceTest::testNoForeignKeyColumnReferencesTheDeletedAccountAndTheOtherAccountKeepsAllItsRows` | vert : `fleet_storage_recipient_id` en `SET NULL` | FK nouvelle |
| `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php` | vert (17 topics) | pas de nouveau topic |
| Tests d'enums s'ils énumèrent `cases()` (`MiningShareBlocker`, `MiningPaymentKind`) | + nouveaux cas | enums étendus |
| Front : `MiningOperationDetailShares.spec.ts`, specs de `MiningShareTable` | une assertion sur le nombre de colonnes cassera | colonne « Minerai détenu par » |
| Front : `MiningPayments` (spec #265) | vert ; `kind` `fleet_storage_share` ajouté au type | type élargi |
| Front : fixtures `miningOperationValuationFixtures.ts` | + champs de R16 si le contrôle de types l'exige | types élargis |
| `GroupIndustryContainerServiceTest` | **non touché** | périmètre interdit |

### Questions de spec ouvertes

- Décision 2 : destinataire toujours participant ; le choix « Container corpo » de la maquette v4 disparaît. [À CONFIRMER]
- Décision 9 : vérification invisible pour un participant hors corporation. [À CONFIRMER]
- Durée du cache ESI des assets corpo, pour le texte d'aide de `assets_synced_before_end`. [À CONFIRMER]
- Fusion de `CorporationContainerContents` avec Group Industry après la refonte Industry : à inscrire dans `.claude/tasks/todo.md`.
