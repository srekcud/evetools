# #1460 phase rouge, partie BACK

## Tests écrits (12)

- `tests/Unit/Service/Planetary/PlanetaryInputNeedsCalculatorTest.php` (+1) : `testEachLaunchpadRowCarriesTheEveCharacterIdAndTheNameOfTheCharacterOfItsColony` (règle 1, deux personnages distincts).
- `tests/Unit/State/Provider/Planetary/PlanetarySupplyHoldersProviderTest.php` (8) : règles 2 à 5.
  - quantités au lieu d'origine pour les seuls types des feeds (dédoublonnés entre launchpads), tri par nom, `homeLocationId`/`homeLocationName`, 0 non listé, types hors feeds et autre lieu ignorés (assertion sur les requêtes d'assets) ;
  - jeton absent ou sans scope assets : `unknown`/`missing_assets_scope`, `quantities: []`, aucune requête, `stale` false même avec lastSyncAt ancien et assets en base ;
  - `lastSyncAt` null : `assets_never_read`, `assetsReadAt` null (assertArrayHasKey + assertNull) ;
  - ordre des raisons : scope avant lecture ;
  - 30 h : `known`, `stale` true, `assetsReadAt` = `2026-11-27T06:00:00+00:00`, quantités conservées ;
  - borne : 24 h exactement non périmé, 24 h + 1 s périmé ;
  - lieu non réglé : tous les personnages avec statut/raison, `quantities: []`, zéro requête d'assets ;
  - anonyme : `UnauthorizedHttpException` (vert dès le squelette).
- `tests/Functional/Api/Planetary/PlanetarySupplyHoldersApiTest.php` (3) : 401 anonyme ; E9 + E10 (personnage d'un autre utilisateur absent, Bacteria 1 000 dans un conteneur à la racine du lieu d'origine + 200 dans une autre station = 1 000, type hors feeds ignoré, personnage sans jeton `unknown`, `assetsReadAt` ISO, null explicites) ; autre utilisateur sans lieu d'origine : seulement son personnage, `homeLocationId` null, `quantities: []`.

E10 : `AssetBrowseRepositoryTest` (Integration) couvre déjà `quantitiesByRootLocation` avec conteneurs et sous-conteneurs (#1395) ; E10 est donc vérifié de bout en bout dans le test fonctionnel (base réelle), pas dupliqué en Integration.

## Squelette ajouté dans src/ (autorisé)

- `src/ApiResource/Planetary/PlanetarySupplyHoldersResource.php` : `GET /planetary/supply-holders`, shortName `PlanetarySupplyHolders`, `skip_null_values: false`, propriétés `id = 'supply-holders'`, `homeLocationId`, `homeLocationName`, `characters` (liste de tableaux : `characterId`, `characterName`, `status`, `unknownReason`, `assetsReadAt`, `stale`, `quantities`).
- `src/State/Provider/Planetary/PlanetarySupplyHoldersProvider.php` : constructeur figé par les tests (Security, PlanetaryColonyRepository, PlanetaryInputNeedsCalculator, AssetBrowseRepository, ClockInterface), 401 si non connecté, renvoie sinon une ressource vide.
- Choix de nom : la liste s'appelle `characters` (la spec dit « une entrée par personnage »). Le front doit lire ce nom.

## Sorties

Unit (`wt-php.sh . unit`) : 8 échecs sur 9 tests ciblés, tous sur assertion métier (pas de classe manquante).
- calculateur : `Failed asserting that an array has the key 'characterId'` ;
- provider : `null is identical to 60003760` (homeLocationId), `actual size 0 matches expected size N` (characters vide), liste de noms attendue `['Alpha','Bravo','Hold']` reçue `[]`.
- 1 vert : l'anonyme (le squelette le gère déjà).
Dossiers `tests/Unit/State/Provider/Planetary`, `tests/Unit/Service/Planetary` : 633 tests, seuls ces 8 échecs. `tests/Unit/ApiResource` : 489 verts.

## Non exécuté : test fonctionnel

`wt-php.sh . integration …` refuse : « Le worktree modifie migrations/ » (la branche porte des migrations absentes de `main` local, `Version20261126213000` et `Version20261127230000`). Arrêt comme demandé ; le fichier est vérifié par `php -l` et listé par phpunit (3 tests), mais pas encore vu rouge. À lancer par la session principale ou avec un `TEST_TOKEN` fourni par Sylvain. Attendu : 401 déjà vert, les 2 autres rouges (clé `characters` absente).

## Tests exhaustifs voisins (5a)

Cherché : listes/comptes de ressources, routes (`tests/Unit/ApiResource/*`, vert), `PlanetaryStockResource`/`planetary/stock`, `Currently.*Issue1460`, formes de `launchpads[]` : aucun test ne fige la forme exacte, rien à modifier. Les fichiers front (`frontend/src/utils/planetarySupply.ts`, `frontend/tests/...`) modifiés dans le worktree ne sont pas de mon fait.

## Points à trancher à l'implémentation

- `assetsReadAt` d'un personnage `unknown`/`missing_assets_scope` avec un `lastSyncAt` ancien : non assertée (spec muette) ; mon test fonctionnel n'attend `null` que pour un personnage sans lastSyncAt.
- Ordre des `quantities` : non figé (comparaison canonique) sauf à un seul élément.
- Format de date : `DateTimeImmutable::format('c')`.
