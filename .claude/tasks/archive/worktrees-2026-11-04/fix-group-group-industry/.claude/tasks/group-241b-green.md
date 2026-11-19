# Spec #241 LOT B : phase verte

Rien commité. `tests/` et `frontend/tests/` non modifiés.

## Résultats

- Unit complète : `Tests: 13464, Assertions: 23050, PHPUnit Notices: 254, Notices: 1, Incomplete: 1` (0 erreur, 0 échec).
- deptrac : 0 violation, 41 ignorées (une ligne `skip_violations` ajoutée, voir ci-dessous).
- PHPStan fichiers touchés : seul bruit `Uuid|null` sur `toRfc4122()` (connu, présent aussi dans `UpdateSaleProcessor` avant ce lot). Les deux `@implements` sont indispensables (`missingType.generics` sinon) et sont gardés.
- `doctrine:schema:validate --skip-sync` : mapping OK. `debug:router` : `POST .../sales/from-wallet-transaction` et `GET .../sale-suggestions` présentes.
- Frontend : vitest 133 fichiers / 989 tests verts (exit 0), lint 0 erreur (117 warnings préexistants hors fichiers touchés), build OK, `dist` et `tsconfig.tsbuildinfo` restaurés. Pas d'espace insécable.
- Intégration et fonctionnel : non lancés (stack arrêtée), relus contre le code.

## Backend

- Migration `migrations/Version20261029010000.php` : colonne `wallet_transaction_id` UUID nullable, FK `FK_4C462DD8924C1837` vers `cached_wallet_transactions (id)` `ON DELETE SET NULL`, index unique `UNIQ_4C462DD8924C1837` ; `down()` retire FK, index, colonne. Noms calculés comme Doctrine (crc32 de `group_industry_sales` puis `wallet_transaction_id`). Ne cite pas `seller_character_id`.
- `GroupIndustrySale` : `OneToOne` unidirectionnel + accesseurs réels.
- `CachedWalletTransactionRepository::findUnlinkedSaleTransactions()` : une requête (jointure `character`, jointure gauche sur `GroupIndustrySale`, `s.id IS NULL`), tri date décroissante, `[]` sans personnage ni type.
- `GroupIndustryProject::getItemTypeName(int): ?string` (nouveau, partagé par provider et processor : nom du premier item du type).
- `GroupSaleSuggestionResource` (`#[ApiResource]`, `GetCollection`, `Link`), `GroupSaleSuggestionProvider` (`final readonly`, `ResolvesSaleOfProject`, `assertAdminOrOwner`).
- `RecordSaleFromWalletTransactionInput` (`NotBlank`, `Uuid`, `Range`), `RecordSaleFromWalletTransactionProcessor` (`final readonly`) : ordre 401, 404 projet, 403, 400 terminé, 404 transaction (inconnue ou d'un personnage hors `$user->getCharacters()`), 400 achat / type / date, 400 quantité, 409 (déjà rattachée ou `UniqueConstraintViolationException` au flush, sans Mercure). `Post` ajouté sur `GroupIndustrySaleResource`, `linkedToWalletTransaction` rempli par `fromSale()`.
- `UpdateSaleProcessor` : constructeur inchangé ; refus 400 avant toute modification si la vente est rattachée et que `typeId`, `unitPrice`, `soldAt` ou `sellerCharacterId` est fourni, ou si la quantité dépasse celle de la transaction.
- `deptrac.yaml` : ligne `RecordSaleFromWalletTransactionProcessor -> GroupProjectAccessChecker` dans `skip_violations`, comme les autres processors Group Industry.

## Frontend

- Store `distribution.ts` : `fetchSaleSuggestions()` via `fetchForProject`, `openProject()` vide les suggestions, `recordSaleFromWalletTransaction()` ; `recordSale` et lui partagent `postSale()`.
- `SaleSuggestionsPanel.vue` (clés `groupIndustry.saleSuggestions.*`), monté dans `GroupIndustryDetail.vue` sous `SalesTable`, visible pour propriétaire/admin et projet non terminé.
- `SalesTable.vue` : badge `groupIndustry.sales.walletBadge`, prix unitaire et vendeur désactivés pour une vente rattachée (le formulaire n'a ni champ type ni champ date).
- i18n en/fr : `groupIndustry.sales.walletBadge`, `groupIndustry.saleSuggestions.*`.

## Points à noter

- `store.error` s'affiche dans `SalesTable` et dans le panneau : une erreur de rattachement apparaît deux fois sur la page (le test du panneau impose son affichage dans le panneau seul).
- La mention des ventes visées par le badge « Doublon possible » n'est qu'un `title` (laissé à `/frontend-design`).
- Le bloc de publication Mercure `sale_recorded` est dupliqué entre `RecordSaleProcessor` et le nouveau processor (comme `sale_updated`) ; non factorisé pour ne pas toucher les constructeurs et classes existants.
- Les routes `GET /api/group_sale_suggestions/{transactionId}` auto-générées existent comme pour les ressources voisines.
- À lancer stack démarrée : `make test-db`, `make test-integration`, suite Functional ; après déploiement, vérifier la migration (`doctrine:migrations:status`).

## Message de commit proposé

```
feat(group-industry): record a sale from a wallet transaction and suggest the unlinked ones (#241)
```
