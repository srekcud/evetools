# Spec #241 LOT B (rattachement aux transactions wallet) : phase rouge

Date : 2026-10-29. Branche `fix/group-group-industry`. Rien n'est commité. Spec : `docs/specs/241-group-sale-seller-and-wallet.md`, LOT B. `.claude/state/tdd-phase` non écrit (à la charge de l'orchestrateur).

## ARRÊT (intégration et fonctionnel)

`.claude/scripts/wt-php.sh … integration` refuse : `service "app" is not running` / `DATABASE_URL illisible dans le conteneur app (stack arrêtée ?)`. Aucun autre chemin tenté. Tests d'intégration et fonctionnels écrits, `php -l` OK, **non exécutés**. À lancer stack démarrée (`make test-db`, `make test-integration`, suite Functional) avant le vert.

## Squelettes dans `src/` (signalés)

| Fichier | Squelette |
|---|---|
| `src/Entity/GroupIndustrySale.php` | `getWalletTransaction(): ?CachedWalletTransaction`, `setWalletTransaction(?CachedWalletTransaction): static`, chacun `throw new \LogicException('not implemented')` ; ni propriété ni mapping |
| `src/ApiResource/GroupIndustry/GroupIndustrySaleResource.php` | `public bool $linkedToWalletTransaction = false;` non rempli par `fromSale()` |
| `src/Repository/CachedWalletTransactionRepository.php` | `findUnlinkedSaleTransactions(array $characters, array $typeIds, \DateTimeImmutable $since): array` → `LogicException` |
| `src/ApiResource/Input/GroupIndustry/RecordSaleFromWalletTransactionInput.php` (nouveau) | `string $transactionId`, `?int $quantity = null`, **sans contrainte** |
| `src/ApiResource/GroupIndustry/GroupSaleSuggestionResource.php` (nouveau) | propriétés de B-R3, **sans `#[ApiResource]`** |
| `src/State/Provider/GroupIndustry/GroupSaleSuggestionProvider.php` (nouveau) | constructeur `(Security, GroupIndustryProjectRepository, GroupProjectAccessChecker, CachedWalletTransactionRepository)`, `provide()` → `LogicException` |
| `src/State/Processor/GroupIndustry/RecordSaleFromWalletTransactionProcessor.php` (nouveau) | constructeur `(Security, GroupIndustryProjectRepository, GroupProjectAccessChecker, CachedWalletTransactionRepository, GroupIndustrySaleRepository, EntityManagerInterface, MercurePublisherService)`, `process()` → `LogicException` |
| `frontend/src/stores/group-industry/types.ts` | `GroupSale.linkedToWalletTransaction: boolean`, types `GroupSaleSuggestion` et `RecordSaleFromWalletTransactionInput` (requis par `vue-tsc -b` sur les specs) |
| `frontend/src/stores/group-industry/distribution.ts` | `saleSuggestions`, `loadingSaleSuggestions`, `saleSuggestionsError` ; `fetchSaleSuggestions()` et `recordSaleFromWalletTransaction()` qui lèvent `Error('not implemented')` |
| `frontend/src/components/group-industry/SaleSuggestionsPanel.vue` (nouveau) | prop `projectId`, gabarit `<div />` |

Les deux classes nouvelles portent un docblock `@implements` (générique PHPStan, comme les voisines) : seul « commentaire » ajouté, à retirer si on le refuse.

## Contrats que les tests figent (le vert doit les respecter)

