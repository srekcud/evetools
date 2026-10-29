# Spec #241 : vendeur d'une vente de groupe et rattachement aux transactions wallet

- **Statut** : brouillon. Le circuit est tranché par Sylvain le 2026-10-29 (voir « Décisions de Sylvain ») ; les choix techniques sont pris en autonomie (section dédiée) ; les questions de la dernière section ne sont pas tranchées et, en attendant, chaque lot applique la recommandation.
- **Date** : 2026-10-29
- **Issue** : #241, « Group Industry : une vente n'a pas de vendeur et n'est pas reliée aux transactions du wallet ».
- **Lots** : deux lots dans cette spec, chacun un rouge puis un vert (ADR-0004 : `tests/` verrouillé pendant le vert). **LOT A** : le vendeur. **LOT B** : le rattachement aux transactions wallet. B part de A (le vendeur d'une vente rattachée est celui de la transaction).

Les termes en gras sont ceux du [glossaire](../glossary.md) ; ceux qui manquent sont proposés en fin de spec.

## Contexte

Faits vérifiés dans le code au 2026-10-29 :

- **Une vente ne connaît que son auteur.** `GroupIndustrySale` porte `recordedBy` (utilisateur qui a saisi, `ManyToOne User`, `onDelete: 'SET NULL'`, `src/Entity/GroupIndustrySale.php:48-50`), posé par `RecordSaleProcessor` (`src/State/Processor/GroupIndustry/RecordSaleProcessor.php:76`). L'API l'expose en `recordedByCharacterName`, nom du main de l'auteur par `PilotName::of()` (`src/ApiResource/GroupIndustry/GroupIndustrySaleResource.php:106`, `:121`) : `null` si le compte est supprimé, `'Unknown'` si l'utilisateur n'a pas de main (`src/Service/GroupIndustry/PilotName.php:11-19`). Aucun champ ne dit quel personnage a vendu, et `RecordSaleInput` n'en reçoit aucun (`src/ApiResource/Input/GroupIndustry/RecordSaleInput.php:14-34`).
- **Qui saisit.** Seuls le propriétaire et les admins `accepted` enregistrent, corrigent et suppriment une vente (`GroupProjectAccessChecker::assertAdminOrOwner()`, `src/State/Provider/GroupIndustry/GroupProjectAccessChecker.php:61-78`, appelé en `RecordSaleProcessor.php:57` et `UpdateSaleProcessor.php:51`). La liste est lisible par le propriétaire, les membres acceptés et la corporation du propriétaire (`assertCanRead()`, `:24-31`, `:93-99`, appelé en `GroupSaleCollectionProvider.php:52`). Elle n'est pas paginée (`GroupIndustrySaleResource.php:85`).
- **Liste.** `GroupIndustrySaleRepository::findByProjectForListing()` joint l'auteur, son main et le token du main (`src/Repository/GroupIndustrySaleRepository.php:28-31`) : `Character::$eveToken` est le côté inverse d'un `OneToOne` (`src/Entity/Character.php:46-47`), que Doctrine charge à part si on ne le joint pas. Le test d'intégration borne la liste à 4 requêtes (`tests/Integration/State/Provider/GroupIndustry/GroupSaleCollectionProviderTest.php:29`, `:54-65`).
- **Frontend.** `SalesTable.vue` n'affiche ni l'auteur ni le vendeur (colonnes Date, Quantity, Unit Price, Revenue, Venue, actions : `frontend/src/components/group-industry/SalesTable.vue:147-152`) ; le formulaire envoie toujours `typeId: 0` (`:27-33`). Le front connaît les personnages de l'utilisateur, avec leur `eveCharacterId` (`frontend/src/types/index.ts:1-12`, `:32-39`, lus par `useAuthStore().user`, `frontend/src/stores/auth.ts:8`).
- **Transactions wallet.** `CachedWalletTransaction` (table `cached_wallet_transactions`) garde les transactions de marché d'un personnage : type, quantité, prix unitaire, `isBuy`, lieu, date (`src/Entity/CachedWalletTransaction.php:26-52`). `transaction_id` y est unique (`:14`) ; la ligne est supprimée avec son personnage (`onDelete: 'CASCADE'`, `:26-28`). Elle est remplie par `WalletTransactionSyncService`, si le token porte `esi-wallet.read_character_wallet.v1` (`src/Service/Sync/WalletTransactionSyncService.php:29`). La sync est planifiée toutes les heures (`src/Scheduler/SyncScheduler.php:72-74`) pour les personnages à l'autorisation valide dont l'utilisateur s'est connecté depuis 7 jours (`SyncWalletTransactionsHandler.php:35`, `CharacterRepository::findActiveWithValidTokens()`, `src/Repository/CharacterRepository.php:94-106`). Aucune purge de cette table n'existe dans `src/`.
- **Scope ESI.** `esi-wallet.read_character_wallet.v1` est déjà demandé (`src/Service/ESI/AuthenticationService.php:34`). Aucun scope nouveau, donc aucune reconnexion.
- **Modèle des achats.** `PurchaseSuggestionProvider` propose les transactions d'achat des personnages de l'utilisateur pour les matériaux du projet (`src/State/Provider/Industry/PurchaseSuggestionProvider.php:98-102`, `CachedWalletTransactionRepository::findBuysByTypeIds()`, `src/Repository/CachedWalletTransactionRepository.php:28-43`) ; une transaction déjà liée reste proposée avec `alreadyLinked` (`:85-94`, `:116`). Le lien est `IndustryStepPurchase::$transaction`, `ManyToOne`, `onDelete: 'SET NULL'`, **sans unicité** (`src/Entity/IndustryStepPurchase.php:28-30`). `CreatePurchaseProcessor` recopie type, quantité et prix de la transaction, avec une quantité saisie facultative (`src/State/Processor/Industry/CreatePurchaseProcessor.php:64-77`). Il ne vérifie pas que la transaction appartient à l'utilisateur (voir « Écarts relevés »). Cette spec reprend le principe (proposer, puis créer à partir de la transaction) et ajoute ce qui manque : unicité, appartenance, exclusion des transactions déjà rattachées.
- **Modèle PVE.** `ScanLootSalesProcessor` lit les ventes (`is_buy` faux, `:107`) directement à l'ESI, pas dans le cache (`src/State/Processor/Pve/ScanLootSalesProcessor.php:92-95`), et exclut les transactions déjà importées ou refusées (`:75-76`, `:100-105`). LOT B lit le cache, comme les achats : aucun appel ESI pendant une requête de l'utilisateur.
- **Distribution.** Le revenu est la somme des `totalPrice` des ventes, relue à chaque calcul ; courtage et taxe de vente sont des pourcentages du projet (`src/Service/GroupIndustry/GroupIndustryDistributionService.php:22-30`). Aucune distribution n'est stockée.
- **Suppression.** Supprimer un compte supprime ses personnages (`Character::$user`, `onDelete: 'CASCADE'`, `Character.php:42-44`) ; les ventes dans les projets des autres restent, sans auteur (`AccountDeletionServiceTest::testSaleRecordedByTheDeletedAccountIsKeptWithoutAuthor`, `tests/Integration/Service/Account/AccountDeletionServiceTest.php:355-378`). Un personnage autre que le main peut être retiré seul (`src/State/Processor/Me/DeleteCharacterProcessor.php:48-54`).

## Décisions de Sylvain (2026-10-29)

- **D1 : options (a) puis (b), dans la foulée.** (a) enregistrer le vendeur sur la vente (LOT A) ; (b) rattacher les ventes aux transactions du wallet (LOT B).
- **D2 : option (c) non retenue.** Un membre ne saisit pas ses propres ventes : seuls le propriétaire et les admins saisissent, corrigent, suppriment et rattachent.

## Décisions prises en autonomie

- **DA1 : le vendeur est un personnage de l'auteur de la saisie.** `sellerCharacterId` (identifiant EVE, comme `CreateEscalationInput::$characterId` résolu parmi `$user->getCharacters()`, `src/State/Processor/Escalation/CreateEscalationProcessor.php:45-54`) est facultatif ; absent, le vendeur est **inconnu** (`null`). L'application ne connaît le wallet que de ses propres personnages : désigner le personnage d'un autre membre serait une affirmation invérifiable (Q1).
- **DA2 : lien en base vers `characters`, `ON DELETE SET NULL`.** Pas de copie du nom ni de l'identifiant EVE : un compte supprimé ne doit laisser aucune donnée personnelle (même règle que `recordedBy`), et le test de `AccountDeletionServiceTest.php:116-159` contrôle toutes les clés étrangères vers `users` et `characters` (`:919-946`). Contrepartie : un personnage retiré rend ses ventes à vendeur inconnu.
- **DA3 : filtre « mes ventes » côté front.** La liste est complète et non paginée : le front filtre sur `sellerCharacterId` parmi les `eveCharacterId` de l'utilisateur. Le provider et son constructeur ne changent pas.
- **DA4 : aucune dépendance nouvelle dans les constructeurs existants.** Le vendeur se résout par `$user->getCharacters()`, la transaction rattachée par `$sale->getWalletTransaction()`. `RecordSaleProcessor`, `UpdateSaleProcessor`, `GroupSaleCollectionProvider` gardent leur signature ; les nouveaux comportements de LOT B passent par une opération, un processor et un provider nouveaux.
- **DA5 : une colonne de liaison unique, pas une table.** `group_industry_sales.wallet_transaction_id`, `OneToOne` unidirectionnelle vers `CachedWalletTransaction` (index unique ; PostgreSQL accepte plusieurs `NULL`), `ON DELETE SET NULL`. L'unicité tient en base, quel que soit le projet : une transaction ne sert qu'une vente. Une table de liaison n'apporterait rien tant qu'une transaction n'est pas répartie entre plusieurs ventes (hors périmètre).
- **DA6 : rattacher, c'est créer la vente à partir de la transaction**, comme un achat (`CreatePurchaseProcessor.php:64-77`). Une vente saisie à la main n'est pas rattachée après coup (Q2).
- **DA7 : quantité partielle permise à la création, bornée par la transaction.** Une transaction peut mêler des unités du projet et d'autres ; les achats permettent déjà une quantité saisie (`CreatePurchaseProcessor.php:74`), ici bornée à la quantité de la transaction.
- **DA8 : une vente rattachée garde les valeurs de sa transaction.** Type, prix unitaire, date et vendeur ne se corrigent plus (400) ; la quantité se corrige dans la borne de DA7 ; le nom et le lieu restent libres. Pour revenir à une saisie libre : supprimer la vente (la transaction redevient proposée) et la ressaisir.
- **DA9 : fenêtre de dates = à partir de la création du projet**, sans borne haute. Une transaction antérieure ne peut pas vendre la production du projet ; elle reste saisissable à la main. Aucune autre borne n'est inventée.
- **DA10 : nom du type pris dans les items du projet**, pas dans le SDE. Les suggestions sont limitées aux types des items, dont le nom est enregistré (`GroupIndustryProjectItem::getTypeName()`, `src/Entity/GroupIndustryProjectItem.php:72`) et dont le `typeId` est résolu à la création (`CreateGroupProjectProcessor.php:115-128`) : aucun nom inventé, aucune recherche SDE.
- **DA11 : un doublon déjà en base est signalé, jamais résolu.** La suggestion porte les ventes non rattachées du projet de même quantité et de même prix unitaire (« doublon possible ») ; rien n'est supprimé ni fusionné (Q2).
- **DA12 : sélecteur de vendeur sans présélection.** Le formulaire propose « Inconnu » par défaut : présélectionner le main inventerait un vendeur.

## LOT A : le vendeur d'une vente

### Règles

- **A-R1 : donnée.** `GroupIndustrySale::$seller`, `ManyToOne Character`, colonne `seller_character_id` nullable, `onDelete: 'SET NULL'`, index. Getter `getSeller(): ?Character`, setter `setSeller(?Character)`.
- **A-R2 : migration sans perte.** Une migration Doctrine ajoute la colonne nullable, la clé étrangère et l'index ; `down()` les retire. Les ventes existantes restent à vendeur inconnu (`NULL`). **Aucune commande console de rattrapage**, aucune déduction depuis `recordedBy` : l'auteur n'est pas le vendeur.
- **A-R3 : saisie.** `RecordSaleInput::$sellerCharacterId`, `?int`, `#[Assert\Positive]`, par défaut `null`. Dans `RecordSaleProcessor`, après le contrôle d'accès et le contrôle du projet terminé : absent, vendeur `null` ; présent, le personnage de l'utilisateur courant dont `getEveCharacterId()` vaut cette valeur, sinon **400** `The seller must be one of your characters.`, sans vente persistée ni événement Mercure.
- **A-R4 : correction.** `UpdateSaleInput::$sellerCharacterId`, mêmes contrainte et résolution (personnage de celui qui corrige). `null` ou absent : inchangé, comme les autres champs (`UpdateSaleProcessor.php:76-98`). Effacer un vendeur n'est pas prévu (hors périmètre).
- **A-R5 : ressource.** `GroupIndustrySaleResource` ajoute `sellerCharacterId: ?int` (identifiant EVE) et `sellerCharacterName: ?string` (`Character::getName()`, pas `PilotName` : le vendeur est un personnage, pas un utilisateur). Les deux sont `null` ensemble. `recordedByCharacterName` ne change pas.
- **A-R6 : liste sans requête en plus.** `findByProjectForListing()` joint `s.seller` et `seller.eveToken` (comme l'auteur, `GroupIndustrySaleRepository.php:28-31`) : la liste reste à 4 requêtes au plus, quel que soit le nombre de vendeurs.
- **A-R7 : front, colonne.** `SalesTable.vue` ajoute une colonne « Vendeur » : `sellerCharacterName`, ou « Vendeur inconnu » si `null` (nouvelle clé i18n fr/en ; pas `common.deletedPilot`, car `null` veut aussi dire « jamais saisi »).
- **A-R8 : front, filtre « mes ventes ».** Une bascule au-dessus du tableau, visible de tout lecteur, ne garde que les ventes dont `sellerCharacterId` est l'`eveCharacterId` d'un personnage de l'utilisateur. Une vente à vendeur inconnu n'est jamais « à moi ». La ligne de total suit le filtre et le dit (« Total, mes ventes ») (Q3). Filtre vide : état vide dédié. La distribution n'est pas filtrée.
- **A-R9 : front, formulaire.** Champ « Vendeur » : liste des personnages de l'utilisateur plus « Inconnu », sans présélection (DA12). En correction, il est prérempli avec le vendeur de la vente si c'est un personnage de l'utilisateur, sinon « Inconnu » ; `changesOf()` n'envoie `sellerCharacterId` que si un personnage est choisi et différent. `GroupSale`, `RecordSaleInput` et `UpdateSaleInput` (`frontend/src/stores/group-industry/types.ts:114-125`, `:194-210`) suivent l'API.
- **A-R10 : Mercure inchangé.** `sale_recorded` et `sale_updated` gardent leurs données (`RecordSaleProcessor.php:81-90`, `UpdateSaleProcessor.php:62-71`) ; le front relit la liste (`groupProjectRefreshes.ts:9-11`).

### Exemples chiffrés

Projet P « Sabre » (Sabre, typeId 22456), statut `selling`. Propriétaire O (personnages `O-Main`, EVE 2120000001, et `O-Alt`, 2120000002), admin `accepted` A (`A-Main`, 2120000011), membre M (`M-Main`, 2120000021). Vente de base : `{typeId: 0, typeName: "Sabre", quantity: 3, unitPrice: 61250000}`.

| # | Cas | Attendu |
|---|---|---|
| A1 | O enregistre la vente de base avec `sellerCharacterId: 2120000002` | 201 ; `sellerCharacterId` 2120000002, `sellerCharacterName` « O-Alt », `recordedByCharacterName` « O-Main », total 183 750 000 |
| A2 | O enregistre sans `sellerCharacterId` | 201 ; vendeur `null` / `null` |
| A3 | O enregistre avec `sellerCharacterId: 2120000011` (personnage de A) | 400, rien de persisté, aucun Mercure |
| A4 | `sellerCharacterId: 0` ou `-5` | 422 sur `sellerCharacterId` |
| A5 | `sellerCharacterId: 99999999` (aucun personnage connu) | 400 |
| A6 | M enregistre avec `sellerCharacterId: 2120000021` | 403 (accès inchangé) |
| A7 | A corrige la vente de A2 : `PATCH {sellerCharacterId: 2120000011}` | 200 ; vendeur « A-Main », total inchangé ; Mercure `sale_updated` |
| A8 | A corrige la vente de A1 : `PATCH {sellerCharacterId: null}` | 200 ; vendeur « O-Alt » inchangé |
| A9 | A corrige la vente de A1 : `PATCH {sellerCharacterId: 2120000001}` (personnage de O) | 400, vente inchangée |
| A10 | Vente enregistrée avant la migration | listée avec vendeur `null`, « Vendeur inconnu » |
| A11 | O retire `O-Alt` de son compte | la vente de A1 passe à vendeur `null` ; quantité, prix, total, distribution inchangés |
| A12 | A supprime son compte, la vente de A7 reste dans P | vente conservée, vendeur `null`, auteur `null`, total inchangé |

Filtre « mes ventes » sur quatre ventes de P :

| Vente | Vendeur | Total |
|---|---|---|
| S1 | O-Main | 3 × 61 250 000 = 183 750 000 |
| S2 | O-Alt | 2 × 60 000 000 = 120 000 000 |
| S3 | A-Main | 1 × 62 000 000 = 62 000 000 |
| S4 | inconnu | 1 × 59 500 000 = 59 500 000 |

Sans filtre : 4 lignes, total 425 250 000. Filtre activé par O : S1 et S2, total 303 750 000. Par A : S3, total 62 000 000. Par M : aucune ligne, état vide. Le revenu de la distribution reste 425 250 000 dans les quatre cas.

## LOT B : rattacher une vente à une transaction wallet

### Règles

- **B-R1 : donnée.** `GroupIndustrySale::$walletTransaction`, `OneToOne` unidirectionnelle vers `CachedWalletTransaction`, colonne `wallet_transaction_id` nullable, **unique**, `onDelete: 'SET NULL'` (DA5). Une migration l'ajoute, sans toucher aux ventes existantes (toutes à `NULL`, donc non rattachées). `down()` la retire.
- **B-R2 : suggestions.** `GET /group-industry/projects/{projectId}/sale-suggestions`, `GetCollection` sur une nouvelle ressource `GroupSaleSuggestionResource`, `uriVariables: ['projectId' => new Link(fromClass: GroupIndustryProjectResource::class)]`, `paginationEnabled: false`, provider `GroupSaleSuggestionProvider` (modèle : `GroupContainerVerificationResource`). Accès : `assertAdminOrOwner()` (D2), 403 sinon, 404 si le projet est inconnu. Une transaction est proposée si, et seulement si :
  1. elle appartient à un personnage de l'utilisateur courant ;
  2. c'est une vente (`isBuy` faux) ;
  3. son type est celui d'un item du projet ;
  4. sa date est postérieure ou égale à la création du projet (`GroupIndustryProject::getCreatedAt()`, `GroupIndustryProject.php:257`) (DA9) ;
  5. aucune vente de groupe, de ce projet ou d'un autre, ne lui est rattachée.

  Aucun filtre sur la quantité ni sur le prix. Lecture du cache uniquement, en une requête (nouvelle méthode de `CachedWalletTransactionRepository`, jointure gauche sur `GroupIndustrySale` et `IS NULL`), tri par date décroissante. Un projet terminé garde ses suggestions en lecture ; c'est le rattachement qui est refusé (B-R4).
- **B-R3 : contenu d'une suggestion.** `transactionId` (UUID de `CachedWalletTransaction`, identifiant), `typeId`, `typeName` (nom de l'item du projet, DA10), `quantity`, `unitPrice`, `totalPrice` (`CachedWalletTransaction::getTotalPrice()`), `date` (ATOM), `sellerCharacterId`, `sellerCharacterName`, `locationId`, `possibleDuplicateSaleIds` : identifiants des ventes **non rattachées** du projet de même `quantity` et de même `unitPrice` (égalité exacte), quel que soit leur `typeId` (le formulaire saisit 0) (DA11).
- **B-R4 : rattachement.** `POST /group-industry/projects/{projectId}/sales/from-wallet-transaction` sur `GroupIndustrySaleResource`, `uriVariables` `projectId` (`Link` vers `GroupIndustryProjectResource`), input `RecordSaleFromWalletTransactionInput` : `transactionId` (requis, `#[Assert\NotBlank]`, `#[Assert\Uuid]`), `quantity` (facultatif, `#[Assert\Range(min: 1, max: RecordSaleInput::MAXIMUM_QUANTITY)]`). Processor `RecordSaleFromWalletTransactionProcessor`, dans cet ordre :
  1. utilisateur absent : 401 ; projet inconnu : 404 ; ni propriétaire ni admin `accepted` : 403 ; projet terminé : 400 (comme `RecordSaleProcessor.php:59-61`) ;
  2. transaction inconnue **ou d'un personnage d'un autre utilisateur** : 404 `Transaction not found` (on ne révèle pas qu'elle existe) ;
  3. achat (`isBuy` vrai), type absent des items du projet, ou date antérieure à la création du projet : 400, message qui nomme la règle ;
  4. `quantity` supérieure à celle de la transaction : 400 ;
  5. transaction déjà rattachée à une vente : **409** (précédent : `JoinGroupProjectProcessor.php:72`). L'index unique tranche une course : `UniqueConstraintViolationException` au flush devient aussi un 409.

  La vente créée : `typeId` et `unitPrice` de la transaction, `typeName` de l'item, `quantity` saisie ou celle de la transaction, `totalPrice` recalculé, `soldAt` = date de la transaction, vendeur = personnage de la transaction, `venue` `null` (le `locationId` n'est pas traduit en lieu), `recordedBy` = utilisateur courant, `walletTransaction` posée. Réponse 201 avec la vente ; Mercure `sale_recorded`, mêmes données que la saisie manuelle.
- **B-R5 : ressource.** `GroupIndustrySaleResource` ajoute `linkedToWalletTransaction: bool` (`getWalletTransaction() !== null` : le proxy suffit, aucune requête en plus pour la liste).
- **B-R6 : correction d'une vente rattachée** (`UpdateSaleProcessor`, DA8). Si `typeId`, `unitPrice`, `soldAt` ou `sellerCharacterId` est fourni (non `null`) : 400 `This sale is linked to a wallet transaction.`, vente inchangée. `quantity` au-delà de celle de la transaction : 400. `typeName`, `venue` et `quantity` dans la borne : acceptés. Une vente dont la transaction a disparu (B-R8) n'est plus rattachée : tout se corrige.
- **B-R7 : suppression.** Inchangée (`DeleteSaleProcessor`) ; la transaction redevient proposée.
- **B-R8 : disparition de la transaction.** Retirer un personnage ou supprimer un compte supprime ses transactions en cache (`CachedWalletTransaction.php:26-28`) : la vente reste avec ses montants, `wallet_transaction_id` passe à `NULL`, et le vendeur aussi (A-R1).
- **B-R9 : front.** Sous `SalesTable.vue`, pour le propriétaire et les admins (`isAdmin`, `GroupIndustryDetail.vue:450`) : un panneau « Transactions à rattacher » liste les suggestions (date, personnage, quantité, prix unitaire, total), un badge « Doublon possible » quand `possibleDuplicateSaleIds` n'est pas vide (avec la ou les ventes visées), une quantité facultative et l'action « Enregistrer comme vente ». Après succès, le panneau relit ses suggestions ; `sale_recorded` rafraîchit déjà ventes et distribution. Dans le tableau, une vente rattachée porte un badge « Wallet » et le formulaire de correction grise type, prix, date et vendeur. Store : `fetchSaleSuggestions()` et `recordSaleFromWalletTransaction()` dans `stores/group-industry/distribution.ts`, types dans `types.ts`. Les erreurs 400/404/409 s'affichent par leur message. Maquette `/frontend-design` avant le développement front.

### Exemples chiffrés

Projet P (item Sabre 22456, créé le 2026-10-20T10:00:00+00:00, `selling`, courtage 3 %, taxe de vente 4 %). P porte déjà une vente manuelle V : `typeId` 0, « Sabre », 3 × 61 250 000, non rattachée. Transactions en cache :

| # | Personnage | Sens | Type | Qté × prix | Date | Proposée à O ? |
|---|---|---|---|---|---|---|
| T1 | O-Main | vente | Sabre | 3 × 61 250 000 | 2026-10-24T21:05:00Z | oui, `possibleDuplicateSaleIds: [V]` |
| T2 | O-Main | achat | Sabre | 5 × 58 000 000 | 2026-10-22 | non (achat) |
| T3 | O-Alt | vente | Ishtar (12005) | 1 × 310 000 000 | 2026-10-23 | non (pas un item de P) |
| T4 | O-Main | vente | Sabre | 1 × 60 000 000 | 2026-10-19 | non (avant la création de P) |
| T5 | A-Main | vente | Sabre | 2 × 61 000 000 | 2026-10-25 | non (personnage de A) ; proposée à A |
| T6 | O-Alt | vente | Sabre | 2 × 60 500 000 | 2026-10-25 | non (rattachée à une vente d'un autre projet) |
| T7 | O-Alt | vente | Sabre | 12 × 60 000 000 | 2026-10-26 | oui, aucun doublon |

| # | Cas | Attendu |
|---|---|---|
| L1 | O rattache T1 | 201 ; vente 22456 « Sabre », 3 × 61 250 000 = 183 750 000, `soldAt` 2026-10-24T21:05:00+00:00, vendeur O-Main, `venue` `null`, `linkedToWalletTransaction` vrai ; Mercure `sale_recorded` ; T1 n'est plus proposée ; V inchangée |
| L2 | O rattache T1 une seconde fois | 409, aucune vente créée |
| L3 | O rattache T7 avec `quantity: 10` | 201 ; 10 × 60 000 000 = 600 000 000 |
| L4 | O rattache T7 avec `quantity: 13` | 400 ; avec `quantity: 0` : 422 |
| L5 | O rattache T2 | 400 (achat) |
| L6 | O rattache T5 | 404 |
| L7 | O rattache T3 | 400 (type hors projet) |
| L8 | O rattache T4 | 400 (avant la création du projet) |
| L9 | O rattache T7 sur P passé `completed` | 400 |
| L10 | M demande les suggestions ou rattache | 403 |
| L11 | `transactionId` inconnu / pas un UUID | 404 / 422 |
| P1 | Vente de L1 : `PATCH {unitPrice: 60000000}` | 400, inchangée |
| P2 | Vente de L1 : `PATCH {quantity: 2}` | 200 ; total 122 500 000 |
| P3 | Vente de L1 : `PATCH {quantity: 4}` | 400 |
| P4 | Vente de L1 : `PATCH {venue: "Jita 4-4"}` | 200 |
| P5 | Vente de L1 : `PATCH {sellerCharacterId: 2120000002}`, `{soldAt: …}` ou `{typeId: 22456}` | 400 |
| D1 | O supprime la vente de L1 | 204 ; T1 de nouveau proposée, avec V en doublon possible |
| X1 | A rattache T5, puis supprime son compte | vente gardée, 2 × 61 000 000 ; `linkedToWalletTransaction` faux ; vendeur `null` |

Distribution de P (montants en ISK, tolérance `float`) :

| État | Revenu | Courtage (3 %) | Taxe (4 %) | Revenu net |
|---|---|---|---|---|
| après L1, V encore là | 367 500 000 | 11 025 000 | 14 700 000 | 341 775 000 |
| après suppression de V | 183 750 000 | 5 512 500 | 7 350 000 | 170 887 500 |

La première ligne montre le risque de doublon que le badge signale (DA11) : rien ne le retire seul.

## Cas limites

- **Vendeur inconnu.** Trois causes, une seule valeur `null` : vente antérieure à la migration, vendeur non saisi, personnage retiré ou compte supprimé. Le front ne les distingue pas (« Vendeur inconnu »).
- **Ordre des erreurs.** Les deux `POST` n'ont pas de provider : la validation de l'input passe avant l'accès (un membre qui envoie `sellerCharacterId: 0` reçoit 422, pas 403), comme pour la saisie actuelle (spec #164).
- **Délai de la sync.** Une transaction apparaît au plus tôt à la sync horaire suivante, et seulement pour un utilisateur connecté depuis 7 jours. Aucun rafraîchissement à la demande dans ce lot.
- **Personnage sans le scope wallet.** Aucune transaction en cache, donc aucune suggestion pour lui ; ce n'est pas une erreur.
- **Transaction partagée entre acheteur et vendeur.** `transaction_id` est unique pour toute la table (`CachedWalletTransaction.php:14`) et la sync écarte un identifiant déjà connu quel que soit le personnage (`WalletTransactionSyncService.php:62-68`). Si l'ESI donnait le même identifiant aux deux parties d'un échange entre deux utilisateurs d'evetools, la transaction du second ne serait jamais en cache. Comportement de l'ESI : inconnu (rien dans le code ne le dit). Hors lot.
- **Frais.** Le prix unitaire d'une transaction est brut ; la distribution applique ensuite les pourcentages du projet, comme pour une vente saisie à la main. Le courtage réellement payé (journal wallet) n'est pas lu.
- **Même transaction en PVE.** Une transaction de vente importée comme vente de loot PVE (`PveIncome`) n'est pas exclue des suggestions Group Industry, et inversement. Hors lot.
- **Deux items du même type** (ME/TE différents) : un seul type, le nom du premier item.

## Hors périmètre

- Désigner comme vendeur le personnage d'un autre membre (Q1), et toute saisie de ventes par un membre (D2).
- Rattacher après coup une vente saisie à la main (Q2) ; détacher une vente sans la supprimer.
- Répartir une transaction entre plusieurs ventes ou plusieurs projets.
- Refuser ou masquer une suggestion (comme les ventes PVE refusées).
- Synchronisation du wallet à la demande ; ventes par contrat (seules les transactions de marché sont lues).
- Traduire `locationId` en lieu de vente (`venue`).
- Effacer le vendeur par `PATCH` (`null` = inchangé).
- Toute commande console de rattrapage (vendeur ou lien).

## Fichiers concernés (indicatif)

- LOT A : `GroupIndustrySale`, migration, `RecordSaleInput`, `UpdateSaleInput`, `RecordSaleProcessor`, `UpdateSaleProcessor`, `GroupIndustrySaleResource`, `GroupIndustrySaleRepository::findByProjectForListing()` ; front : `SalesTable.vue`, `stores/group-industry/types.ts`, locales fr/en ; `docs/glossary.md`.
- LOT B : `GroupIndustrySale`, migration, `CachedWalletTransactionRepository`, nouveaux `GroupSaleSuggestionResource`, `GroupSaleSuggestionProvider`, `RecordSaleFromWalletTransactionInput`, `RecordSaleFromWalletTransactionProcessor`, opération dans `GroupIndustrySaleResource`, `UpdateSaleProcessor` ; front : nouveau panneau de suggestions, `SalesTable.vue`, `stores/group-industry/distribution.ts`, `types.ts`, locales ; `docs/glossary.md`.
- Aucune dépendance nouvelle, aucun scope ESI nouveau.

## Tests existants appelés à changer

- **Front, fixtures typées `GroupSale`** : `vue-tsc -b` vérifie aussi `tests/**/*.ts` (`frontend/tsconfig.json:23`, `frontend/package.json:8`). Les champs ajoutés étant requis, ces fixtures cassent la vérification de types : `frontend/tests/stores/groupDistributionErrors.spec.ts:27`, `groupDistributionAnotherProject.spec.ts:24` et `:51`, `groupDistributionSaleCorrection.spec.ts:13`, `:26`, `:39`, `frontend/tests/components/group-industry/salesTableScrollContainer.spec.ts:17`, `:30`. À compléter **pendant le rouge** (`sellerCharacterId: null`, `sellerCharacterName: null` en A ; `linkedToWalletTransaction: false` en B), puisque `tests/` est verrouillé pendant le vert.
- **Constructeurs** (DA4) : `RecordSaleProcessorTest.php:59`, `UpdateSaleProcessorTest.php:70`, `SaleCorrectionDistributionTest.php:204`, `GroupSaleCollectionProviderTest.php:45` (unitaire) et `:166` (intégration), `AccountDeletionServiceTest.php:820` ne changent pas. Si l'implémentation ajoute une dépendance, ces six sites cassent : c'est le signe qu'elle s'écarte de la spec.
- **`AccountDeletionServiceTest::testNoForeignKeyColumnReferencesTheDeletedAccountAndTheOtherAccountKeepsAllItsRows`** (`:116-159`) : la colonne `group_industry_sales.seller_character_id` y entre d'elle-même (`rowsReferencing()`, `:919-946`) et vaut 0 après suppression grâce au `SET NULL` ; le test ne casse pas. À étendre en A : `seedPersonalData()` sème une vente vendue par le personnage, et `$seededColumns` gagne `'group_industry_sales.seller_character_id' => 1`.
- **Liste des ventes** (`GroupSaleCollectionProviderTest` intégration, `:34-76`) : reste verte si A-R6 est respecté ; elle devient le garde-fou du N+1 avec des vendeurs distincts.
- Tests unitaires de la ressource et des inputs (`RecordSaleInputValidationTest`, `UpdateSaleInputValidationTest`, `GroupSaleCollectionProviderTest::testSalesAreListedInTheListingOrderWithTheNameOfTheirRecorder`) : aucune assertion sur l'ensemble des propriétés, rien ne casse.

## Tests attendus par couche

### LOT A

- **Input** (`tests/Unit/ApiResource/Input/GroupIndustry/`) : A4 sur `RecordSaleInput` et `UpdateSaleInput` (0, -5 : violation ; absent, `null`, 2120000002 : aucune).
- **Processors** (`tests/Unit/State/Processor/GroupIndustry/`) : A1, A2, A3, A5 dans `RecordSaleProcessorTest` (A3 et A5 : `persist` et Mercure jamais appelés) ; A7, A8, A9 dans `UpdateSaleProcessorTest`.
- **Ressource** : `fromSale()` donne `sellerCharacterId` et `sellerCharacterName` ensemble, `null` ensemble.
- **Intégration** (non lancés sans base, `make test-db` puis `make test-integration`) : migration appliquée sur une vente existante, vendeur `null` (A10) ; A11 (personnage retiré) ; A12 dans `AccountDeletionServiceTest` ; liste avec quatre vendeurs distincts sous 4 requêtes (A-R6).
- **Front** (`frontend/tests/components/group-industry/`) : colonne « Vendeur » et « Vendeur inconnu » ; filtre « mes ventes » sur S1 à S4 (lignes et total pour O, A, M) ; formulaire sans présélection, `sellerCharacterId` envoyé seulement s'il est choisi.

### LOT B

- **Input** : `transactionId` vide ou non UUID, `quantity` 0 : violations.
- **Provider** (`GroupSaleSuggestionProvider`, unitaire) : T1 à T7 vus par O (T1, T7 seulement ; `possibleDuplicateSaleIds` de T1 = [V]) et par A (T5) ; 403 pour M ; 404 projet inconnu.
- **Processor** (`RecordSaleFromWalletTransactionProcessor`, unitaire) : L1 à L9, avec contrôle que la vente porte les valeurs de la transaction et que `persist`/Mercure ne sont pas appelés sur un refus.
- **`UpdateSaleProcessorTest`** : P1 à P5 ; correction libre d'une vente dont la transaction a disparu.
- **Intégration** (non lancés sans base) : requête des suggestions sur une vraie base (exclusion des transactions rattachées à un autre projet) ; L2 par l'index unique (deux rattachements, le second en 409) ; X1 dans `AccountDeletionServiceTest` (transaction supprimée en cascade, vente gardée) ; D1.
- **Fonctionnel** (`tests/Functional/Api/GroupIndustry/GroupSaleApiTest.php`) : routes, `uriVariables` résolues (pas de 400 d'API Platform), L11.
- **Front** : panneau de suggestions (badge doublon, quantité, appel du store), badge « Wallet », champs grisés en correction.
- **Architecture** : `make deptrac` sans nouvelle violation.

## Déploiement

- Chaque lot apporte une migration. `make deploy` l'exécute (`Makefile:95`, `:107`) ; après chaque déploiement, vérifier en production que la migration est passée (`doctrine:migrations:status` dans le conteneur `app`).
- **Aucune commande console à lancer** : pas de rattrapage, les ventes existantes restent à vendeur inconnu et non rattachées.
- Aucun nouveau scope : aucune reconnexion EVE SSO demandée aux joueurs.

## Termes du glossaire

Utilisés tels quels : **Contribution**, **Projet terminé**, **Compte supprimé**, **Quantité**, **Frais de courtage**, **Taxe de vente**.

À ajouter à la section « Industrie collaborative » pendant le rouge du lot concerné :

- LOT A, **Vente** (Sale) : vente d'un produit d'un projet collaboratif, saisie par le propriétaire ou un admin. Elle porte une quantité en unités, un prix unitaire, un lieu libre, une date, son auteur et, si on le connaît, son **vendeur** ; son total vaut quantité × prix unitaire. Les ventes forment le revenu de la distribution. Elle se corrige et se supprime tant que le projet n'est pas terminé (spec #164). Symboles : `GroupIndustrySale`, `GroupIndustrySaleResource`, `RecordSaleProcessor`, `UpdateSaleProcessor`, `DeleteSaleProcessor`.
- LOT A, **Vendeur** (Seller) : personnage EVE qui a vendu les unités d'une **vente**, choisi parmi les personnages de l'auteur de la saisie, ou celui de la **transaction wallet** rattachée. Distinct de l'auteur de la saisie. Inconnu (`null`, jamais deviné) pour une vente antérieure à #241, sans vendeur saisi, ou dont le personnage a été retiré (spec #241). Symboles : `GroupIndustrySale::$seller`, `sellerCharacterId`, `sellerCharacterName`.
- LOT A, mise à jour de **Compte supprimé** : « ventes … conservées sans auteur ni vendeur ».
- LOT B, **Transaction wallet** (Wallet transaction), section « Comptes et ESI » : achat ou vente au marché d'un personnage, lu à l'ESI et mis en cache par la sync horaire (`isBuy` distingue les deux). Une transaction de vente est rattachée à au plus une vente de groupe (spec #241). Symboles : `CachedWalletTransaction`, `WalletTransactionSyncService`, `GroupIndustrySale::$walletTransaction`.

Le `venue` libre d'une vente n'est pas le **Lieu de vente** du glossaire (`jita` ou `structure`, pour une marge) : à ne pas fusionner (déjà noté par la spec #164).

## Écarts relevés en chemin (hors lot)

- `CreatePurchaseProcessor` lie une transaction trouvée par son seul UUID, sans vérifier qu'elle appartient à un personnage de l'utilisateur (`src/State/Processor/Industry/CreatePurchaseProcessor.php:64-72`) : un UUID connu suffit pour recopier la quantité et le prix d'une transaction d'un autre joueur. La quantité saisie n'est pas bornée par celle de la transaction (`:74`). Correction proposée : même contrôle que B-R4 (404 si la transaction n'est pas à l'utilisateur), dans une issue à part.
- `ScanLootSalesProcessor` remplace des données ESI manquantes par des valeurs plausibles (`quantity ?? 1`, `unit_price ?? 0`, `:121-122`) et avale les erreurs (`catch (\Throwable) { continue; }`, `:145-147`), contre la règle « pas de `?? 0` ni de catch silencieux sur une donnée métier ». Issue à part.

## Questions ouvertes

1. **Q1 : vendeur parmi les personnages des autres membres ?** Options : (a) personnages de l'auteur de la saisie seulement ; (b) personnages de tous les membres acceptés. Recommandation : (a), retenu par défaut. (b) permet d'attribuer une vente à un joueur sans voir son wallet, donc sans preuve, et rapproche de l'option (c) écartée.
2. **Q2 : rattacher une vente déjà saisie à la main ?** Options : (a) non, badge « doublon possible » et suppression manuelle de la vente manuelle par le `DELETE` existant ; (b) rattachement qui remplace quantité, prix, date et type de la vente par ceux de la transaction (montants affichés et distribution changés) ; (c) fusion automatique. Recommandation : (a), retenu par défaut ; aucune donnée n'est supprimée ni réécrite sans geste explicite.
3. **Q3 : total de la liste filtrée.** Options : (a) la ligne de total suit le filtre « mes ventes », libellée « Total, mes ventes » ; (b) elle garde le total du projet. Recommandation : (a), retenu par défaut ; le revenu du projet reste visible dans la distribution.
