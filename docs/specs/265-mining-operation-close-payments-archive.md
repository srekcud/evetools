# Spec #265 — Minage groupé : clôture, paiements marqués payés et archive

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-03, à valider par Sylvain avant `/tdd-red`.
- **Circuit** : `circuit:m`, sans note de câblage séparée : le harnais de test est en annexe (section 12).
- **État du code de référence** : `fix/night-mining-ops` @ `050982c0` (#262, #263, #264 fusionnés).
- **Maquette de référence** : `mockups/grouped-mining-v4.html` (branche `docs/mining-specs`) : `computeShares()` (paiements), `payLine()`, `canSettle()`, `renderShares()` (état `settled`), `renderSoloResults()`, `stepper()`, `renderHistory()` (« Opérations passées »). Complément #265 : `mockups/mining-operation-payments-archive.html` (paiements en cours, tout payé, réglée, chacun pour soi en clôture, terminée, archive, noms longs, vue d'un simple membre).
- **Dépend de** : #262 (opération, membres, accès), #263 (instantanés, quantités, relance, correction), #264 (valorisation, parts, gel).

## 1. Contexte

#264 fige les parts (Clôture → Parts) : prix unitaires et valeur minée de chaque participant sont copiés dans l'opération, et les parts se recalculent à l'identique. Il manque la fin de l'opération :

1. **les paiements** : qui doit combien à qui, une fois les parts figées, et le marquage « payé » de chaque ligne ;
2. **la fin** : Parts → **Réglée** quand tout est payé (prorata), Clôture → **Terminée** en « Chacun pour soi », avec gel des prix et des valeurs ;
3. **l'archive** : une opération réglée ou terminée est en lecture seule et se retrouve dans un onglet « Archive » ;
4. trois reliquats de #263/#264 : la relance d'un instantané de fin, le personnage resté « en mesure » sans instantané de fin, le transfert de propriété à la suppression du compte de l'Owner.

L'issue demande aussi une notification in-app (ledger prêt, part payée) : elle part dans #271, qui couvre les notifications d'opération. La vérification par le journal du wallet est hors périmètre selon le corps de l'issue (le commentaire de la maquette la propose, voir section 8).

Constat dans le code (lu le 2026-11-03) :

| Élément | État | Usage dans #265 |
|---|---|---|
| `MiningOperationStatus` | `planned`, `in_progress`, `closing`, `shares`, `completed` ; `isOpen()`, `acceptsParticipants()` | + `settled` ; + `isArchived()` |
| `MiningOperation::freezeShares()` / `MiningShareFreezer::freeze()` | gèle prix et valeurs, statut `shares` ; constructeur `(valuator, typeNameResolver, entityManager, clock)` construit dans 4 tests | crée aussi les paiements (R6) ; nouvelle méthode `complete()` (R14) |
| `MiningShareCalculator::split()` | parts entières, reste d'arrondi, `Σ parts + booster + corporation = G` | source des montants dus (R4) |
| `MiningOperationValuator::value()` | aperçu, ou valeurs figées dès que `sharesFrozenAt` est renseigné | sert aussi l'opération terminée (R14) |
| `MiningOperationLifecycle::retryableSnapshotOf()` | début relançable en `in_progress`, fin en `closing` | inchangé ; l'accès change dans le processor (R19) |
| `RetryMiningLedgerSnapshotProcessor` | 403 si ni propriétaire du personnage ni administrateur | fin réservée à l'administration (R19) |
| `MinedQuantityCalculator::measuredOf()` | début `taken` sans fin → `measuring`, même en clôture | `unknown` / `no_end_snapshot` (R21) |
| `AccountDeletionService::delete()` | transfert de propriété des projets Group Industry ; une opération de minage disparaît avec son Owner (cascade `owner_id`) | transfert de propriété des opérations démarrées (R23) |
| `MiningOperationRepository::findForParticipant()` | toutes les opérations de l'utilisateur | sans les archivées (R17) ; nouvelle requête d'archive |
| `MiningOperationAccess` | `canRead` (participation ou corporation), `canParticipate`, `canAdminister` | lecture des résultats d'une opération archivée par la corporation (R18) |
| Topic `/user/{id}/mining-operations` | actions `member_*`, `operation_*`, `quantity_corrected`, `valuation_updated`, `shares_frozen` | trois actions de plus, pas de nouveau topic |
| Front `MiningOperationStepper.vue` | étapes `settled` (prorata) et `completed` (chacun pour soi) déjà déclarées, clés i18n `steps.settled` / `steps.completed` présentes | dernière étape marquée faite une fois atteinte |

## 2. Modèle

### Une seule migration Doctrine

| Entité / table | Changement | Contraintes |
|---|---|---|
| `MiningOperation` / `mining_operations` | + `archivedAt` datetime_immutable nullable (passage à `settled` ou `completed`) ; + `archivedByName` varchar(255) nullable (personnage principal de l'auteur) ; porte ses paiements (`OneToMany`, `cascade: persist, remove`, `orphanRemoval`) | `status` varchar(20) inchangé (`settled` tient) |
| `MiningPayment` / `mining_payments` (nouveau) | `id` UUID ; `operation` ; `position` smallint ; `kind` varchar(30) `MiningPaymentKind` ; `payerMember` → `mining_operation_members` nullable ; `payerName` varchar(255) nullable ; `beneficiaryMember` → `mining_operation_members` nullable ; `beneficiaryName` varchar(255) nullable ; `amount` float (entier d'ISK, figé) ; `paidAt` datetime_immutable nullable ; `paidBy` → `users` nullable ; `paidByName` varchar(255) nullable | `operation_id` `ON DELETE CASCADE` ; `payer_member_id`, `beneficiary_member_id`, `paid_by_id` `ON DELETE SET NULL` ; `UNIQUE(operation_id, position)` |

Aucune reprise de données : aucune ligne de paiement n'est créée pour une opération déjà en `shares` (voir cas limites et questions ouvertes).

### Enums (`src/Enum/`)

| Enum | Cas (valeur) | Méthodes |
|---|---|---|
| `MiningOperationStatus` | + `Settled` (`settled`) | `isArchived()` : vrai pour `settled` et `completed` ; `isOpen()` et `acceptsParticipants()` faux pour `settled` |
| `MiningPaymentKind` (nouveau) | `BoosterCut` (`booster_cut`, participant → booster), `CorporationCut` (`corporation_cut`, participant → corporation), `CollectedCorporationCut` (`collected_corporation_cut`, booster → corporation) | — |
| `MinedQuantityUnknownReason` | + `NoEndSnapshot` (`no_end_snapshot`) | — |
| `MiningShareBlocker` | + `NotEachForThemselves` (`not_each_for_themselves`) | — |

## 3. Règles métier

### Paiements : qui doit quoi à qui

- **R1 — Principe.** Sans fleet storage (#266), chaque participant garde le minerai qu'il a miné. Il garde sa part et verse le reste : `montant dû D_i = valeur minée V_i − part S_i`, entier, toujours ≥ 0 (parts de #264 R11). `Σ D_i = part du booster + part corporation`, exactement, puisque `Σ S_i + booster + corporation = G = Σ V_i`.
- **R2 — Bénéficiaire.** 
  - part du booster > 0 : chaque participant autre que le booster, avec `D_i > 0`, doit `D_i` au booster (`booster_cut`). Si la part corporation est > 0, le booster doit la part corporation entière à la corporation (`collected_corporation_cut`). Le booster garde son propre `D_b` : ce n'est pas une ligne ;
  - part du booster = 0 et part corporation > 0 : chaque participant avec `D_i > 0` doit `D_i` à la corporation (`corporation_cut`), booster désigné ou non ;
  - les deux à 0 : aucune ligne (tous les `D_i` valent 0).

  Contrôle, vrai par construction : le booster finit avec `V_b + Σ_{i≠b} D_i − part corporation = S_b + part du booster`.
- **R3 — Ordre et noms.** Lignes numérotées `position` 0, 1, … : les lignes vers le booster ou la corporation dans l'ordre des participants figés (position de `MiningFrozenMinedValue`), puis la ligne `collected_corporation_cut`. `payerName` et `beneficiaryName` sont copiés au gel (personnage principal). Pour la corporation : `beneficiaryMember` `null`, `beneficiaryName` = nom de corporation du personnage principal de l'Owner si son `corporationId` est celui de l'opération, sinon `null` (le front affiche « la corporation »).
- **R4 — Montant figé.** `amount` = `D_i` (ou la part corporation) calculé au gel par `MiningShareCalculator`, copié dans la ligne. Il ne change plus.
- **R5 — Calcul pur.** `MiningPaymentPlanner::plan(list<int> $minedValues, MiningShareSplit $split, ?int $boosterPosition, bool $hasCorporationCut)` rend la liste ordonnée `{position, kind, payerPosition (null = corporation), beneficiaryPosition (null = corporation), amount}`. Pas de base, pas d'entité.
- **R6 — Création au gel.** `MiningShareFreezer::freeze()` (#264 R17) crée les `MiningPayment` dans la même transaction que les prix et valeurs figés. Le planner est un paramètre ajouté en dernier au constructeur, avec la valeur par défaut `new MiningPaymentPlanner()` : les quatre constructions existantes restent valides.

### Marquer payé

- **R7 — Qui.** Le débiteur (le participant `accepted` dont l'utilisateur est le payeur de la ligne), l'Owner et les Admins `accepted` de l'opération (#262 R17) marquent une ligne payée ou la démarquent (décision 5, conforme au corps de l'issue). Tout autre utilisateur : `403`. Un participant ne marque jamais que SES propres lignes de payeur ; Owner et Admins marquent toutes les lignes.
- **R8 — Quand.** Seulement au statut `shares` : `400` « Payments can only be marked while the shares are frozen ». Avant le gel, il n'existe aucune ligne (`404` sur un identifiant) ; après `settled`, l'opération est en lecture seule.
- **R9 — Effet.** `PATCH …/payments/{id}` `{paid: true}` : `paidAt` = heure de l'horloge, `paidBy` = utilisateur, `paidByName` = son personnage principal. `{paid: false}` : les trois champs repassent à `null`. Une ligne déjà dans l'état demandé n'est pas modifiée (la trace d'origine reste), réponse `200`.
- **R10 — Réversible jusqu'au règlement.** Tant que l'opération est en `shares`, une ligne se démarque. Seul l'état courant est gardé : pas d'historique des marquages dans ce lot.

### Régler (Parts → Réglée)

- **R11 — Régler.** `POST /api/mining-operations/{id}/settle` (corps vide), administration. Refus `400`, dans cet ordre :
  1. statut autre que `shares` : « Only an operation with frozen shares can be settled » ;
  2. une ligne non payée : « Every payment must be marked paid before settling: {n} left ».

  Effet : statut `settled`, `archivedAt` = heure de l'horloge, `archivedByName`. Réponse `200`, l'opération. Une opération sans ligne de paiement (parts du booster et corporation à 0) se règle tout de suite.
- **R12 — Lecture seule.** Une opération archivée (`isArchived()`) refuse toute écriture. Les processors qui contrôlent déjà le statut gardent leur message (démarrer, terminer, partir, corriger, relancer, réglages, figer, supprimer, renouveler le lien, rejoindre, accepter). Les trois qui n'en contrôlent pas reçoivent `400` « This operation is archived » : `PATCH …/members/{id}` (rôle), `DELETE …/members/{id}`, `POST …/leave`. Les quantités, prix, parts et paiements d'une opération archivée ne changent plus.

### Chacun pour soi (Clôture → Terminée)

- **R13 — Étapes.** Une opération `each_for_themselves` passe par Planifiée → En cours → Clôture → Terminée : pas d'étape Parts, pas de paiement, pas de règlement. `POST …/freeze-shares` reste refusé (#264 CS4) et `POST …/settle` aussi (R11.1).
- **R14 — Marquer terminée.** `POST /api/mining-operations/{id}/complete` (corps vide), administration. Refus `400`, dans cet ordre :
  1. statut autre que `closing` : « Only a closing operation can be completed » ;
  2. mode `value_prorata` : « A value prorata operation is settled through its shares and payments » ;
  3. un participant `measuring` ou `unknown` : « Every mined quantity must be known before completing the operation: {noms} » ;
  4. un type de quantité > 0 sans prix : « Some ores have no price on the valuation basis: {types} ».

  Effet, une seule transaction : prix unitaires et valeurs minées figés comme au gel de #264 (`MiningFrozenUnitPrice`, `MiningFrozenMinedValue`), `pricesSyncedAt`, `sharesFrozenAt` = heure de l'horloge (heure du gel des valeurs, lue par `MiningOperationValuator::value()`), statut `completed`, `archivedAt`, `archivedByName`, aucune ligne de paiement. Réponse `200`, l'opération. Code : `MiningShareFreezer::complete()`, qui partage avec `freeze()` la copie des prix et des valeurs.
- **R15 — Blocages exposés.** `MiningOperationValuation` (#264 R20) gagne `canComplete` (administrateur et aucun blocage) et `completionBlockers` : `null` en `value_prorata` ; sinon, dans l'ordre de R14, `not_closing`, `quantities_not_known`, `prices_missing` ; `[]` une fois figée. `freezeBlockers` ne change pas (#264 CS1 garde `[each_for_themselves]`).

### Archive

- **R16 — Archive.** `GET /api/mining-operations/archive` : opérations `settled` ou `completed` que l'utilisateur peut lire : Owner, membre `accepted`, ou membre de la corporation d'une opération de visibilité `corporation` (même règle que `canRead`, #262). Les membres `pending` ou `removed` ne la voient pas. Tri par `archivedAt` décroissant. Pas de pagination dans ce lot.
- **R17 — Mes opérations.** `GET /api/mining-operations` (#262) ne liste plus les opérations archivées.
- **R18 — Lecture des résultats.** Pour une opération archivée, `GET …/valuation` et `GET …/payments` passent de l'accès participation à l'accès lecture (`canRead`) : la corporation voit le résultat d'une opération ouverte à la corporation. Pour une opération non archivée, rien ne change (#264 A14 reste vert). `GET …/ledger` et `GET …/members` restent réservés aux participants.
- **R18 bis — Résumé d'archive.** Chaque opération de R16 porte `archiveSummary` : `{archivedAt, archivedByName, totalValue, myMinedValue, myShare, paymentsCount, paidPaymentsCount}`. `totalValue` = somme des valeurs figées ; `myMinedValue` et `myShare` : ceux du participant figé dont la membership est celle de l'utilisateur, `null` s'il n'a pas participé ; `myShare` `null` en chacun pour soi. Parts recalculées par `MiningShareCalculator` sur les valeurs figées (pas de lecture des instantanés). `archiveSummary` est `null` pour une opération non archivée.

### Reliquats de #263 et #264

- **R19 — Relance d'un instantané de fin : administration seulement.** Dans une opération `closing`, `POST …/characters/{id}/snapshot-retry` n'est permis qu'à l'Owner et aux Admins : sinon `403` « Only the operation owner or an admin can retry an end snapshot », contrôlé avant la règle de relançabilité. Raison : un participant qui a continué à miner après la fin gonflerait sa quantité en relançant lui-même sa fin (#263 R15 compte le minage jusqu'à la relance). Dans une opération `in_progress`, la relance du début par le propriétaire du personnage reste permise (#263 R15 inchangé). `canRetry` du ledger (#263 R24) applique la même règle : en `closing`, vrai seulement pour un administrateur.
- **R20 — Ce qui ne change pas.** La relance d'une fin par un administrateur, la règle de relançabilité (`MiningOperationLifecycle`), et la relance du début par le propriétaire du personnage ou un administrateur.
- **R21 — Personnage sans instantané de fin.** Un personnage dont l'instantané de début est `taken`, sans instantané de fin, alors que sa fin est passée (opération ni `planned` ni `in_progress`, ou participant parti), est **inconnu**, raison `no_end_snapshot`, au lieu de `measuring`. Cas : début pris tard (acceptation juste avant la fin, message lu après `end`) ou début relancé et lu après le départ. Il se corrige comme tout personnage inconnu (#263 R19-R21), ce qui débloque le gel ou la fin. Un début pas encore réglé (`waiting`) reste `measuring` : le handler peut encore le lire, puis la règle s'applique. Aucun instantané de fin n'est créé après coup : un début lu après la fin ne mesure rien de fiable.
- **R22 — Acceptation en clôture.** Toujours refusée (#263 R5), y compris en `shares`, `settled` et `completed` (`acceptsParticipants()` faux).
- **R23 — Transfert de propriété.** À la suppression du compte de l'Owner, `AccountDeletionService` traite ses opérations avant de supprimer l'utilisateur :
  - opération `planned` : supprimée, comme aujourd'hui ;
  - autre statut, avec un successeur : propriété transférée. Successeur = membre `accepted` d'un autre utilisateur, Admin d'abord, puis plus ancien `joinedAt`, puis identifiant (motif `GroupIndustryProjectMemberRepository::findOwnershipSuccessor()`). Il prend le rôle `owner` et devient `owner` de l'opération. La membership de l'ancien Owner disparaît (cascade #262), ses valeurs figées et ses paiements gardent leur nom (`SET NULL` + nom copié) ;
  - sans successeur : supprimée.

  Raison : une opération en `shares` porte des dettes entre joueurs, et une opération archivée est l'historique des autres participants. Code : `MiningOperation::transferOwnershipTo(MiningOperationMember)`, `MiningOperationMemberRepository::findOwnershipSuccessor()`.

### Ressources renvoyées

- **R24 — `MiningPayment`** : `{id, position, kind, payerMemberId, payerName, beneficiaryMemberId, beneficiaryName, amount, paid, paidAt, paidByName}`. `amount` entier. `paid` = `paidAt !== null`.
- **R25 — `MiningOperation`** (#262 R22) gagne `archivedAt`, `archivedByName` et `archiveSummary` (R18 bis). `status` peut valoir `settled`.
- **R26 — `MiningOperationValuation`** gagne `canComplete`, `completionBlockers` (R15). Une opération terminée est servie comme une opération figée (#264 R19), avec `frozen` vrai.

### Temps réel

- **R27 — Actions publiées.** `publishMiningOperationEvent()` publie aussi `payment_updated` (`{paymentId, paid}`, après R9), `operation_settled` (`{}`, après R11) et `operation_completed` (`{}`, après R14), vers `MiningOperationAudience::userIdsOf()`. Pas de nouveau topic : `getTopicsForUser()` ne change pas. Le transfert de propriété (R23) ne publie rien : `AccountDeletionService` n'a pas de publieur et la prochaine lecture montre le nouveau rôle.
- **R28 — Front.** La vue détail recharge les paiements sur `payment_updated` et `shares_frozen` de l'opération affichée ; l'opération, la valorisation et les paiements sur `operation_settled` et `operation_completed`. La liste recharge « Mes opérations » et « Archive » sur `operation_settled` et `operation_completed`.

## 4. Endpoints API Platform

Toutes les opérations : `security: "is_granted('ROLE_USER')"`, `paginationEnabled: false`, préfixe `/api`, exigence UUID sur `{id}` / `{operationId}` comme en #262. Ordre des refus : `401`, `404` (`UuidOrNotFound`), `403`, `422`, puis règles métier `400`.

| Ressource | Opération | `uriTemplate` | `uriVariables` | Entrée | Provider / Processor | Réponse |
|---|---|---|---|---|---|---|
| `MiningPaymentResource` | `GetCollection` | `/mining-operations/{operationId}/payments` | `operationId` → `Link(fromClass: MiningOperationResource::class)` | — | `MiningPaymentCollectionProvider` | 200, liste par `position` ; `[]` avant le gel et en chacun pour soi (R18 pour l'accès) |
| | `Patch` | `/mining-operations/{operationId}/payments/{id}` | `operationId` → `Link(fromClass: MiningOperationResource::class)`, `id` → `Link(fromClass: self::class)` | `UpdateMiningPaymentInput` (`?bool $paid`, `Assert\NotNull`), `inputFormats: ['json' => ['application/merge-patch+json']]` | `MiningPaymentItemProvider` / `UpdateMiningPaymentProcessor` | 200 (R7-R10) ; `404` « Payment not found » (autre opération, inconnu, non UUID) |
| `MiningOperationResource` | `Post` | `/mining-operations/{id}/settle` | `id` → `Link(fromClass: self::class)` | `EmptyInput` | `SettleMiningOperationProcessor`, `status: 200` | 200 (R11) |
| | `Post` | `/mining-operations/{id}/complete` | `id` → `Link(fromClass: self::class)` | `EmptyInput` | `CompleteMiningOperationProcessor`, `status: 200` | 200 (R14) |
| | `GetCollection` | `/mining-operations/archive` | — | — | `ArchivedMiningOperationsProvider` | 200 (R16, R18 bis) |

`MiningOperationSubResourceUriVariablesTest` reçoit les deux lignes de `MiningPaymentResource` et les deux `Post` (`settle`, `complete`).

Répartition du code :
- entités : `MiningOperation::settle()`, `complete()`, `transferOwnershipTo()`, `getPayments()` ; `MiningPayment::markPaid(User, string, \DateTimeImmutable)`, `unmarkPaid()` ;
- `src/Service/MiningOperation/` : `MiningPaymentPlanner` (R1-R5, pur), `MiningShareFreezer` (`freeze()` crée les paiements R6, `complete()` R14), `MiningOperationSettlement` (R8-R11 : contrôles et écriture du marquage et du règlement), `MiningOperationArchive` (R18 bis, résumé à partir des valeurs figées), `MiningOperationAccess::assertCanReadResults()` (R18) ;
- `MinedQuantityCalculator::measuredOf()` (R21), `RetryMiningLedgerSnapshotProcessor` et `MiningOperationLedgerProvider` (R19), `AccountDeletionService` (R23), `MiningOperationRepository::findForParticipant()` (R17) et `findArchivedReadableBy(User)` (R16), `MiningOperationMemberRepository::findOwnershipSuccessor()` (R23), garde « archivée » dans `UpdateMiningOperationMemberProcessor`, `RemoveMiningOperationMemberProcessor`, `LeaveMiningOperationProcessor` (R12).

Les processors restent minces. Le mapping passe par `MiningOperationResourceMapper`.

## 5. Écrans (front)

Direction : HUD EVE de la v4, tokens de `mockups/mining-operation-shares.html`, maquette complémentaire `mockups/mining-operation-payments-archive.html`. Montants au format `fr-FR` / `en-US` en ISK entiers, heures EVE `HH:MM`. Une valeur `null` s'affiche « inconnue » en ambre (`UnknownValue`), jamais « 0 ». Noms tronqués avec infobulle.

| Où | Contenu |
|---|---|
| Détail, en-tête | `MiningOperationStepper.vue` : la dernière étape atteinte (`settled`, `completed`) est marquée faite et courante ; `MiningStatusBadge.vue` : `settled` « Réglée » et `completed` « Terminée » en émeraude |
| Détail, `shares` | quatrième KPI « Paiements {payés} / {total} » (ambre tant qu'il en reste, émeraude sinon) ; carte « Paiements » (`MiningPayments.vue`, colonne principale sous la répartition) : texte de règle, une ligne par paiement « {payeur} doit {montant} ISK à {bénéficiaire} », motif selon `kind`, trace « marqué payé par {nom} le {date} à {HH:MM} » ou « en attente du don en jeu », badge « payé » / « en attente », case à cocher pour l'administration (désactivée pour un membre) ; colonne droite : carte « Marquer réglée » (administration) : aide, bouton désactivé tant qu'il reste une ligne, motif « {n} paiements pas encore marqués payés », confirmation « Marquer l'opération réglée ? Plus rien ne pourra changer. » |
| Détail, `settled` | bandeau émeraude « Opération réglée le {date HH:MM} EVE. » ; KPI et répartition figée de #264 ; carte « Paiements » en lecture seule ; carte « Récapitulatif » (période, système, participants et personnages, valeur totale, ISK/h, base, booster, corporation, paiements, réglée le … par …) |
| Détail, `closing`, chacun pour soi | carte « Marquer terminée » (`MiningCompleteOperation.vue`, administration) : aide, bouton, motifs de `completionBlockers` en liste ambre, confirmation « Marquer l'opération terminée ? Les prix du moment sont figés. » |
| Détail, `completed` | bandeau émeraude « Opération terminée le {date} EVE · chacun pour soi » ; KPI : valeur totale, ISK/h, durée, participants ; tableau des valeurs minées figées ; carte « Récapitulatif » sans booster, corporation ni paiements |
| Détail, ledger `closing` | raison `no_end_snapshot` : « ✗ pas d'instantané de fin · quantité inconnue » ; bouton « Relancer » d'une fin visible seulement pour l'administration (`canRetry` serveur) |
| Liste | troisième onglet « Archive » (`useTabQuery`, valeur `archive`) : tableau Opération, Archivée le, Système, Participants, Valeur G, Ma part (ou « ma valeur · chacun pour soi », « non participant »), Paiements (« {n} / {m} payés » ou « rien à régler »), Statut ; clic → détail ; vide : « Aucune opération réglée ou terminée pour l'instant. » |

Store `stores/miningOperation.ts` : `fetchPayments(id)`, `markPayment(operationId, paymentId, paid)` (`application/merge-patch+json`), `settle(id)`, `complete(id)`, `fetchArchive()`. Le type `MiningOperationStatus` gagne `'settled'`, `MiningOperation` gagne `archivedAt`, `archivedByName`, `archiveSummary`.

### Clés i18n (fr / en)

| Clé | fr | en |
|---|---|---|
| `miningOperation.status.settled` | Réglée | Settled |
| `miningOperation.tabs.archive` | Archive | Archive |
| `miningOperation.archive.columns.operation` / `.archivedAt` / `.system` / `.participants` / `.totalValue` / `.myShare` / `.payments` / `.status` | Opération / Archivée le / Système / Participants / Valeur G / Ma part / Paiements / Statut | Operation / Archived on / Solar system / Participants / Total value G / My share / Payments / Status |
| `miningOperation.archive.myValue` / `.notParticipant` / `.nothingToSettle` / `.paymentsCount` | ma valeur · chacun pour soi / non participant / rien à régler / {paid} / {total} payés | my value · everyone keeps their own / not a participant / nothing to settle / {paid} / {total} paid |
| `miningOperation.archive.empty` / `.hint` | Aucune opération réglée ou terminée pour l'instant. / L'archive montre les opérations réglées ou terminées auxquelles vous avez participé, et celles de votre corporation ouvertes à la corporation. | No settled or completed operation yet. / The archive lists the settled or completed operations you took part in, and your corporation's operations open to the corporation. |
| `miningOperation.payments.title` / `.count` / `.rule` | Paiements / {paid} / {total} payés / Chacun garde son minerai et verse au booster la différence entre sa valeur minée et sa part. Le booster reverse la part corporation. Un Owner ou un Admin coche la ligne quand le don est fait en jeu. | Payments / {paid} / {total} paid / Everyone keeps their ore and gives the booster the difference between their mined value and their share. The booster passes the corporation cut on. An owner or admin ticks the line once the ISK is given in game. |
| `miningOperation.payments.ruleCorporationOnly` | Chacun garde son minerai et verse à la corporation la différence entre sa valeur minée et sa part. | Everyone keeps their ore and gives the corporation the difference between their mined value and their share. |
| `miningOperation.payments.line` | {payer} doit {amount} ISK à {beneficiary} | {payer} owes {amount} ISK to {beneficiary} |
| `miningOperation.payments.kind.booster_cut` / `.corporation_cut` / `.collected_corporation_cut` | part du booster et part corporation sur sa valeur minée / part corporation sur sa valeur minée / part corporation collectée par le booster ({percent} % de G) | booster and corporation cuts on their mined value / corporation cut on their mined value / corporation cut collected by the booster ({percent} % of G) |
| `miningOperation.payments.corporation` | la corporation | the corporation |
| `miningOperation.payments.paid` / `.pending` / `.paidTrace` / `.pendingTrace` / `.markLabel` | payé / en attente / marqué payé par {name} le {date} à {time} / en attente du don en jeu / {payer} a payé {amount} ISK à {beneficiary} | paid / pending / marked paid by {name} on {date} at {time} / waiting for the ISK to be given in game / {payer} paid {amount} ISK to {beneficiary} |
| `miningOperation.shares.kpi.payments` | Paiements | Payments |
| `miningOperation.settle.title` / `.action` / `.hint` / `.confirm` / `.left` | Marquer réglée / Marquer réglée / Possible quand tous les paiements sont marqués payés. L'opération passe ensuite en archive, en lecture seule. / Marquer l'opération réglée ? Plus rien ne pourra changer. / {count} paiement(s) pas encore marqué(s) payé(s) | Mark settled / Mark settled / Available once every payment is marked paid. The operation then moves to the archive, read only. / Mark the operation settled? Nothing can change afterwards. / {count} payment(s) not marked paid yet |
| `miningOperation.settle.banner` | Opération réglée le {date} EVE. Tous les paiements sont marqués payés. Quantités, prix, parts et paiements ne changent plus. | Operation settled on {date} EVE. Every payment is marked paid. Quantities, prices, shares and payments no longer change. |
| `miningOperation.complete.title` / `.action` / `.hint` / `.confirm` | Marquer terminée / Marquer terminée / Les prix du moment et la valeur minée de chacun sont copiés dans l'opération, qui passe en archive, en lecture seule. Rien n'est à régler. / Marquer l'opération terminée ? Les prix du moment sont figés. | Mark completed / Mark completed / Current prices and each participant's mined value are copied into the operation, which moves to the archive, read only. Nothing to settle. / Mark the operation completed? Current prices are frozen. |
| `miningOperation.complete.blockers.not_closing` / `.quantities_not_known` / `.prices_missing` | L'opération doit être en clôture. / Quantité inconnue ou en mesure : {names} / Sans prix sur la base : {types} | The operation must be closing. / Unknown or still measured quantity: {names} / No price on the basis: {types} |
| `miningOperation.complete.banner` | Opération terminée le {date} EVE · chacun pour soi. Chacun garde son minerai : rien à régler. Les prix et les valeurs minées sont figés. | Operation completed on {date} EVE · everyone keeps their own. Nothing to settle. Prices and mined values are frozen. |
| `miningOperation.recap.title` / `.period` / `.system` / `.participants` / `.totalValue` / `.iskPerHour` / `.basis` / `.booster` / `.corporation` / `.payments` / `.settledBy` / `.completedBy` | Récapitulatif / Période / Système / Participants / Valeur totale / ISK/h / Base / Booster / Corporation / Paiements / {date} par {name} / {date} par {name} | Summary / Period / Solar system / Participants / Total value / ISK/h / Basis / Booster / Corporation / Payments / {date} by {name} / {date} by {name} |
| `miningOperation.recap.participantsCount` | {participants} · {characters} personnages | {participants} · {characters} characters |
| `miningOperation.snapshot.noEndSnapshot` | ✗ pas d'instantané de fin · quantité inconnue | ✗ no end snapshot · quantity unknown |

## 6. Exemples chiffrés (tests rouges)

Données communes : exemple E1 de #264 (Aria Solberg Owner, Brann Holt parti à 21:05, Cyra Lune booster, base compressé / Jita vente), valeurs figées V1 `[2 699 093, 2 868 460, 176]`, G 5 567 729. Corporation C1 « Corpo Exemple ». Horloge `MockClock`. Montants en ISK.

### Planification des paiements (`MiningPaymentPlanner`, pur)

| # | Valeurs, booster / corporation, position du booster | Parts (#264 R11) | Lignes attendues (`kind`, payeur → bénéficiaire, montant) |
|---|---|---|---|
| PA1 (S1) | V1, 10 / 0, Cyra (2) | [2 429 184, 2 581 614, 158], booster 556 773 | 0 `booster_cut` Aria → Cyra 269 909 ; 1 `booster_cut` Brann → Cyra 286 846. Somme 556 755 ; Cyra : 176 + 556 755 = 556 931 = 158 + 556 773 |
| PA2 (S2) | V1, 10 / 5, Cyra (2) | [2 294 229, 2 438 191, 150], booster 556 773, corporation 278 386 | 0 Aria → Cyra 404 864 ; 1 Brann → Cyra 430 269 ; 2 `collected_corporation_cut` Cyra → corporation 278 386. Cyra : 176 + 835 133 − 278 386 = 556 923 = 150 + 556 773 |
| PA3 (corporation seule) | V1, 0 / 5, aucun booster | [2 564 138, 2 725 037, 167], corporation 278 387 (reste d'arrondi 1 à la corporation) | 0 `corporation_cut` Aria → corporation 134 955 ; 1 Brann → corporation 143 423 ; 2 Cyra → corporation 9. Somme 278 387 |
| PA4 (sans part) | V1, 0 / 0 | parts = valeurs | aucune ligne |
| PA5 (booster Brann, tout aux parts) | [1 000, 2 000], 60 / 40, Brann (1) | [0, 0], booster 1 800, corporation 1 200 | 0 Aria → Brann 1 000 ; 1 `collected_corporation_cut` Brann → corporation 1 200. Brann : 2 000 + 1 000 − 1 200 = 1 800 |
| PA6 (participant sans minerai) | [2 699 093, 2 868 460, 0], 10 / 0, Cyra (2) | [2 429 184, 2 581 614, 0], booster 556 755 | Aria → Cyra 269 909, Brann → Cyra 286 846 ; aucune ligne pour Cyra |
| PA7 (booster sans part, corporation) | V1, 0 / 5, booster Cyra désigné | comme PA3 | comme PA3 (le booster ne collecte que s'il a une part) |
| PA8 (contrôle) | PA1 à PA7 | — | `Σ montants des lignes vers le booster et la corporation` = `booster + corporation − D_booster` quand un booster collecte ; = part corporation sinon ; aucun montant < 0 |

### Gel avec paiements (`MiningShareFreezer::freeze()`, #264 F1 étendu)

| # | Entrée | Sortie attendue |
|---|---|---|
| G1 | Aria, E1, réglages 10 / 5, booster Cyra, horloge 21:52 | `200`, `shares` ; 3 `MiningPayment` de PA2, `payerName` « Aria Solberg », « Brann Holt », « Cyra Lune », `beneficiaryName` « Cyra Lune », « Cyra Lune », « Corpo Exemple » ; `paidAt` `null` partout |
| G2 | E1, 10 / 0 | 2 lignes de PA1 |
| G3 | E1, 0 / 0 | aucune ligne ; tests #264 F1-F12 verts |
| G4 | Owner dont le personnage principal est dans une autre corporation que l'opération, 0 / 5 | `beneficiaryName` `null` pour les lignes vers la corporation |

### Marquer payé (`PATCH …/payments/{id}`)

| # | Entrée | Sortie attendue |
|---|---|---|
| M1 | Aria, G1, ligne 0, `{paid: true}`, horloge 22:24 | `200`, `paid` vrai, `paidAt` 22:24, `paidByName` « Aria Solberg » ; événement `payment_updated` `{paymentId, paid: true}` vers Aria, Brann, Cyra |
| M2 | M1 puis Aria, ligne 0, `{paid: true}` à 22:40 | `200`, `paidAt` toujours 22:24 |
| M3 | M1 puis Aria, `{paid: false}` | `200`, `paid` faux, `paidAt`, `paidByName` `null` |
| M4 | Brann `member`, ligne 1 (sa propre dette), `{paid: true}` | `200`, `paid` vrai, `paidByName` « Brann Holt » (R7 : le débiteur marque sa ligne) |
| M4b | Brann `member`, ligne 0 (dette d'Aria), `{paid: true}` | `403` « Only the payer, the operation owner or an admin can mark this payment » ; ligne inchangée |
| M5 | Cyra promue Admin, ligne 1 | `200`, `paidByName` « Cyra Lune » |
| M6 | Aria, `{paid: null}` ; `{}` ; `{paid: "yes"}` | `422`, violation sur `paid` |
| M7 | Aria, identifiant d'une ligne d'une autre opération ; inconnu ; non UUID | `404` « Payment not found » |
| M8 | Aria, O `settled` | `400` « Payments can only be marked while the shares are frozen » |
| M9 | Dorn sans accès | `403` « You do not have access to this operation » |
| M10 | hub Mercure en panne | `200`, `warning` journalisé |

### Régler (`POST …/settle`)

| # | Entrée | Sortie attendue |
|---|---|---|
| RG1 | Aria, G1, lignes 0 et 1 payées, ligne 2 non | `400` « Every payment must be marked paid before settling: 1 left » ; O reste `shares` |
| RG2 | Aria, G1, 3 lignes payées, horloge 23:35 | `200`, `settled`, `archivedAt` 23:35, `archivedByName` « Aria Solberg » ; événement `operation_settled` |
| RG3 | Aria, G3 (aucune ligne) | `200`, `settled` |
| RG4 | Aria, O `closing` ; O `settled` | `400` « Only an operation with frozen shares can be settled » |
| RG5 | Brann `member` | `403` « Only the operation owner or an admin can perform this action » |
| RG6 | Aria, O chacun pour soi `closing` | `400` « Only an operation with frozen shares can be settled » |

### Lecture seule (R12)

| # | Entrée | Sortie attendue |
|---|---|---|
| RO1 | Aria, O `settled`, `PATCH …/members/{Brann}` `{role: "admin"}` | `400` « This operation is archived » ; rôle inchangé |
| RO2 | Aria, O `settled`, `DELETE …/members/{membre pending}` | `400` « This operation is archived » |
| RO3 | membre `pending`, O `completed`, `POST …/leave` | `400` « This operation is archived » |
| RO4 | Aria, O `settled` : `PATCH …/valuation`, `POST …/freeze-shares`, `POST …/characters/{id}/quantity-corrections`, `POST …/characters/{id}/snapshot-retry`, `POST …/join-code`, `DELETE /mining-operations/{id}` | messages existants : « The shares of this operation are frozen », « Only a closing operation can have its shares frozen », « Quantities can only be corrected while the operation is closing », « This snapshot cannot be retried », « This operation is no longer accepting participants », « Only a planned operation can be deleted » |

### Chacun pour soi (`POST …/complete`)

| # | Entrée | Sortie attendue |
|---|---|---|
| T1 | Aria, E1 en `each_for_themselves`, `closing`, horloge 21:58, sync Jita 21:00 | `200`, `completed`, `archivedAt` 21:58 ; 2 prix figés (Veldspar 19.61, Bitumens 221.45), 3 valeurs figées (2 699 093, 2 868 460, 176) ; `sharesFrozenAt` 21:58, `pricesSyncedAt` 21:00 ; aucune ligne de paiement ; événement `operation_completed` |
| T2 | T1 puis `GET …/valuation` après une sync Jita qui passe Compressed Veldspar à 25,00 | `frozen` vrai ; valeurs, G 5 567 729 et `unitPrices` inchangés ; `share`, `boosterCut` `null` ; `canComplete` faux, `completionBlockers` `[]` |
| T3 | Aria, chacun pour soi, `Brann II` début `reconnect_required` sans correction | `400` « Every mined quantity must be known before completing the operation: Brann Holt » ; rien n'est figé |
| T4 | Aria, chacun pour soi, prix P4 (#264) | `400` « Some ores have no price on the valuation basis: Bitumens » |
| T5 | Aria, chacun pour soi, `in_progress` | `400` « Only a closing operation can be completed » |
| T6 | Aria, `value_prorata`, `closing` | `400` « A value prorata operation is settled through its shares and payments » |
| T7 | Brann `member` | `403` « Only the operation owner or an admin can perform this action » |
| T8 | `GET …/valuation`, chacun pour soi `closing`, T3 | `canComplete` faux, `completionBlockers` [`quantities_not_known`], `freezeBlockers` [`each_for_themselves`] (#264 CS1 inchangé) |
| T9 | `GET …/valuation`, `value_prorata` | `completionBlockers` `null`, `canComplete` faux |
| T10 | T1 puis `GET …/payments` | `200`, `[]` |

### Archive

| # | Entrée | Sortie attendue |
|---|---|---|
| AR1 | Aria : O `settled` (RG2), P2 `completed` (chacun pour soi, archivée la veille), P3 `shares` | `GET /mining-operations/archive` : [O, P2] (`archivedAt` décroissant) ; `GET /mining-operations` : P3 seule |
| AR2 | AR1, `archiveSummary` d'O vu par Aria | `{totalValue: 5567729, myMinedValue: 2699093, myShare: 2294229, paymentsCount: 3, paidPaymentsCount: 3, archivedByName: "Aria Solberg"}` |
| AR3 | AR1, `archiveSummary` de P2 vu par Aria (valeur 812 300) | `myShare` `null`, `myMinedValue` 812 300, `paymentsCount` 0 |
| AR4 | Dorn, même corporation C1, O de visibilité `corporation`, non participant | O listée, `myMinedValue` et `myShare` `null` ; `GET …/valuation` et `GET …/payments` `200` ; `GET …/ledger` `403` |
| AR5 | Dorn, O de visibilité `private` | O absente ; `GET …/valuation` `403` « You do not have access to this operation » |
| AR6 | Cyra `pending` d'une opération archivée de visibilité `private` | absente de l'archive et de « Mes opérations » |
| AR7 | Dorn, même corporation, O `shares` de visibilité `corporation` | `GET …/valuation` `403` (#264 A14 : participation avant l'archive) |

### Relance d'une fin (R19)

| # | Entrée | Sortie attendue |
|---|---|---|
| RT1 | Brann `member`, O `closing`, fin de `Brann II` `failed` | `403` « Only the operation owner or an admin can retry an end snapshot » ; fin toujours `failed`, aucun message |
| RT2 | Aria, même cas | `204`, fin `waiting`, `readyAt` = maintenant (#263 R-4 inchangé) |
| RT3 | Brann, O `closing`, fin de `Brann II` `taken` | `403` (accès avant relançabilité) |
| RT4 | Brann, O `in_progress`, début de `Brann II` `reconnect_required` | `204` (#263 R-1 inchangé) |
| RT5 | ledger d'O `closing` vu par Brann, fin de `Brann II` `failed` | `canRetry` faux ; vu par Aria : vrai |

### Personnage sans instantané de fin (R21)

| # | Situation | Attendu |
|---|---|---|
| NE1 | Cyra acceptée à 21:29, début `waiting` ; fin d'O à 21:30 (pas de fin pour Cyra) ; début lu `taken` à 21:30:05 | `Cyra Lune` `unknown`, `no_end_snapshot` ; participant Cyra `unknown` ; `freezeBlockers` [`quantities_not_known`] |
| NE2 | NE1 avant la lecture du début (`waiting`) | `measuring` |
| NE3 | NE1 puis correction de `Cyra Lune` Veldspar = 0 par Aria | `known`, Veldspar 0 ; gel permis |
| NE4 | Brann parti à 21:05 alors que le début de `Brann II` était `waiting`, lu `taken` à 21:06, O toujours `in_progress` | `Brann II` `unknown`, `no_end_snapshot` |
| NE5 | O `in_progress`, début `taken`, personnage non parti | `measuring` (#263 U3 inchangé) |
| NE6 | O `closing`, personnage sans aucun instantané de début | `measuring` (#263 `testCharacterWithoutStartSnapshotOrWithAWaitingOneIsMeasuring` inchangé) |

### Transfert de propriété (R23)

| # | Entrée | Sortie attendue |
|---|---|---|
| TR1 | O `shares` (G1), ligne 0 payée par Aria ; Aria supprime son compte | O gardée, `owner` = Brann (plus ancien `joinedAt`, pas d'Admin), sa membership `owner` ; valeur figée position 0 `member` `null`, `participantName` « Aria Solberg » ; ligne 0 `payerMember` `null`, `payerName` « Aria Solberg », `paidBy` `null`, `paidByName` « Aria Solberg » ; parts de S2 inchangées |
| TR2 | TR1 avec Cyra `admin` | `owner` = Cyra |
| TR3 | O `settled`, Aria supprime son compte | O gardée, dans l'archive de Brann et de Cyra |
| TR4 | O `planned` avec Brann `accepted` | O supprimée (test #262 `testDeletedOwnerTakesHisOperationsAndMembershipsWithHimWhileTheOtherAccountKeepsItsOwn` vert) |
| TR5 | O `closing` sans autre membre `accepted` (Cyra `pending`) | O supprimée |
| TR6 | TR1, `testNoForeignKeyColumnReferencesTheDeletedAccountAndTheOtherAccountKeepsAllItsRows` | vert : `paid_by_id` remis à `null` |

### Statuts

| # | Entrée | Sortie attendue |
|---|---|---|
| D1 | `isOpen()` | `planned`, `in_progress`, `closing` vrai ; `shares`, `settled`, `completed` faux |
| D2 | `acceptsParticipants()` | `planned`, `in_progress` vrai ; les autres faux |
| D3 | `isArchived()` | `settled`, `completed` vrai ; les autres faux |
| D4 | `POST …/snapshot-retry`, O `settled` | `400` « This snapshot cannot be retried » (`match` de `MiningOperationLifecycle` : `Settled => null`) |

### Temps réel

| # | Entrée | Sortie attendue |
|---|---|---|
| MT1 | `getTopicsForUser('user-uuid-123')` | 17 topics, inchangé |
| MT2 | front : détail d'O, `payment_updated` pour O puis pour P2 | `fetchPayments(O)` appelé une fois |
| MT3 | front : `operation_settled` pour O | `fetchDetail(O)`, `fetchValuation(O)`, `fetchPayments(O)` |

### Front

| # | Entrée | Sortie attendue |
|---|---|---|
| FR1 | détail `shares`, G1, M1 | carte « Paiements » : « Aria Solberg doit 404 864 ISK à Cyra Lune », « payé », « marqué payé par Aria Solberg le 05/11 à 22:24 » ; KPI « Paiements 1 / 3 » en ambre ; bouton « Marquer réglée » désactivé, « 2 paiements pas encore marqués payés » |
| FR2 | clic sur la case de la ligne 1 par Aria | `markPayment(O, ligne 1, true)` en `application/merge-patch+json` |
| FR3 | Brann `member` | cases actives seulement sur ses propres lignes de payeur, désactivées sur les autres ; pas de carte « Marquer réglée » |
| FR4 | 3 lignes payées, clic « Marquer réglée », confirmation | `settle(O)` |
| FR5 | détail `settled` | bandeau « Opération réglée le 05/11 23:35 EVE », cases désactivées, carte « Récapitulatif », stepper : 5 étapes faites, « Réglée » `aria-current="step"` |
| FR6 | chacun pour soi `closing`, T8 | carte « Marquer terminée », bouton désactivé, « Quantité inconnue ou en mesure : Brann Holt » ; valeur de Brann « inconnue », jamais « 0 » |
| FR7 | détail `completed` | bandeau « Opération terminée … chacun pour soi », pas de carte « Paiements », 4 étapes faites |
| FR8 | liste, onglet « Archive », AR1 | 2 lignes ; O : Valeur G 5 567 729, Ma part 2 294 229, « 3 / 3 payés », badge « Réglée » ; P2 : « ma valeur · chacun pour soi », « rien à régler », badge « Terminée » |
| FR9 | archive vide | « Aucune opération réglée ou terminée pour l'instant. » |
| FR10 | ledger `closing`, `Cyra Lune` `no_end_snapshot` | « ✗ pas d'instantané de fin · quantité inconnue » |
| FR11 | textes des nouveaux composants en fr puis en en | uniquement les clés i18n de la locale (`expectOnlyLocaleTexts`) |
| FR12 | noms de 60 caractères (payeur, bénéficiaire, opération) | tronqués avec infobulle, montants intacts |

## 7. Cas limites

- **Le booster doit recevoir avant de reverser.** En PA2, Cyra reverse 278 386 à la corporation alors qu'elle n'a miné que 176 : elle attend les dons d'Aria et Brann. L'ordre des lignes (vers le booster d'abord) le montre ; l'application n'impose pas d'ordre de marquage.
- **Bénéficiaire ou payeur dont le compte est supprimé.** Ligne gardée avec ses noms copiés ; un administrateur la marque encore. Un participant hors evetools n'existe pas : tout participant a un compte.
- **Owner qui supprime son compte en `shares`.** Transfert (R23) ; le nouvel Owner marque les paiements et règle.
- **Opération figée avant le déploiement de #265.** Elle n'a aucune ligne de paiement et se réglerait tout de suite (RG3). [À CONFIRMER : aucune opération en `shares` en production au déploiement, #264 n'étant pas encore déployé ; sinon, la défiger à la main n'existe pas, voir questions ouvertes.]
- **Démarquer après le règlement.** Impossible (lecture seule) : le règlement demande une confirmation.
- **Montant à 1 ISK près.** Les montants sont entiers et exacts (R1) ; un don en jeu différent d'un ISK se règle entre joueurs, la case reste un constat manuel.
- **Message de début lu après la fin** (R21). Le personnage devient inconnu, l'Admin corrige, souvent à 0 : le minage de ce personnage est d'au plus quelques minutes.
- **Début perdu dans le transport** (`waiting` pour toujours en clôture). Reste `measuring` : la relance du début n'est ouverte qu'en `in_progress` (#263 R15). Voir questions ouvertes.
- **Corporation sans nom connu.** Libellé « la corporation » ; le nom n'est qu'un affichage.
- **Opération de visibilité `corporation` dont l'Owner change de corporation.** `corporationId` de l'opération ne change pas (#262 R3) : l'archive reste visible de C1.
- **Archive longue.** Pas de pagination ni de filtre de période dans ce lot : à reprendre si la liste grandit.

## 8. Hors périmètre

- Vérification d'un paiement par le journal du wallet ESI et référence d'opération `OP-xxxx` en motif de don (corps de l'issue ; proposée en commentaire de l'issue, à ouvrir en issue séparée).
- Notifications in-app ou push « ledger de fin prêt » et « part marquée payée » (#271).
- Fleet storage, minerai détenu par un autre et dettes qui en découlent (#266).
- Récapitulatif à partager (texte Discord, image) (#316) : seul le récapitulatif affiché dans le détail est fait ici.
- Compagnon, sites multiples, scans partagés, présence, valeur d'un chunk, vaisseau de boost, hauler, roches, aide à la vente, Stockpile, saison (#304-#317).
- Défiger les parts ; prix saisi à la main (#264 décisions 6 et 9, maintenues).
- Historique des marquages (qui a démarqué, quand) ; marquage par le payeur ou le bénéficiaire (décision 5).
- Export CSV, filtre de période et pagination de l'archive.
- Désarchiver une opération.

## 9. Termes du glossaire

Utilisés tels quels (section Minage) : **Opération de minage**, **Participant**, **Part**, **Part du booster**, **Base de valorisation**, **Quantité minée d'une opération**, **Fleet storage** (hors lot).

Termes proposés, à ajouter au glossaire après validation (non ajoutés ici) :

| Terme FR | Term EN | Définition proposée | Symbole(s) |
|---|---|---|---|
| **Paiement** | Payment | Montant entier qu'un participant doit au booster ou à la corporation après le gel des parts : sa valeur minée moins sa part, ou la part corporation que reverse le booster. Figé au gel. | `MiningPayment`, `MiningPaymentKind`, `MiningPaymentPlanner` |
| **Montant dû** | Amount due | Montant figé d'un paiement. | `MiningPayment::$amount` |
| **Marqué payé** | Marked paid | Paiement déclaré fait par un Owner ou un Admin, avec son auteur et son heure. Réversible tant que l'opération n'est pas réglée. | `MiningPayment::markPaid()`, `$paidAt` |
| **Opération réglée** | Settled operation | Opération au prorata dont tous les paiements sont marqués payés et que l'administration a marquée réglée. Lecture seule. | `MiningOperationStatus::Settled` |
| **Opération terminée** | Completed operation | Opération « Chacun pour soi » dont les prix et les valeurs minées sont figés en fin de clôture. Lecture seule, rien à régler. | `MiningOperationStatus::Completed` |
| **Archive des opérations** | Operation archive | Opérations réglées ou terminées, visibles de leurs participants et, pour une opération ouverte à la corporation, des membres de la corporation. | `MiningOperationStatus::isArchived()`, `GET /mining-operations/archive` |

Les libellés « Booster », « Part corporation », « Valeur minée » proposés par #264 restent en attente de validation.

## 10. Décisions prises en autonomie

| # | Décision | Alternative écartée | Raison |
|---|---|---|---|
| 1 | Relance d'un instantané de fin réservée à l'Owner et aux Admins ; la relance du début par le propriétaire du personnage reste permise (décision de l'orchestrateur) | relance par le propriétaire du personnage (#263 R15) | Un participant qui a continué à miner après la fin gonflerait sa quantité en relançant sa propre fin. Le début relancé, lui, fait perdre du minage au pilote, pas aux autres. |
| 2 | Transfert de propriété traité ici, pour toute opération démarrée ; une opération Planifiée part avec son Owner (décision de l'orchestrateur, reportée de #264 décision 10) | garder la suppression en cascade | L'état Parts porte des dettes entre joueurs et l'archive est l'historique des autres participants. Même règle de successeur que Group Industry. |
| 3 | Chacun pour soi : Planifiée → En cours → Clôture → Terminée ; « Marquer terminée » fige prix et valeurs et archive (décision de l'orchestrateur) | gel des prix à la fin du ledger, ou pas de gel | La fin fixe la valeur affichée dans l'archive ; sans gel, la sync Jita suivante la changerait. |
| 4 | Début `taken` sans fin, fin passée : quantité inconnue `no_end_snapshot`, corrigeable (décision de l'orchestrateur) | créer une fin après coup ; laisser `measuring` | Une fin lue après un début tardif ne mesure rien de fiable ; `measuring` bloquait le gel sans issue, la correction tracée existe déjà. |
| 5 | Marquage payé par le débiteur, l'Owner et les Admins, réversible tant que l'opération est en Parts, état courant seul ; montant figé au gel (décision de l'orchestrateur : conforme au corps de l'issue « par le débiteur ou l'Owner », Admins inclus comme pour la correction de quantité) | Owner et Admins seuls (proposition initiale de l'architecte) ; marquage irréversible | L'issue autorise le débiteur ; sans vérification par le wallet, une case cochée par erreur doit pouvoir se décocher avant le règlement. |
| 6 | Lignes de paiement : chaque participant verse `V_i − S_i` au booster, qui reverse la part corporation ; sans booster rémunéré, versement direct à la corporation | deux lignes par participant (booster et corporation) | Montants entiers exacts sans nouvelle règle d'arrondi : `D_i` sort directement des parts de #264. Répartir `D_i` entre booster et corporation demanderait un second partage au reste d'arrondi. Coût : le booster avance la part corporation. |
| 7 | Lignes de paiement stockées au gel (`mining_payments`) | lignes recalculées à la lecture, seuls les marquages stockés | Le montant dû est figé (demande de l'orchestrateur) et les noms survivent à la suppression d'un compte (le booster n'est pas retrouvable dans les valeurs figées après `SET NULL`). |
| 8 | Réglée par une action explicite « Marquer réglée », possible seulement quand tout est payé | passage automatique au dernier marquage | Laisse corriger le dernier marquage ; la maquette v4 a un bouton « Marquer réglée » activé par `canSettle()`. |
| 9 | Pas de statut `archived` distinct : Réglée (prorata) et Terminée (chacun pour soi) sont les états finaux, archivés et en lecture seule | `settled` → `archived` (corps de l'issue) | Un cinquième pas manuel n'apporte rien ; le stepper de #264 et la maquette s'arrêtent à Réglée / Terminée. |
| 10 | Archive = onglet de la liste, opérations lisibles (`canRead`) ; « Mes opérations » sans les archivées ; résultats d'une opération archivée lisibles par la corporation | tout dans « Mes opérations » | Le corps de l'issue : archive visible des participants et de la corporation pour une opération ouverte à la corporation. |
| 11 | Notifications in-app renvoyées à #271 | les faire ici | #271 couvre les notifications d'opération ; ici, Mercure suffit au rafraîchissement. |
| 12 | Pas de défigeage, pas de prix saisi à la main (#264 décisions 6 et 9 maintenues) | défiger tant qu'aucun paiement n'est marqué | Les corrections se font avant le gel, qui est confirmé. À reprendre si un gel erroné arrive en pratique. |
| 13 | Récapitulatif de fin = carte du détail composée des ressources existantes | ressource dédiée | Aucune donnée nouvelle ; le partage reste à #316. |
| 14 | Résumé d'archive calculé sur les valeurs figées et `MiningShareCalculator`, sans lire les instantanés | appeler `MiningOperationValuator` par opération | Le valuator lit tous les instantanés de chaque personnage : trop lourd pour une liste. |
| 15 | Maquette complémentaire `mockups/mining-operation-payments-archive.html`, la v4 reste la référence d'ensemble | aucune maquette | Paiements sans wallet, « Marquer terminée » et onglet Archive diffèrent de la v4. |

## 11. Fichiers

Backend : `src/Enum/{MiningOperationStatus,MinedQuantityUnknownReason,MiningShareBlocker}.php`, `src/Enum/MiningPaymentKind.php` (nouveau) ; `src/Entity/MiningOperation.php`, `src/Entity/MiningPayment.php` (nouveau) ; `src/Repository/{MiningOperationRepository,MiningOperationMemberRepository}.php`, `src/Repository/MiningPaymentRepository.php` (nouveau) ; une migration `migrations/Version2026110…php` ; `src/Service/MiningOperation/{MiningPaymentPlanner,MiningOperationSettlement,MiningOperationArchive}.php` (nouveaux), `MiningShareFreezer.php`, `MiningOperationValuator.php` (`completionBlockers`), `MiningOperationValuation.php`, `MinedQuantityCalculator.php`, `MiningOperationLifecycle.php` (`match`), `MiningOperationAccess.php` ; `src/Service/Account/AccountDeletionService.php` ; `src/ApiResource/MiningOperation/MiningPaymentResource.php` (nouveau), `MiningOperationResource.php`, `MiningOperationValuationResource.php` ; `src/ApiResource/Input/MiningOperation/UpdateMiningPaymentInput.php` (nouveau) ; `src/State/Provider/MiningOperation/{MiningPaymentCollectionProvider,MiningPaymentItemProvider,ArchivedMiningOperationsProvider}.php` (nouveaux), `MiningOperationResourceMapper.php`, `MiningOperationValuationProvider.php`, `MiningOperationLedgerProvider.php` ; `src/State/Processor/MiningOperation/{UpdateMiningPaymentProcessor,SettleMiningOperationProcessor,CompleteMiningOperationProcessor}.php` (nouveaux), `RetryMiningLedgerSnapshotProcessor.php`, `UpdateMiningOperationMemberProcessor.php`, `RemoveMiningOperationMemberProcessor.php`, `LeaveMiningOperationProcessor.php`.

Front : `frontend/src/stores/miningOperation.ts` ; `frontend/src/components/mining-operation/{MiningPayments,MiningSettleOperation,MiningCompleteOperation,MiningOperationRecap}.vue` (nouveaux), `MiningOperationStepper.vue`, `MiningStatusBadge.vue`, `MiningClosingLedger.vue` ; `frontend/src/views/MiningOperation/{MiningOperationDetail,MiningOperationList}.vue` ; `frontend/src/i18n/locales/{fr,en}.json`.

Docs au vert : `CLAUDE.md`, section Mercure : rien à changer (pas de nouveau topic).

## 12. Annexe — Harnais de test

### Dépendances de l'implémentation et ce que le harnais fournit

| Dépendance | Utilisée par | Fournie par le harnais |
|---|---|---|
| `MiningShareCalculator` (pur) | planner, freezer, archive | **réel** ; PA1-PA8 l'appellent pour obtenir les parts, sans entité |
| `MiningPaymentPlanner` (pur) | freezer | **réel** ; PA1-PA8 en `tests/Unit` sans base |
| `MiningShareFreezer` et `MiningOperationValuator` | gel avec paiements, fin en chacun pour soi | **réels**, sur `BuildsValuedSpodumainOperation::e1Operation()` et `valuatorOn()` (prix bouchonnés par `jitaMarketSyncedAt()`) ; `typeNameResolverOfSdeOres()` |
| `MinedQuantityCalculator` (pur) | R21, valuator | **réel**, entités montées par `BuildsLedgerSnapshots` (`takenStartSnapshot`, `waitingEndSnapshot`, `failedStartSnapshot`) |
| `MiningOperationLifecycle` | relance | réel via `lifecycleWith()` (motif `RetryMiningLedgerSnapshotProcessorTest`) |
| `Psr\Clock\ClockInterface` | `paidAt`, `archivedAt`, `sharesFrozenAt` | `MockClock` |
| `MiningOperationAccess` | providers, processors | réel |
| `MercurePublisherService` → `HubInterface` | processors (R27) | `HubInterface` bouchonné qui capture les `Update` (motif `BuildsSpodumainOperation`), `NullLogger` |
| `EntityManagerInterface` / repositories | processors | mock dans les tests unitaires (motif `FreezeMiningSharesProcessorTest`) ; base réelle `eve_app_test` en fonctionnel et intégration |
| `AccountDeletionService` | R23 | **réel** en intégration (`tests/Integration/Service/Account/`, trait `PersistsMiningOperations`) ; `EveSsoTokenRevoker` et cache comme dans `AccountDeletionMiningOperationTest` |
| Prix en cache | tests fonctionnels de T1, G1 | `self::getContainer()->set(JitaMarketService::class, $stub)` (motif `MiningOperationValuationApiTest`) |
| ESI | — | **aucun appel** dans ce lot |

Frontières mockées : marché (bouchon Jita), hub Mercure, horloge. Planification, montants, contrôles, transfert de propriété et requêtes d'archive tournent en vrai.

Classement : PA1-PA8, D1-D3 en `tests/Unit` sans base ; G1-G4, T1-T4, T8-T9, NE1-NE6 en `tests/Unit/Service/MiningOperation` ; M1-M10, RG1-RG6, RO1-RO4, T5-T7, T10, AR1-AR7, RT1-RT5, D4 en `tests/Functional/Api/MiningOperation/MiningOperationSettlementApiTest.php` (nouveau), avec quelques cas de processor en unitaire ; TR1-TR6 en `tests/Integration/Service/Account/AccountDeletionMiningOperationTest.php` ; FR1-FR12, MT2-MT3 en `frontend/tests` (store, composants, vues détail et liste, fixtures sur le motif `miningOperationValuationFixtures.ts`). Groupe `#[Group('issue-265')]`.

### Tests existants appelés à changer

| Test | Changement | Raison |
|---|---|---|
| `tests/Unit/Enum/MiningOperationStatusTest.php` : `testEveryStatusButSharesAndCompletedIsAnOpenOperation` | attendu + `'settled' => false` ; à renommer (`…ButSharesSettledAndCompleted…`) | nouveau cas de `cases()` |
| même fichier : `testOnlyPlannedAndInProgressOperationsAcceptParticipants` | attendu + `'settled' => false` | idem |
| `tests/Unit/ApiResource/MiningOperationSubResourceUriVariablesTest.php` | + 4 lignes (`GET` et `PATCH …/payments`, `POST …/settle`, `POST …/complete`) | nouveaux endpoints sous un parent |
| `tests/Integration/Migrations/MigrationsReplayTest.php` | vert avec la nouvelle migration | schéma = migrations |
| `RetryMiningLedgerSnapshotProcessorTest`, `MiningOperationLedgerApiTest::testCharacterOwnerRetriesHisStartSnapshotOnceReconnected`, `MiningOperationLifecycleTest` (relances) | verts sans modification : ils relancent un début en `in_progress` ou passent par l'administrateur ; RT1-RT5 ajoutés | R19 ne touche que la fin, côté accès |
| `MiningOperationLedgerProviderTest` (`canRetry`) | vert : `canRetry` vrai seulement sur un début en cours d'opération vu par son propriétaire | R19 |
| `MinedQuantityTest::testCharacterWithoutStartSnapshotOrWithAWaitingOneIsMeasuring`, `MiningOperationLedgerProviderTest` (`planned`, `closing` avec fins `waiting`) | verts : début absent ou `waiting` reste `measuring` | R21 ne vise qu'un début `taken` sans fin. À vérifier au rouge : tout test montant une opération `closing` avec un début `taken` et sans fin attendait `measuring` |
| `MiningShareFreezerTest`, `FreezeMiningSharesProcessorTest`, `MiningOperationValuationProviderTest`, `MiningOperationValuatorTest` (constructions de `MiningShareFreezer`) | verts : le planner a une valeur par défaut | R6 |
| `MiningOperationValuationApiTest` (F1-F12 de #264) | verts ; G1-G4 s'ajoutent | les paiements sont additifs |
| `AccountDeletionMiningOperationTest::testDeletedOwnerTakesHisOperationsAndMembershipsWithHimWhileTheOtherAccountKeepsItsOwn` | vert : l'opération y est `planned` | R23 garde la suppression d'une opération Planifiée |
| `AccountDeletionServiceTest::testNoForeignKeyColumnReferencesTheDeletedAccountAndTheOtherAccountKeepsAllItsRows` | vert, couvre `mining_payments.paid_by_id` | FK `SET NULL` |
| `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php` | vert (17 topics) | pas de nouveau topic |
| Front : `frontend/tests/views/MiningOperation/MiningOperationList.spec.ts` | + onglet « Archive » ; un comptage d'onglets ou un appel `fetchMine`/`fetchAvailable` exact cassera s'il en est | troisième onglet, `fetchArchive()` au montage |
| Front : `MiningOperationDetailShares.spec.ts` (stepper, `:429`) | vert si le test ne porte que sur `shares` ; à revoir s'il vérifie la classe de la dernière étape | dernière étape atteinte marquée faite |
| Front : `MiningOperationDetailStockpileCoverage.spec.ts` | vert (`closing`, `completed`) | la couverture du Stockpile ne change pas |
| Front : fixtures `miningOperationValuationFixtures.ts`, `miningOperationLedgerFixtures.ts` | + `canComplete`, `completionBlockers`, `archivedAt`, `archivedByName`, `archiveSummary` si le contrôle de types l'exige | types élargis |

### Questions de spec ouvertes

- Décision 5 : le débiteur, l'Owner et les Admins marquent (conforme à l'issue).
- Décision 6 : le booster qui collecte et reverse la part corporation convient-il en pratique, ou faut-il deux lignes par participant ? [À CONFIRMER]
- Opérations déjà en `shares` au déploiement : aucune ligne de paiement. [À CONFIRMER : aucune en production]
- Début `waiting` perdu dans le transport en clôture : reste `measuring` sans issue. Faut-il ouvrir sa relance à l'administration en clôture, ou le passer à inconnu après 5 min ? [À CONFIRMER]
- Vérification par le wallet (commentaire de l'issue) : issue séparée à ouvrir. [À CONFIRMER]