- Provider : un seul appel `findUnlinkedSaleTransactions($user->getCharacters() en tableau, typeIds des items, $project->getCreatedAt())` ; le faux dépôt applique les règles 1-5 de B-R2 (personnage par identité, `isBuy` faux, type, `date >= since`, non rattachée) et trie par date décroissante. Doublons possibles lus dans `$project->getSales()` (aucun dépôt de ventes dans le constructeur). Accès `assertAdminOrOwner()`.
- Processor : transaction lue par `CachedWalletTransactionRepository::find()` ; appartenance = personnage de la transaction parmi `$user->getCharacters()` ; « déjà rattachée » par `GroupIndustrySaleRepository::findOneBy(['walletTransaction' => $transaction])` (le faux échoue sur tout autre critère) ; `UniqueConstraintViolationException` au `flush()` → 409 sans Mercure.
- Ordre des refus : 401, 404 projet, 403, 400 projet terminé, 404 transaction (inconnue ou d'un autre joueur, même achat/déjà rattachée/quantité trop grande), 400 achat / type / date, 400 quantité, 409.
- Messages exacts : `Transaction not found` (404) ; `Cannot record a sale on a completed project` ; `This transaction is a purchase, not a sale.` ; `The type of this transaction is not an item of the project.` ; `This transaction predates the project.` ; `The quantity exceeds the quantity of the transaction.` (processor et `UpdateSaleProcessor`) ; `This transaction is already linked to a sale.` (409) ; `This sale is linked to a wallet transaction.` (B-R6).
- `UpdateSaleProcessor` : constructeur inchangé (DA4) ; refus avant toute modification, 0 flush, 0 Mercure.

## Tests et résultats

### Unitaires PHPUnit (lancés)

| Fichier | Contenu | Rouges | Verts |
|---|---|---|---|
| `tests/Unit/State/Provider/GroupIndustry/SabreBatchWalletTransactions.php` (nouveau trait) | P créé le 2026-10-20T10:00:00+00:00, item Sabre 22456, vente manuelle V, T1-T7 de la spec (prix, quantités, lieux distincts), vente d'un autre projet rattachée à T6, faux `find()` et `findUnlinkedSaleTransactions()` | - | - |
| `tests/Unit/State/Provider/GroupIndustry/SabreBatchSellers.php` | les personnages reçoivent un UUID (réflexion) | - | - |
| `tests/Unit/State/Provider/GroupIndustry/GroupSaleSuggestionProviderTest.php` (nouveau) | O : [T7, T1] ; T1 complète avec `[V]` ; T7 complète sans doublon ; A : T5 seule ; item Ishtar ajouté → [T7, T1, T3] ; deux items Sabre → pas de doublon de suggestion ; borne de date (pile à la création proposée, 1 s avant non) ; T1 rattachée à une vente de P exclue ; doublons = ventes non rattachées de P de même quantité **et** même prix (type indifférent ; 61 000 000, 2 u., 4 u., 61 250 000,01 et vente rattachée exclus) ; vente d'un autre projet jamais doublon ; utilisateur sans transaction → `[]` ; projet terminé lisible ; matrice d'accès (M, en attente, corpo, retiré, inconnu → 403 ; admin, admin d'un projet terminé → accordé) ; 404 projet inconnu ; 401 | 22 | 0 |
| `tests/Unit/State/Processor/GroupIndustry/RecordSaleFromWalletTransactionProcessorTest.php` (nouveau) | L1 (valeurs, persistance, lien, vendeur, auteur, Mercure `sale_recorded`, V inchangée) ; L2 (409, 1 vente) ; T6 rattachée ailleurs 409 ; course (violation d'unicité au flush) 409 sans Mercure ; L3 et bornes (10, 12, 1, `null` → 12) ; L4 13 → 400 ; L5, L7, L8 ; date pile à la création acceptée, 1 s avant refusée ; L6 T5 → 404 ; L11 → 404 ; transaction d'un autre joueur → 404 même achat/rattachée/quantité 13 ; A rattache T5 → A-Main ; L9 ; terminé avant transaction ; quantité avant conflit ; L10 et 4 autres profils → 403 ; projet inconnu ; 401 | 31 | 0 |
| `tests/Unit/State/Processor/GroupIndustry/UpdateSaleProcessorTest.php` | P1, P5 (vendeur O-Alt, date, typeId 22456 identique, typeId 0), prix + quantité, prix 0 → 400 sans modification ; P2 et bornes (2, 3, 1) ; P3 ; P4 (lieu et nom) ; champs de la transaction à `null` acceptés ; transaction disparue → tout se corrige | 13 | 1 (transaction disparue, garde-fou) |
| `tests/Unit/ApiResource/GroupIndustry/GroupIndustrySaleResourceTest.php` | `linkedToWalletTransaction` vrai / faux | 2 | 0 |
| `tests/Unit/ApiResource/Input/GroupIndustry/RecordSaleFromWalletTransactionInputValidationTest.php` (nouveau) | quantité 0, -1, max+1 → `quantity` ; `transactionId` non UUID, numéro ESI, vide, absent → `transactionId` ; acceptés : sans quantité, 10, 13, 1, max, `null` | 7 | 6 |

Suite Unit complète : `Tests: 13464, Assertions: 22996, Errors: 38, Failures: 37, PHPUnit Notices: 254, Notices: 1, Incomplete: 1`. Les 75 sont exactement les rouges ci-dessus (13 + 22 + 2 + 31 + 7). Notices et incomplet préexistants ; aucune notice dans les classes touchées (`--display-*`).

### Intégration (écrits, non exécutés : voir ARRÊT)

| Fichier | Tests |
|---|---|
| `tests/Integration/Repository/CachedWalletTransactionRepositoryTest.php` (nouveau) | T1-T7 en base : O → [T7, T1], A → [T5], deux types → [T7, T1, T3], borne de date, vente de P rattachée exclue, T6 revient après suppression de la vente de Cutlass, **une seule requête**, `[]` sans personnage ni type |
| `tests/Integration/Migrations/GroupIndustrySaleWalletTransactionMigrationTest.php` (nouveau) | `down()`+`up()` : vente existante non rattachée, vendeur et montants gardés ; colonne nullable, FK `cached_wallet_transactions(id)` `SET NULL`, un index **unique** sur `(wallet_transaction_id)` ; `down()` retire tout, vente gardée. Migration trouvée par contenu : seul fichier qui mentionne `group_industry_sales` et `wallet_transaction_id` |
| `tests/Integration/State/Processor/GroupIndustry/SaleFromWalletTransactionTest.php` (nouveau) | L1 en base (lien, vendeur, `venue` null) ; T1 n'est plus proposée, T7 l'est ; L2 → 409, 2 ventes ; index unique (deux ventes sur T1 → `UniqueConstraintViolationException`) ; D1 (T1 reproposée avec `[V]`) ; distribution 367 500 000 / 11 025 000 / 14 700 000 / 341 775 000, puis 183 750 000 / 5 512 500 / 7 350 000 / 170 887 500 |
| `tests/Integration/State/Provider/GroupIndustry/GroupSaleCollectionProviderTest.php` | B-R5 : liste de 4 ventes rattachées = autant de requêtes que 4 non rattachées ; `linkedToWalletTransaction` lu en base ; helper `createProjectWithSalesSoldByDistinctCharacters(..., linkedToWalletTransactions)` |
| `tests/Integration/Service/Account/AccountDeletionServiceTest.php` | X1 : A rattache T5 puis supprime son compte → vente gardée, 2 × 61 000 000, transaction supprimée, lien, vendeur et auteur `null`, liste `[false, null, null, 122 000 000]`. `wallet_transaction_id` ne référence ni `users` ni `characters` : `$seededColumns` inchangé |

### Fonctionnel (écrit, non exécuté)

`GroupSaleApiTest` : GET `sale-suggestions` par O (200, T1 dans `member`) ; M → 403 ; POST `sales/from-wallet-transaction` (201, 2 × 61 250 000, `linkedToWalletTransaction` vrai) ; L11 UUID inconnu → 404 avec `detail` `Transaction not found` (distingue d'une route absente) ; non UUID → 422 `transactionId` ; M avec quantité 0 → 422 avant le 403 ; O avec la transaction de A → 404.

### Frontend (vitest lancé ; build OK ; `frontend/dist` et `tsconfig.tsbuildinfo` restaurés ; eslint propre)

| Fichier | Rouges | Verts |
|---|---|---|
| `frontend/tests/stores/groupDistributionSaleSuggestions.spec.ts` (nouveau) : lecture (chemin, ordre), échec → `[]` + message sans rejet, changement de projet → liste vidée et chargement ; POST avec/sans quantité, vente en tête ; 409 → `rejects` attendu, ventes gardées, `error` | 6 | 0 |
| `frontend/tests/components/group-industry/saleSuggestionsPanel.spec.ts` (nouveau) : colonnes Character/Quantity/Unit Price/Total, titre, badge doublon sur T1 seule, FR, état vide, échec de lecture affiché **sans** prétendre « aucune transaction », POST 10 u. de T7, POST T1 sans quantité, relecture après succès, 409 affiché | 12 | 0 |
| `frontend/tests/components/group-industry/salesTableWalletLink.spec.ts` (nouveau) : badge « Wallet » sur la vente rattachée seule ; correction : prix et vendeur grisés ; vente manuelle : rien grisé ; PATCH `{quantity: 2}` seul | 2 | 2 (garde-fous) |
| Fixtures `GroupSale` (`linkedToWalletTransaction: false`) : `groupDistributionSaleCorrection` (×3), `groupDistributionAnotherProject` (×3), `groupDistributionErrors` (×1), `salesTableScrollContainer` (×2), `salesTableSeller` (helper `sale()`, oublié par la liste de la spec) | 0 | inchangé |

Vitest complet : `Test Files 3 failed | 130 passed (133)`, `Tests 18 failed | 971 passed (989)`. Aucun espace insécable, pas de `.at()`.

**Libellés à poser au vert** (en / fr) : titre `Transactions to link` / `Transactions à rattacher` ; en-têtes exacts `Character`, `Quantity`, `Unit Price`, `Total` (plus `Date`) ; badge `Possible duplicate` / `Doublon possible` ; bouton `Record as sale` / `Enregistrer comme vente` ; vide `No wallet transaction to link` (FR libre) ; un seul `input[type="number"]` par ligne ; erreurs : `saleSuggestionsError` et `store.error`. SalesTable : texte `Wallet` dans la ligne, `<label>` `Unit Price (ISK)` et `Seller` liés par `for` à des champs `disabled` pour une vente rattachée. Non testé, laissé à `/frontend-design` : la mention des ventes visées par le badge, la place du panneau dans `GroupIndustryDetail.vue` (sous `SalesTable`, `isOwnerOrAdmin`).

## Preuve des raisons d'échec

- Sans sonde : `LogicException: not implemented` (provider, processor, accesseurs de l'entité) ; inputs : `[]` au lieu de `['quantity']` / `['transactionId']` ; ressource : `false` au lieu de `true`.
- Sonde A (accesseurs réels sur une propriété non mappée, retirée) : `UpdateSaleProcessorTest` et ressource échouent sur assertion : `'granted'` au lieu de `'400 This sale is linked…'` avec flush et Mercure (P1, P5…), `4` au lieu de `3` (P3), `linkedToWalletTransaction` `false` au lieu de `true` (P2, P4).
- Sonde B (implémentation complète jetable de l'entité, ressource, input, provider, processor, `UpdateSaleProcessor`, **retirée**, squelettes recopiés depuis une sauvegarde et vérifiés par `grep`/`git diff`) : 188 tests des classes touchées verts. 14 mutants manuels tous tués : sans contrôle d'appartenance, `<`→`<=` sur la date, `>`→`>=` sur la quantité (processor et correction), sans vendeur, sans lien, sans conversion de la violation d'unicité, doublons incluant les ventes rattachées, doublons par quantité seule ou prix seul, sans personnages, `since` + 1 s, accès en lecture au lieu d'admin, vendeur corrigeable.
- Sonde front (store, panneau, SalesTable, clés i18n, **retirée**) : 20/20 verts, suite complète 989/989. Sans sonde : `promise rejected "Error: not implemented"`, `no column "Character"`, `expected '' to contain 'Transactions to link'`, `[false, false]` au lieu de `[true, false]` (badge Wallet), `[]` au lieu de `['Unit Price (ISK)', 'Seller']`.

## Ce que le vert doit créer

- **Migration** (non écrite) `migrations/VersionYYYYMMDDHHMMSS.php`, après `Version20261029000000` : seul fichier qui mentionne `wallet_transaction_id`, et **ne doit pas** mentionner `seller_character_id` (sinon `GroupIndustrySaleSellerMigrationTest` en trouve deux). `up()` : `ALTER TABLE group_industry_sales ADD wallet_transaction_id UUID DEFAULT NULL` ; FK `FK_4C462DD8…` `FOREIGN KEY (wallet_transaction_id) REFERENCES cached_wallet_transactions (id) ON DELETE SET NULL NOT DEFERRABLE` ; `CREATE UNIQUE INDEX UNIQ_4C462DD8… ON group_industry_sales (wallet_transaction_id)`. `down()` : FK, index, colonne. Noms de `doctrine:migrations:diff` (`MigrationsReplayTest`). Aucun `UPDATE`.
- **Mapping** : `#[ORM\OneToOne(targetEntity: CachedWalletTransaction::class)]` + `#[ORM\JoinColumn(name: 'wallet_transaction_id', nullable: true, onDelete: 'SET NULL')]`, unidirectionnel.
- **API Platform** : `#[ApiResource]` sur `GroupSaleSuggestionResource` (`GetCollection` `/group-industry/projects/{projectId}/sale-suggestions`, `Link` vers `GroupIndustryProjectResource`, `paginationEnabled: false`, `security` ROLE_USER, `transactionId` identifiant) ; `Post` `/group-industry/projects/{projectId}/sales/from-wallet-transaction` sur `GroupIndustrySaleResource` (input, processor, `Link`).
- Contraintes de l'input (`NotBlank`, `Uuid`, `Range(1, MAXIMUM_QUANTITY)`), `fromSale()`, provider, processor, règles de `UpdateSaleProcessor`, store, panneau, SalesTable, clés i18n.

## Glossaire

`docs/glossary.md` : **Vendeur** mentionne « ou celui de la **transaction wallet** rattachée » ; **Transaction wallet** ajouté en « Comptes et ESI » (texte de la spec).

## Autres tests qui figent la forme touchée

`grep -rln "CachedWalletTransactionRepository\|UpdateSaleProcessor\|GroupIndustrySaleResource\|GroupSale\b\|walletTransaction\|linkedToWalletTransaction" tests frontend/tests` : tous traités ci-dessus, plus `WalletTransactionSyncServiceTest`, `PurchaseSuggestionProviderTest` (doublent le dépôt, méthode ajoutée sans effet), `SaleCorrectionDistributionTest`, `SaleSellerCharacterRemovalTest`, `RecordSaleProcessorTest`, `GroupSaleItemProviderTest`, `GroupSaleCollectionProviderTest` unitaire : rien à changer, verts. Constructeurs DA4 inchangés. `grep -rn "Currently.*Issue241\|CARACTÉRISATION"` : seule occurrence `MarketServiceTest` (#26), hors sujet. Aucun test de caractérisation : exigences a-e de `/characterize` sans objet.

## Message de commit proposé

```
test(group-industry): specify the sale suggestions from the wallet transactions and the sale recorded from one (#241)
```
