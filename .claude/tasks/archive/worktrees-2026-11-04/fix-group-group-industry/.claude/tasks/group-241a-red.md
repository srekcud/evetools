# Spec #241 LOT A (vendeur d'une vente) : phase rouge

Date : 2026-10-29. Branche `fix/group-group-industry`. Rien n'est commité. Spec : `docs/specs/241-group-sale-seller-and-wallet.md`, LOT A seulement (aucun test sur le LOT B).

## ARRÊT (intégration et fonctionnel)

`.claude/scripts/wt-php.sh … integration` refuse de démarrer : `service "app" is not running` / `DATABASE_URL illisible dans le conteneur app (stack arrêtée ?)`. Aucun autre chemin tenté. Les tests d'intégration et fonctionnels sont écrits et passent `php -l`, **non exécutés** : à lancer stack démarrée (`make test-db` puis `make test-integration`, et la suite Functional) avant le vert.

## Squelettes dans `src/` (signalés)

| Fichier | Squelette | Pourquoi |
|---|---|---|
| `src/Entity/GroupIndustrySale.php` | `getSeller(): ?Character` et `setSeller(?Character): static`, chacun `throw new \LogicException('not implemented')` ; aucune propriété ni mapping | Les tests appellent ces méthodes ; sans elles, erreur « méthode inconnue » |
| `src/ApiResource/Input/GroupIndustry/RecordSaleInput.php`, `UpdateSaleInput.php` | `public ?int $sellerCharacterId = null;`, **sans contrainte** | Sinon propriété dynamique (dépréciation) et aucune violation lisible |
| `src/ApiResource/GroupIndustry/GroupIndustrySaleResource.php` | `public ?int $sellerCharacterId = null; public ?string $sellerCharacterName = null;`, **non remplis par `fromSale()`** | Pour que les assertions échouent sur la valeur (`null`) et non sur une propriété absente |
| `frontend/src/stores/group-industry/types.ts` | `GroupSale` : `sellerCharacterId: number \| null`, `sellerCharacterName: string \| null` ; `RecordSaleInput` et `UpdateSaleInput` : `sellerCharacterId?: number` | `vue-tsc -b` vérifie `tests/` : les 8 fixtures typées et la nouvelle spec ne compilent pas sans ces champs (A-R9 : « suivent l'API ») |

Reste au vert : contraintes `#[Assert\Positive]`, mapping `ManyToOne` + accesseurs réels, résolution dans les deux processors, `fromSale()`, jointure du repository, migration, front (colonne, filtre, champ), clés i18n.

## Fichiers et résultats

### Unitaires PHPUnit (lancés)

| Fichier | Changement | Rouges | Verts (garde-fous) |
|---|---|---|---|
| `tests/Unit/ApiResource/Input/GroupIndustry/RecordSaleInputValidationTest.php` | A4 (0, -5 → `['sellerCharacterId']`) ; acceptés : 2120000002, 99999999, 1, `null`, absent | 2 | 5 |
| `tests/Unit/ApiResource/Input/GroupIndustry/UpdateSaleInputValidationTest.php` | A4 ; acceptés : 2120000011, 1, `null` | 2 | 3 |
| `tests/Unit/State/Processor/GroupIndustry/RecordSaleProcessorTest.php` | A1 (O-Alt, total 183 750 000, auteur O-Main), main d'O, A2 (`null`/`null`), admin avec son personnage, refus 400 sans `persist` ni Mercure (A3, A5, admin → alt d'O, admin → personnage de M), M → 403 (A6), projet terminé → refus « terminé » avant le vendeur, données `sale_recorded` inchangées (A-R10) | 8 | 3 |
| `tests/Unit/State/Processor/GroupIndustry/UpdateSaleProcessorTest.php` | A7 (A-Main, total 1 800 000 inchangé, 1 flush), données `sale_updated` inchangées, O déplace la vente d'A-Main vers O-Alt, A8 (`null` et correction du seul prix : vendeur gardé), A9 (main d'O, inconnu, main d'O + nouveau prix : 400, vente inchangée, 0 flush, 0 Mercure), M → 403, projet terminé → refus « terminé » | 7 (9 cas) | 3 |
| `tests/Unit/ApiResource/GroupIndustry/GroupIndustrySaleResourceTest.php` (nouveau) | `fromSale()` : vendeur et auteur distincts ; `null` ensemble ; nom du personnage (pas `PilotName` : compte sans main → « A-Main », pas « Unknown ») | 3 | 0 |
| `tests/Unit/State/Provider/GroupIndustry/GroupSaleCollectionProviderTest.php` | S1-S4 listés avec vendeur (O-Main, O-Alt, A-Main, `null`) | 1 | 0 |
| `tests/Unit/State/Provider/GroupIndustry/GroupSaleItemProviderTest.php` | vente à corriger avec son vendeur O-Alt | 1 | 0 |
| `tests/Unit/State/Provider/GroupIndustry/SabreBatchSellers.php` (nouveau trait) | O-Main 2120000001, O-Alt 2120000002, A-Main 2120000011, M-Main 2120000021, 99999999 inconnu ; `withCharacters()` configure `getCharacters()` et `getMainCharacter()` du stub `User` | - | - |

