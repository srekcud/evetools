# Spec #241 LOT A (vendeur d'une vente) : phase verte

Date : 2026-10-29. Branche `fix/group-group-industry`. Rien n'est commité, `tests/` et `frontend/tests/` intacts.

## Fichiers

- Migration `migrations/Version20261029000000.php` : `up()` ajoute `seller_character_id UUID DEFAULT NULL`, la FK `FK_4C462DD8449E3089` vers `characters (id)` `ON DELETE SET NULL NOT DEFERRABLE` et l'index `IDX_4C462DD8449E3089` ; `down()` les retire dans l'ordre inverse. Aucun `UPDATE`. Noms calculés comme Doctrine (crc32 du nom de table puis de la colonne, préfixe `4C462DD8` identique aux FK voisines). Seul fichier de `migrations/` qui cite `seller_character_id`.
- `src/Entity/GroupIndustrySale.php` : propriété `$seller` (`ManyToOne Character`, `JoinColumn(name: 'seller_character_id', nullable: true, onDelete: 'SET NULL')`), accesseurs réels à la place des squelettes.
- `src/Service/GroupIndustry/ResolvesSellerOfUser.php` (trait, même style que `ResolvesSaleOfProject`) : résout `sellerCharacterId` parmi `$user->getCharacters()`, `null` si absent, sinon 400 `The seller must be one of your characters.`. Utilisé par les deux processors (pas de duplication).
- `RecordSaleProcessor` : vendeur résolu après l'accès et le contrôle du projet terminé, avant toute création. `UpdateSaleProcessor` : résolu avant `applyCorrection()`, donc un 400 ne modifie rien, aucun flush ni Mercure ; `null` laisse le vendeur inchangé.
- `RecordSaleInput`, `UpdateSaleInput` : `#[Assert\Positive]` sur `sellerCharacterId`.
- `GroupIndustrySaleResource::fromSale()` : `sellerCharacterId` (`getEveCharacterId()`) et `sellerCharacterName` (`getName()`), `null` ensemble.
- `GroupIndustrySaleRepository::findByProjectForListing()` : jointures `s.seller` et `seller.eveToken` ajoutées au select (A-R6).
- Front `SalesTable.vue` : colonne Vendeur (`Vendeur inconnu` si `null`), case « Mes ventes » (visible de tout lecteur, total filtré « Total, mes ventes », état vide dédié, store intact), champ Vendeur du formulaire (option « Inconnu » d'abord, sans présélection ; prérempli en correction si le vendeur est un personnage du lecteur ; `sellerCharacterId` envoyé seulement si choisi et différent). Clés `groupIndustry.sales.{sellerColumn,unknownSeller,mySales,totalMySales,noMySales,unknownSellerOption}` en `en.json` et `fr.json` ; FR : « Aucune vente de vos personnages », « Inconnu ».
- Les types front (`types.ts`) et le glossaire étaient déjà posés au rouge.

## Résultats

- Unit : `Tests: 13382, Assertions: 22952, PHPUnit Notices: 254, Notices: 1, Incomplete: 1` (aucun échec ni erreur ; notices et incomplet préexistants).
- deptrac : `Violations 0`, `Skipped violations 40` (aucune ligne `skip_violations` ajoutée).
- PHPStan sur les fichiers touchés : 5 erreurs, toutes `Cannot call method toRfc4122() on Uuid|null` (bruit d'environnement connu, lignes préexistantes).
- Vitest complet : `Test Files 130 passed`, `Tests 969 passed`, code 0. Lint : 0 erreur (117 avertissements préexistants). Build OK ; `frontend/dist` et `tsconfig.tsbuildinfo` restaurés.

## Non exécuté (stack arrêtée)

Intégration et fonctionnel relus contre le code, non lancés :
- Migration : le test applique `down()` puis `up()` via `getSql()`, attend colonne `YES` nullable, FK `characters.id` `SET NULL`, un index dont la définition contient `(seller_character_id)`, et `down()` sans colonne ni FK ni index. Conforme. À lancer avec la suite : `MigrationsReplayTest` compare la migration au mapping (noms FK/IDX calculés à la main, à confirmer).
- A11 / A12 : reposent sur le `SET NULL` en base ; `DeleteCharacterProcessor` fait un `remove` simple, rien à ajouter côté PHP.
- Fonctionnel : 422 par `Assert\Positive` avant l'accès ; admin avec son main → 201 avec `sellerCharacterName` ; propriétaire avec le main de l'admin → 400.

À lancer stack démarrée : `make test-db`, `make test-integration`, suite Functional.

## Déploiement

`make deploy` joue la migration ; vérifier `doctrine:migrations:status` en production. Aucune commande de rattrapage.

## Message de commit proposé

```
feat(group-industry): record the seller of a sale and filter the sales of the reader's characters (#241)
```
