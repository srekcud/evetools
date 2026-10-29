# Spec #205 + #285 — Achat rattaché à une transaction wallet : propriété, unicité, matériaux de réaction

**Statut : validée en autonomie en mode nuit le 2026-10-29, à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage). Un seul lot : les deux issues touchent `CreatePurchaseProcessor`. Tests unitaires seulement, pas de base : dépôts, `Security` et `EntityManagerInterface` doublés ; utilisateurs, personnages, projets, étapes, achats et transactions en vraies entités. Tests de composants front (Vitest, `en.json`/`fr.json`).

## Contexte

**Rattachement** — `POST /api/industry/projects/{id}/steps/{stepId}/purchases` (`StepPurchaseResource.php:21-26`, input `CreatePurchaseInput`, processor `CreatePurchaseProcessor`).

- Projet de l'utilisateur, sinon 404 (`CreatePurchaseProcessor.php:47-51`) ; étape du projet, sinon 404 (`:53-57`).
- Avec `transactionId`, la transaction est cherchée par son seul UUID (`:66`, `Uuid::fromString()` sans contrôle : un identifiant qui n'est pas un UUID lève `\InvalidArgumentException`, non mappée dans `config/packages/api_platform.yaml`, donc 500). Inconnue : 404 (`:68-70`). Puis type, quantité (saisie ou celle de la transaction), prix unitaire et total sont recopiés, source `esi_wallet` (`:72-77`).
- **#285** : aucun contrôle que la transaction appartient à un personnage de l'utilisateur. Un UUID connu suffit pour recopier la transaction d'un autre joueur. Déjà relevé par spec #241 (« Écarts relevés », `docs/specs/241-group-sale-seller-and-wallet.md:240`).
- **#205** : aucun contrôle d'unicité. `IndustryStepPurchase::$transaction` est un `ManyToOne` nullable, `onDelete: 'SET NULL'`, sans index unique (`IndustryStepPurchase.php:28-30`). La même transaction peut être rattachée deux fois à la même étape, à deux étapes ou à deux projets ; son prix compte alors dans le coût de chacun.
- `IndustryStepPurchaseRepository` n'a aucune méthode propre (`IndustryStepPurchaseRepository.php:14-20`).

**Suggestions** — `GET /api/industry/projects/{id}/purchase-suggestions` (`PurchaseSuggestionProvider`).

- Types suggérés (`PurchaseSuggestionProvider.php:49-77`) :
  - le produit de chaque étape de profondeur > 0 hors copie (`:52-56`) ;
  - à défaut seulement, les matériaux SDE des blueprints des étapes hors copie, par une requête SQL brute limitée à `activity_id = 1` (`:66-76`, `:70`). Une formule de réaction n'a que des matériaux d'activité 11 : un projet à une seule étape de réaction n'a aucune suggestion.
- Transactions : achats (`isBuy`) des personnages de l'utilisateur sur ces types (`:98-102`, `CachedWalletTransactionRepository::findBuysByTypeIds()`, `CachedWalletTransactionRepository.php:30-45`).
- Déjà rattachées : seules celles du **projet courant** sont repérées (`:85-94`) et restent listées avec `alreadyLinked = true` (`:116`). Une transaction rattachée à un autre projet est proposée comme libre.
- Le repérage compare `(string) $tx->getId()` : en test unitaire, une transaction non persistée a un id `null`, donc la clé `''` est partagée par toutes.
- Constantes d'activité : `App\Enum\IndustryActivityType::Manufacturing = 1`, `::Reaction = 11` (`src/Enum/IndustryActivityType.php:9`, `:14`), déjà utilisées en SQL par `ProductSearchProvider.php:37-39`. `IndustryActivityMaterialRepository::findMaterialsForBlueprints(array $blueprintTypeIds, array $activityIds)` (`src/Repository/Sde/IndustryActivityMaterialRepository.php:40-73`) fait la même lecture avec une liste d'activités.

**Précédent dans le module voisin** — `RecordSaleFromWalletTransactionProcessor` (spec #241 B-R4) :

- transaction inconnue, pas un UUID ou d'un personnage d'un autre utilisateur → 404 `Transaction not found` (`RecordSaleFromWalletTransactionProcessor.php:110-121`) ;
- déjà rattachée à une vente → **409** (`:37`, `:73-75`) ; `UniqueConstraintViolationException` au flush → 409 (`:90-94`), grâce à l'index unique de `GroupIndustrySale::$walletTransaction` (`OneToOne`, `GroupIndustrySale.php:56-58`).
- Autres 409 du code : `CreateStructureProcessor.php:100`, `JoinGroupProjectProcessor.php:72`, `AddFavoriteProcessor.php:55`. Aucun 422 métier dans les processors : 422 reste réservé aux violations du validateur.

**Front.**

- `usePurchasesStore().createPurchase()` (`frontend/src/stores/industry/purchases.ts:24-43`) range le message dans `error` (jamais affiché) puis relance l'exception.
- `ShoppingTab.linkSuggestion()` (`frontend/src/components/industry/ShoppingTab.vue:398-415`) n'a qu'un `try/finally` : un échec devient une promesse rejetée non gérée, le bouton se réactive (`resetLinkingId`) et **rien ne s'affiche**.
- `apiRequest` lève une `ApiError` portant `status` pour les erreurs hors 401/420/422/429 (`frontend/src/services/api.ts:65-70`, `:218`). Précédent de traduction d'un 409 : `StructureConfig.vue:59` (`HTTP_CONFLICT`) et `:358-361`.
- `ShoppingPurchasesPanel.vue` affiche le badge « Linked » quand `alreadyLinked` (`:129`, `:138`) ; props `:22-29` ; carte des suggestions `:90-111`.

## Règles métier

- **R1 — Propriété (#285).** Un achat ne se rattache qu'à une transaction wallet d'un personnage de l'utilisateur courant (`$user->getCharacters()->contains($transaction->getCharacter())`). Sinon : **404 `Transaction not found`**, même réponse qu'une transaction inconnue (on ne révèle pas qu'elle existe). Aucun achat créé, ni `persist` ni `flush`.
- **R2 — Identifiant invalide.** Un `transactionId` qui n'est pas un UUID donne le même 404 que R1, sans lecture du dépôt (comme `RecordSaleFromWalletTransactionProcessor.php:112-114`). Aujourd'hui : 500.
- **R3 — Unicité (#205).** Une transaction wallet est rattachée à **au plus un achat**, tous projets et toutes étapes confondus. Si un achat la référence déjà (`IndustryStepPurchaseRepository::findOneBy(['transaction' => $transaction])`) : **409 `This transaction is already linked to a purchase.`**, aucun achat créé. Cela vaut aussi pour le projet courant et la même étape, et pour une quantité partielle.
- **R4 — Ordre des contrôles.** 401 utilisateur → 404 projet → 404 étape → R2 → 404 transaction inconnue ou d'un autre (R1) → 409 (R3) → création. La propriété passe avant l'unicité : un tiers n'apprend jamais qu'une transaction qui n'est pas à lui est rattachée.
- **R5 — Création inchangée.** Transaction de l'utilisateur, non rattachée : achat créé comme aujourd'hui (type, quantité saisie ou celle de la transaction, prix unitaire, total, source `esi_wallet`), 201. L'achat manuel (sans `transactionId`) n'est pas concerné par R1 à R3 et ne lit pas `IndustryStepPurchaseRepository`.
- **R6 — Suggestions sans transaction rattachée ailleurs (#205).** Pour chaque transaction trouvée :

  | Achats qui la référencent | Suggestion |
  |---|---|
  | aucun | proposée, `alreadyLinked = false` |
  | au moins un dans une étape du projet courant | proposée, `alreadyLinked = true` (badge « Linked », inchangé) |
  | uniquement dans d'autres projets, de l'utilisateur ou non | **absente** |

  `totalCount` compte les suggestions rendues. Les achats sont lus en **une** requête pour toutes les transactions trouvées (`IndustryStepPurchaseRepository::findBy(['transaction' => $transactions])`), pas lus si aucune transaction n'est trouvée. L'appartenance au projet compare les entités (`$purchase->getStep()->getProject() === $project`), la transaction aussi (identité d'objet, pas `(string) getId()`).
- **R7 — Matériaux des réactions (#205).** Quand les matériaux viennent du SDE (projet sans étape de profondeur > 0, `:58-77`), ils sont lus pour les activités `IndustryActivityType::Manufacturing` **et** `IndustryActivityType::Reaction`, via `IndustryActivityMaterialRepository::findMaterialsForBlueprints($blueprintTypeIds, [1, 11])`. Un blueprint de fabrication n'a pas de matériaux d'activité 11, une formule de réaction pas d'activité 1 : les projets de fabrication gardent exactement leurs suggestions.
- **R8 — Écran.** Un échec du rattachement s'affiche dans la carte des suggestions (`data-testid="purchase-link-error"`, `role="alert"`, rouge) :

  | Erreur | Clé |
  |---|---|
  | `ApiError` 409 | `industry.shoppingTab.linkErrors.alreadyLinked` |
  | `ApiError` 404 | `industry.shoppingTab.linkErrors.notFound` |
  | toute autre exception | `industry.shoppingTab.linkErrors.failed` |

  Après un échec, les suggestions sont relues (la transaction disparaît ou passe « Linked ») ; projet et liste d'achats ne sont pas relus. Le message s'efface au rattachement suivant et au clic sur « Refresh ». Aucun texte de l'API n'est affiché.

## Contrat de l'API

| Cas | Avant | Après |
|---|---|---|
| Transaction d'un autre utilisateur | 201, achat créé | 404 `Transaction not found` |
| `transactionId` pas un UUID | 500 | 404 `Transaction not found` |
| Transaction déjà rattachée (tout projet) | 201, doublon | 409 `This transaction is already linked to a purchase.` |
| Suggestions : transaction rattachée à un autre projet | listée, `alreadyLinked = false` | absente |
| Suggestions : projet sans sous-étape, étape de réaction | aucune | matériaux de la formule |

Forme des ressources inchangée (`StepPurchaseResource`, `PurchaseSuggestionResource`, `PurchaseSuggestionListResource`). Pas de consommateur externe connu.

## Exemples chiffrés

Données fictives. Utilisateur U1, personnages P1 « Pilot One » et P2 « Pilot Two ». Utilisateur U2, personnage P9 « Other Pilot ». Projets de U1 : J1 (courant) et J2 ; projet de U2 : J9. Étape S1 de J1 (fabrication, profondeur 0), S1b de J1 (profondeur 1), S2 de J2.

Rattachement — `POST /api/industry/projects/J1/steps/S1/purchases` :

| # | Données | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | T1 : P1, achat, type 9101, 120 u à 5,25 ISK, jamais rattachée | 201 | 201 ; achat type 9101, quantité 120, prix 5,25, total 630,0, source `esi_wallet`, transaction T1 ; `persist` et `flush` une fois |
| E2 | T1, `quantity` 80 | 201, total 420,0 | inchangé : quantité 80, total 420,0 |
| E3 | T9 : P9 (U2), jamais rattachée | 201, achat créé avec les prix de T9 | **404** `Transaction not found` ; `IndustryStepPurchaseRepository` non lu ; ni `persist` ni `flush` |
| E4 | UUID inconnu (`find` → `null`) | 404 | inchangé : 404 |
| E5 | `transactionId` = `"not-a-uuid"` | 500 | 404 `Transaction not found` ; `CachedWalletTransactionRepository::find` jamais appelé |
| E6 | T2 : P2 (U1), déjà rattachée à un achat de S2 (J2) | 201, T2 comptée dans J1 et J2 | **409** `This transaction is already linked to a purchase.` ; ni `persist` ni `flush` |
| E7 | T3 : P1, déjà rattachée à un achat de S1b (J1) | 201 | 409 |
| E8 | T3 avec `quantity` 10 (rattachement partiel) | 201 | 409 |
| E9 | T9 (P9, U2), déjà rattachée dans J9 | 201 | 404 (R4 : propriété avant unicité) |
| E10 | Achat manuel : type 9103, quantité 7, prix 1000,0 | 201 | inchangé : 201, total 7000,0, source `manual` ; `IndustryStepPurchaseRepository` non lu |

Suggestions — `GET /api/industry/projects/J1/purchase-suggestions`. J1 : S1 (blueprint 9200, produit 9000, profondeur 0), S1b (produit 9101, profondeur 1), S1c (produit 9102, profondeur 1) → types {9101, 9102}. `findBuysByTypeIds(P1, …)` rend dans l'ordre :

| Transaction | Type | Achats existants |
|---|---|---|
| T1 | 9101 | aucun |
| T2 | 9101 | un dans S2 (J2) |
| T3 | 9102 | un dans S1b (J1) |
| T4 | 9102 | deux dans S2 (J2), doublon historique |
| T5 | 9101 | un dans S1b (J1) et un dans S2 (J2), doublon historique |
| T6 | 9102 | un dans une étape de J9 (U2), rattachement historique permis par #285 |

| # | Situation | Aujourd'hui | Attendu |
|---|---|---|---|
| G1 | P1 seul, T1 à T6 | `totalCount` 6 ; `alreadyLinked` vrai pour T3 et T5 seulement | `totalCount` 3 : T1 (`false`), T3 (`true`), T5 (`true`) ; T2, T4, T6 absentes ; `findBy` des achats appelé une fois avec T1 à T6 |
| G2 | Aucune transaction trouvée | `totalCount` 0 | `totalCount` 0 ; `IndustryStepPurchaseRepository` non lu |
| G3 | P1 rend T1, P2 rend T7 (9102, jamais rattachée) | 2 suggestions | 2 : T1 (Pilot One), T7 (Pilot Two) ; un seul `findBy` avec T1 et T7 |

Matériaux SDE (projet sans étape de profondeur > 0) :

| # | Projet | SDE | Aujourd'hui | Attendu |
|---|---|---|---|---|
| M1 | J4 : une étape `reaction`, formule 9300, produit 9301 | 9300, activité 11 : matériaux 9311, 9312 | aucune ligne (`activity_id = 1`) ; `totalCount` 0 ; `findBuysByTypeIds` jamais appelé | `findMaterialsForBlueprints([9300], [1, 11])` ; `findBuysByTypeIds(P1, [9311, 9312])` ; T8 (9311, 40 u) suggérée |
| M2 | J5 : une étape `manufacturing`, blueprint 9200 | 9200, activité 1 : 9101, 9102 | types {9101, 9102} | inchangé |
| M3 | J6 : deux étapes de profondeur 0, fabrication 9200 et réaction 9300 | comme M1 et M2 | types {9101, 9102} | types {9101, 9102, 9311, 9312} |
| M4 | J7 : une étape `copy` seule | — | `totalCount` 0, SDE non lu | inchangé |

Écran (`en.json` sauf mention) :

| # | Données | Attendu |
|---|---|---|
| F1 | « Link » sur T2, `createPurchase` rejette `ApiError(…, 409)` | panneau : « This transaction is already linked to a purchase. Suggestions have been refreshed. » ; `GET …/purchase-suggestions` appelé ; `fetchProject` non appelé ; bouton réactivé |
| F2 | rejet `ApiError(…, 404)` | « The transaction or the step no longer exists. Suggestions have been refreshed. » |
| F3 | rejet `ApiError(…, 500)` | « Failed to link the purchase. » |
| F4 | F1 en `fr.json` | « Cette transaction est déjà rattachée à un achat. Les suggestions ont été rafraîchies. » |
| F5 | F1, puis « Link » sur T1 réussi | message effacé ; projet et suggestions relus (inchangé) |
| F6 | `ShoppingPurchasesPanel` avec `linkError` `null` | aucun `purchase-link-error` |

## Clés i18n

Nouveau bloc `industry.shoppingTab.linkErrors` dans `fr.json` et `en.json` :

| Clé | FR | EN |
|---|---|---|
| `alreadyLinked` | `Cette transaction est déjà rattachée à un achat. Les suggestions ont été rafraîchies.` | `This transaction is already linked to a purchase. Suggestions have been refreshed.` |
| `notFound` | `La transaction ou l'étape n'existe plus. Les suggestions ont été rafraîchies.` | `The transaction or the step no longer exists. Suggestions have been refreshed.` |
| `failed` | `Échec du rattachement de l'achat.` | `Failed to link the purchase.` |

`notFound` couvre aussi « Step not found » et « Project not found » : le front ne distingue pas les trois 404, et le conseil est le même.

## Cas limites

- **Doublons historiques en base** (même transaction dans plusieurs achats, avant ce lot). Pas de migration ni de nettoyage. Les achats existants restent, et comptent toujours dans le coût de chaque projet. Le rattachement d'une telle transaction est refusé (409) tant qu'un seul achat la référence (E6, T4). Dans les suggestions, elle est « Linked » si un de ses achats est dans le projet courant (T5), absente sinon (T4). La supprimer d'un projet (`DELETE` existant) ne la libère que lorsque le dernier achat qui la référence est supprimé. Les retirer est un geste de l'utilisateur, projet par projet.
- **Rattachement historique à la transaction d'un autre utilisateur** (permis par #285). L'achat reste dans le projet de son auteur. Le propriétaire de la transaction ne la voit plus en suggestion (T6) et ne peut pas la rattacher (409). Il ne peut pas non plus retirer l'achat d'un autre. Cas improbable (il faut connaître l'UUID d'une transaction d'un autre) : aucun traitement, à corriger en base si on le rencontre.
- **Projet ou étape supprimé.** Les achats suivent en cascade (`IndustryStepPurchase::$step`, `onDelete: 'CASCADE'` ; étape → projet idem) : la transaction redevient libre.
- **Personnage retiré.** Ses transactions sont supprimées en cascade ; les achats gardent leurs montants avec `transaction = null` (`SET NULL`) et ne bloquent plus rien.
- **Fusion d'étapes** (`MergeStepsProcessor.php:106-107`) : les achats changent d'étape dans le même projet, la transaction reste rattachée au projet. Inchangé.
- **Course entre deux requêtes.** Le contrôle R3 lit puis écrit, sans index unique : deux `POST` simultanés sur la même transaction (deux onglets) peuvent créer deux achats. Le front désactive déjà le bouton de la ligne pendant la requête (`ShoppingPurchasesPanel.vue:142`). Risque faible et visible (deux lignes « Linked purchases ») ; l'index unique le fermerait (voir D4).
- **Transaction de vente** (`isBuy = false`) passée à la main à l'API : acceptée comme aujourd'hui, hors périmètre.

## Hors périmètre

- Index unique sur `industry_step_purchases.transaction_id` (migration) : demande d'abord de dédoublonner les données historiques. À ouvrir en issue (D4).
- Quantité rattachée non bornée par celle de la transaction (`CreatePurchaseProcessor.php:74`, relevé par spec #241) et répartition d'une transaction entre plusieurs projets.
- Transaction de vente acceptée comme achat ; type de la transaction sans rapport avec l'étape.
- `id` / `stepId` de l'URL qui ne sont pas des UUID (500 aujourd'hui, comme les autres routes du module).
- Matériaux bruts des projets à sous-étapes (voir « Blocage : options », Q1).
- `purchasesStore.error` et son texte français en dur (`purchases.ts:40`), jamais affichés : laissés tels quels.

## Décisions retenues en autonomie

- **D1 — 409 pour une transaction déjà rattachée**, 404 pour une transaction inconnue ou d'un autre. Même contrat que `RecordSaleFromWalletTransactionProcessor` (spec #241 B-R4) et les autres conflits du code ; 422 reste aux violations du validateur.
- **D2 — Unicité globale**, quel que soit le projet (même projet, même étape, autre utilisateur) et la quantité : c'est la règle de l'issue, et elle tient en une ligne.
- **D3 — Liées au projet courant : gardées avec `alreadyLinked`, liées ailleurs : absentes.** C'est le test de l'issue (« elle n'apparaît pas dans les suggestions du projet B »). Le badge « Linked » du projet courant reste utile et ne coûte rien.
- **D4 — Pas d'index unique dans ce lot.** La migration échouerait sur les doublons historiques et imposerait un nettoyage qui supprime ou modifie des achats ; le contrôle applicatif suffit au flux réel (un utilisateur, bouton désactivé pendant la requête). Option ouverte : issue « dédoublonner puis indexer `transaction_id` », avec traduction de `UniqueConstraintViolationException` en 409 comme `RecordSaleFromWalletTransactionProcessor.php:90-94`.
- **D5 — Requête SQL brute remplacée par `findMaterialsForBlueprints($ids, [Manufacturing, Reaction])`.** La méthode existe, porte la liste d'activités et se double en test ; `EntityManagerInterface` sort du provider (seul usage). `IN (1, 11)` plutôt qu'une activité par étape : le SDE sépare blueprints et formules, le résultat est le même avec une règle plus simple.
- **D6 — Achats lus par `findBy(['transaction' => $transactions])`** : une requête, aucune méthode de dépôt nouvelle. Doctrine traduit un tableau d'entités en `IN` sur leurs identifiants [TO CONFIRM par un test d'intégration hors lot de nuit].
- **D7 — Messages traduits par statut côté front**, pas le texte de l'API : l'application est bilingue, précédent `StructureConfig.vue:358-361`. Message porté par `ShoppingTab` (qui fait l'appel) et passé en prop au panneau. Pas de bouton fermer : le message s'efface au geste suivant. Pas de maquette : une ligne d'alerte dans une carte existante.
- **D8 — Ordre propriété puis unicité (R4)**, pour ne rien révéler sur la transaction d'un autre.

## Blocage : options

Aucun blocage pour ce lot. Une décision produit reste ouverte pour la suite :

- **Q1 — Matériaux suggérés d'un projet à sous-étapes.** Avec au moins une étape de profondeur > 0, seules les productions intermédiaires sont suggérées (`:52-56`) ; ni les minéraux ni les matériaux des étapes de réaction (moon materials) ne le sont, R7 ne touchant que le repli SDE. Le test de l'issue « projet avec une étape de réaction : ses matériaux sont suggérés » n'est donc rempli que pour un projet sans sous-étape.
  - (a) Garder ce lot sur le repli SDE et ouvrir une issue. **Recommandé**, retenu par défaut : le changement de périmètre ajoute beaucoup de suggestions à tous les projets existants.
  - (b) Suggérer l'union des produits intermédiaires et des matériaux SDE (activités 1 et 11) de toutes les étapes hors copie.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/State/Processor/Industry/CreatePurchaseProcessor.php` | Dépendance `IndustryStepPurchaseRepository` ; constante `ALREADY_LINKED_MESSAGE` ; transaction lue par une méthode privée (`Uuid::isValid`, `find`, `contains` sur les personnages de l'utilisateur → 404, R1, R2) ; `findOneBy(['transaction' => …])` → `ConflictHttpException` (R3) ; reste inchangé (R5) |
| `src/State/Provider/Industry/PurchaseSuggestionProvider.php` | Dépendances `IndustryActivityMaterialRepository` et `IndustryStepPurchaseRepository` à la place d'`EntityManagerInterface` ; repli SDE via `findMaterialsForBlueprints(…, [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value])` (R7) ; transactions de tous les personnages lues d'abord, achats lus en un `findBy`, filtre et `alreadyLinked` par identité d'entité (R6) |
| `frontend/src/components/industry/ShoppingTab.vue` | `purchaseLinkError` ; `catch` dans `linkSuggestion()` : message par statut (`ApiError`, constantes 404 / 409), relecture des suggestions ; effacé au début de `linkSuggestion()` et de `loadSuggestions()` ; prop `link-error` passée au panneau (R8) |
| `frontend/src/components/industry/shopping/ShoppingPurchasesPanel.vue` | Prop `linkError: string \| null` ; alerte `purchase-link-error` sous l'en-tête de la carte des suggestions |
| `frontend/src/i18n/locales/fr.json`, `en.json` | Bloc `industry.shoppingTab.linkErrors` |

Pas de migration, pas de message Messenger, pas de commande à lancer en production. `IndustryStepPurchase` et son dépôt ne changent pas.

## Câblage des tests (tests unitaires)

**Tests existants appelés à casser :**

- `tests/Unit/State/Provider/Industry/PurchaseSuggestionProviderTest.php` (`#[Group('issue-115')]`) : `new PurchaseSuggestionProvider(...)` (l.80-86) passe un `EntityManagerInterface` qui disparaît. Le harnais passe des stubs `IndustryActivityMaterialRepository` (jamais appelé : le projet a des étapes de profondeur 1) et `IndustryStepPurchaseRepository` (`findBy` → `[]`). L'assertion ne change pas : aucune transaction n'y est rattachée.
- Aucun test de `CreatePurchaseProcessor`. Front : `ShoppingPurchasesPanel` est un stub dans les quatre specs `shoppingTab*.spec.ts` ; aucun test n'appelle `createPurchase` ni `linkSuggestion`.

**Tests rouges attendus** (`#[Group('issue-205')]`, `#[Group('issue-285')]` selon la règle) :

- `tests/Unit/State/Processor/Industry/CreatePurchaseProcessorTest.php` (nouveau) : E1 à E10.
  - Harnais : `Security` stub → vrai `User` U1 avec P1 et P2 (`addCharacter`) ; U2 avec P9. `IndustryProjectRepository` stub → vrai `IndustryProject` J1 (`setUser(U1)`) ; `IndustryProjectStepRepository` stub → vraie étape S1 (`J1->addStep`). `CachedWalletTransactionRepository` en mock (`find` → vraie transaction, `expects(never())` pour E5). `IndustryStepPurchaseRepository` en mock (`findOneBy` → `null` ou un vrai achat d'une étape de J2 / J1 ; `expects(never())` pour E3, E9, E10).
  - `EntityManagerInterface` en mock : `persist` capture l'achat (E1, E2, E10), `expects(never())` sur `persist` et `flush` pour E3 à E9. `IndustryResourceMapper` stub (classe non finale) rendant un `StepPurchaseResource`.
  - Exceptions attendues : `NotFoundHttpException` (message `Transaction not found`), `ConflictHttpException` (message `This transaction is already linked to a purchase.`).
- `tests/Unit/State/Provider/Industry/PurchaseSuggestionProviderTest.php` : nouveaux cas G1 à G3 et M1 à M4.
  - `findBuysByTypeIds` par `willReturnCallback` selon le personnage et les types reçus (M1 vérifie `[9311, 9312]`).
  - `IndustryStepPurchaseRepository::findBy` rend de vrais achats (`step->addPurchase`, étapes ajoutées à J1, J2, J9). `expects(never())` pour G2, `expects(once())` pour G1 et G3.
  - `IndustryActivityMaterialRepository::findMaterialsForBlueprints` par callback : ne rend les matériaux de 9300 que si `11` est dans la liste d'activités, ceux de 9200 que si `1` y est ; `expects(never())` pour G1 à G3 (sous-étapes) et M4.
  - Transactions non persistées (id `null`) : l'identité d'objet de R6 est exigée par le harnais.
- `frontend/tests/components/industry/shoppingTabPurchaseLinkError.spec.ts` (nouveau) : F1 à F5. Montage de `ShoppingTab` comme `shoppingTabFailedProducts.spec.ts` (`apiRequest` mocké par URL et méthode, `ShoppingPurchasesPanel` en stub). `useIndustryStore().currentProject` porte une étape de profondeur 0 hors copie (pour `findStepForType`). Émission `linkSuggestion` depuis le stub, puis `props('linkError')` et les appels à `apiRequest`. `fr.json` pour F4.
- `frontend/tests/components/industry/shoppingPurchasesPanelLinkError.spec.ts` (nouveau) : F6 et l'affichage d'un `linkError` non nul (`role="alert"`, texte).

## Glossaire (proposé, à valider)

- Ajout : **Achat** (Purchase) — achat de matériaux enregistré sur une **étape** d'un projet industriel personnel : saisi à la main, ou rattaché à une **transaction wallet** d'achat d'un personnage de l'utilisateur, dont il recopie type, quantité et prix. Il compte dans le coût réel du projet. Une transaction ne sert qu'un achat, tous projets confondus (spec #205) ; seule une transaction de l'utilisateur peut être rattachée (spec #285). Code : `IndustryStepPurchase`, `CreatePurchaseProcessor`, `StepPurchaseResource`.
- Ajout : **Suggestion d'achat** (Purchase suggestion) — transaction wallet d'achat de l'utilisateur sur un matériau du projet, proposée au rattachement ; absente si elle est déjà rattachée à un autre projet, marquée « Linked » si elle l'est au projet courant (spec #205). Code : `PurchaseSuggestionProvider`, `PurchaseSuggestionResource::$alreadyLinked`.
- Modification de **Transaction wallet** (`docs/glossary.md:165`) : ajouter « Une transaction d'achat est rattachée à au plus un **achat** (spec #205). »