Suite Unit complète : `Tests: 13382, Assertions: 22930, Errors: 12, Failures: 12, PHPUnit Notices: 254, Notices: 1, Incomplete: 1`. Les 24 sont exactement les rouges ci-dessus (regroupés par classe : 3 + 2 + 2 + 8 + 7 + 1 + 1). Les classes touchées ne lèvent aucune notice PHPUnit (`--display-phpunit-notices`) ; notice et incomplet préexistants.

Constructeurs inchangés (DA4) : `RecordSaleProcessorTest`, `UpdateSaleProcessorTest`, `GroupSaleCollectionProviderTest` (unitaire et intégration), `SaleCorrectionDistributionTest`, `AccountDeletionServiceTest::listSalesOf` n'ont pas été touchés dans leur construction.

### Intégration (écrits, non exécutés : voir ARRÊT)

| Fichier | Tests |
|---|---|
| `tests/Integration/Migrations/GroupIndustrySaleSellerMigrationTest.php` (nouveau) | A10 : vente existante, `down()` puis `up()` → `seller_character_id` `NULL`, auteur et montants gardés (aucune déduction depuis `recordedBy`) ; `up()` → colonne nullable, FK vers `characters(id)` en `SET NULL`, un index ; `down()` → colonne, FK et index retirés, vente gardée. La migration est trouvée par son contenu : **le seul fichier de `migrations/` qui mentionne `group_industry_sales` et `seller_character_id`** (nom inconnu avant le vert) |
| `tests/Integration/State/Processor/GroupIndustry/SaleSellerCharacterRemovalTest.php` (nouveau) | A11 par le vrai `DeleteCharacterProcessor` (seule `Security` doublée) : vente d'O-Alt gardée, vendeur `null`, 3 × 61 250 000 = 183 750 000 ; vente d'O-Main garde son vendeur ; revenu de distribution 303 750 000 avant et après ; liste `[null, null, 'O-Main', 183 750 000]` puis `[2120000001, 'O-Main', 'O-Main', 120 000 000]` |
| `tests/Integration/State/Provider/GroupIndustry/GroupSaleCollectionProviderTest.php` | A-R6 : mêmes requêtes pour 2 et 4 vendeurs distincts, au plus 4 requêtes pour 4 vendeurs ; S1-S4 lus en base avec vendeur ; `addSale()` renvoie la vente |
| `tests/Integration/Service/Account/AccountDeletionServiceTest.php` | `seedPersonalData()` sème un projet du compte avec une vente vendue par le personnage ; `$seededColumns` gagne `'group_industry_sales.seller_character_id' => 1` (ordre alphabétique respecté) ; nouveau test A12 : vente de Brann vendue par le main de Kaelen (admin), Kaelen supprime son compte → vente gardée, vendeur et auteur `null`, 183 750 000, distribution et liste inchangées |

Raisons d'échec attendues aujourd'hui : `LogicException: not implemented` (`setSeller`) en préparation ; migration : `assertCount(1, [])` ; FK test : la colonne absente de `rowsReferencing()`.

À noter pour le vert : dans `seedPersonalData()`, le projet semé par Kaelen n'a aucun autre membre accepté et part avec ses ventes (cascade sur `project_id`) ; c'est A12 qui exerce le `SET NULL` sur `seller_character_id`. Le test de migration exécute du DDL dans la transaction de dama (comme `GroupIndustryJobQuantitiesInUnitsMigrationTest`) ; si PostgreSQL signale « pending trigger events », c'est le test qui est à revoir, pas la migration.

### Fonctionnel (écrit, non exécuté : base requise)

`tests/Functional/Api/GroupIndustry/GroupSaleApiTest.php` : POST `sellerCharacterId: 0` → 422 sur `sellerCharacterId`, rien de créé ; membre avec `-5` → 422 avant le 403 (cas limite « ordre des erreurs ») ; admin avec son main → 201, `sellerCharacterId`, `sellerCharacterName` « Brann Holt » dans le JSON ; propriétaire avec le main de l'admin → 400, rien de créé ; PATCH `0` → 422, vente inchangée.

### Frontend (vitest lancé ; `npm run build` OK, `frontend/dist` et `tsconfig.tsbuildinfo` restaurés)

| Fichier | Changement | Rouges | Verts |
|---|---|---|---|
| `frontend/tests/components/group-industry/salesTableSeller.spec.ts` (nouveau) | colonne « Seller » (O-Main, O-Alt, A-Main, « Unknown seller »), aussi pour un membre non admin ; FR « Vendeur » / « Vendeur inconnu » ; filtre « My sales » : O → S1, S2, `Total, my sales \| 5 \| 303,750,000` ; A → S3, `… \| 1 \| 62,000,000` ; M → état vide dédié, ni ligne ni total ; vente inconnue jamais à moi ; retour aux 4 ventes et `Total \| 7 \| 425,250,000` ; le store garde ses 4 ventes ; FR « Mes ventes », « Total, mes ventes », 303 750 000 ; champ « Seller » : options `Unknown, O-Main, O-Alt`, « Unknown » sélectionné ; POST sans `sellerCharacterId` si Inconnu, avec 2120000002 si O-Alt ; correction préremplie O-Alt (vente d'O-Alt) ou « Unknown » (vente d'A-Main) ; PATCH `{}` si vendeur gardé, `{unitPrice}` seul si Inconnu gardé, `{sellerCharacterId: 2120000001}` et `{sellerCharacterId: 2120000002}` si choisi | 16 | 4 |
| `groupDistributionErrors.spec.ts`, `groupDistributionAnotherProject.spec.ts` (×3), `groupDistributionSaleCorrection.spec.ts` (×3), `salesTableScrollContainer.spec.ts` (×2) | les 8 fixtures `GroupSale` reçoivent `sellerCharacterId: null`, `sellerCharacterName: null` | 0 | inchangé |

Suite vitest complète : `Test Files 1 failed | 129 passed (130)`, `Tests 16 failed | 953 passed (969)` : les 16 rouges de la nouvelle spec. Aucun espace insécable littéral (`grep -P '\x{00A0}|\x{202F}'` vide : `normalized()` ramène tout blanc à une espace) ; pas de `.at()`.

**Libellés que le vert doit poser** (en.json / fr.json) : en-tête de colonne `Seller` / `Vendeur` ; `Unknown seller` / `Vendeur inconnu` ; case à cocher dont le `<label>` vaut exactement `My sales` / `Mes ventes` (libellé `for` ou case dans le label) ; première cellule du total filtré `Total, my sales` / `Total, mes ventes` ; état vide `No sales by your characters` (FR libre, non testé : proposition « Aucune vente de vos personnages ») ; champ du formulaire `<label>` `Seller` lié à un `<select>`, option `Unknown` en premier (FR « Inconnu », non testé). Les en-têtes en dur `Quantity` et `Revenue`, le bouton `Record Sale`, `Save`, `Edit` et les placeholders sont lus par la spec : ne pas les renommer dans ce lot.

## Preuve des raisons d'échec

- Inputs : `[]` au lieu de `['sellerCharacterId']` (4 cas).
- Sonde temporaire (accesseurs réels sur une propriété non mappée, **retirée**, squelette `LogicException` rétabli, vérifié par `grep`) sur les cinq classes : tous les rouges deviennent des échecs d'assertion : `null, null` au lieu de `2120000002, 'O-Alt'` (et 2120000001, 2120000011) pour A1, main d'O, admin, A7, O → O-Alt, A8 (×2), ressource (×2), liste, item ; `'granted', 1` au lieu de `'400 The seller must be one of your characters.', 0, []` pour A3, A5 et les deux cas admin ; `'granted'` au lieu du 400 pour A9 (×3), avec `950000.0` au lieu de `900000.0` sur le cas « nouveau prix » (correction appliquée en partie). Sous la sonde, A2 et « vente sans vendeur » de la ressource passent : garde-fous.
- Sans sonde : `LogicException: not implemented` là où la préparation pose un vendeur (A8, A9, O → O-Alt, ressource, liste, item) ou relit celui de la vente persistée (A2) ; échecs d'assertion ailleurs (voir ci-dessus).
- Vitest : `no column "Seller"` (2), `no column "Vendeur"` (1), `no label "My sales"` (6), `no label "Mes ventes"` (1), `no label "Seller" in the sale form` (6). Les 4 verts : total du projet sans filtre, POST sans vendeur, PATCH `{}` et PATCH `{unitPrice}` (comportement actuel déjà conforme : garde-fous contre un envoi de `sellerCharacterId` non choisi).

## Ce que le vert doit créer : migration (non écrite)

- Fichier `migrations/VersionYYYYMMDDHHMMSS.php` (après `Version20261023030000`), description du type « Record the seller character of a group industry sale ». Doit être **le seul** fichier qui mentionne `seller_character_id` (le test le trouve ainsi).
- `up()` : `ALTER TABLE group_industry_sales ADD seller_character_id UUID DEFAULT NULL` ; `ALTER TABLE group_industry_sales ADD CONSTRAINT FK_… FOREIGN KEY (seller_character_id) REFERENCES characters (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE` ; `CREATE INDEX IDX_… ON group_industry_sales (seller_character_id)`. Noms FK/IDX : ceux de `doctrine:migrations:diff` (le `MigrationsReplayTest` compare au mapping).
- `down()` : `DROP CONSTRAINT FK_…`, `DROP INDEX IDX_…`, `DROP seller_character_id`.
- Aucun `UPDATE` de rattrapage, aucune commande console (A-R2).
- Mapping : `#[ORM\ManyToOne(targetEntity: Character::class)]`, `#[ORM\JoinColumn(name: 'seller_character_id', nullable: true, onDelete: 'SET NULL')]` sur `GroupIndustrySale::$seller`.
- Repository : `findByProjectForListing()` ajoute `seller` et `sellerToken` (`leftJoin('s.seller', …)`, `leftJoin('seller.eveToken', …)`).

## Glossaire (`docs/glossary.md`)

- Ajouts dans « Industrie collaborative » : **Vente** (texte de la spec) et **Vendeur**.
- **Écart volontaire** : la définition de **Vendeur** omet « ou celui de la **transaction wallet** rattachée » (LOT B, pas encore dans le code ni dans le glossaire) ; à ajouter au rouge du LOT B avec le terme **Transaction wallet**. Elle cite en revanche le compte supprimé parmi les causes d'un vendeur inconnu (cas limite de la spec).
- **Compte supprimé** : « conservées sans auteur ni vendeur ».

## Autres tests qui figent la forme touchée

`grep -rn "recordedByCharacterName\|fromSale\|findByProjectForListing\|RecordSaleInput\|UpdateSaleInput\|GroupIndustrySaleResource" tests frontend/tests` : tous listés plus haut, plus `SaleCorrectionDistributionTest` et `MemberAndContributionListAccessTest` (aucune assertion sur l'ensemble des propriétés, rien à changer). `grep -rn "Currently.*Issue241\|CARACTÉRISATION"` : aucun test #241 ; la seule occurrence (`MarketServiceTest`, #26) est hors sujet. Aucun test de caractérisation dans ce lot : exigences a-e de `/characterize` sans objet.

## Proposition de message de commit (rouge)

```
test(group-industry): pin the seller of a sale, its scoping to the recorder's characters and the my sales filter (#241)
```
