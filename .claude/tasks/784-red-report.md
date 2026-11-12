# 784 phase rouge : rapport

## Fichiers de test (51 tests backend, 7 front)

- tests/Unit/State/Processor/Assets/ResolveAssetItemNameProcessorTest.php (20) : le processeur contre un vrai `AssetsService` dont seul `EsiClient` est stubbé (la frontière). Périmètre (propre perso, autre utilisateur, autre corporation, division masquée, UUID mal formé / inconnu, sans personnage principal, anonyme), 4 refus 422 (pas un singleton, drapeau inconnu, déjà nommé, bureau), jeton et endpoint (perso / Director), 409 (sans Director, sans jeton), named, unnamed (None, vide, aucune entrée), 502.
- tests/Unit/Service/ESI/AssetsServiceSingleItemNameTest.php (7) : un seul id posté, endpoint par type de propriétaire, l'exception ESI remonte, `name` absent / entier / flottant / null en erreur explicite.
- tests/Unit/Service/ESI/AssetsServiceSingletonFlagTest.php (7) : `AssetDto::$isSingleton` rempli (personnage et corporation), `is_singleton` absent / chaîne / entier / flottant / null en erreur explicite.
- tests/Unit/Entity/CachedAssetItemNameEligibilityTest.php (8) : table de vérité de `canResolveItemName()`.
- tests/Unit/Service/Sync/AssetsSyncServiceTest.php (+2) : les deux méthodes de sync persistent le drapeau.
- tests/Unit/State/Provider/Assets/CorporationAssetsProviderTest.php (+1) : `isSingleton` et `canResolveItemName` exposés.
- tests/Functional/Api/Assets/ResolveAssetItemNameApiTest.php (6) : 200 named (nom persisté, `[itemId]` posté), 200 unnamed (`itemName` clé présente et null), 502, 404 autre utilisateur, 422 pile de Tritanium, GET des assets perso exposant les deux champs (clé puis `assertNull` pour le drapeau inconnu).
- frontend/tests/components/assets/assetLocationGroupResolveItemName.spec.ts (7) ; fixture typée de assetLocationGroupUnknownTypeName.spec.ts mise à jour.

## Squelettes en src/ (minimaux, à remplacer)

- AssetDto : `?bool $isSingleton = null` en dernier paramètre.
- CachedAsset : champ ORM `isSingleton` (boolean nullable, SANS migration), `isSingleton()`, `setIsSingleton()`, `canResolveItemName()` qui renvoie `false`.
- AssetItemResource : `?bool $isSingleton`, `bool $canResolveItemName` (valeurs par défaut).
- AssetsService::resolveSingleItemName(int $itemId, EveToken $token, ?int $characterId, ?int $corporationId): ?string (renvoie null). Signature choisie, à garder ou à renégocier.
- AssetItemNameResource : ressource complète (Post, uriVariables, EmptyInput, status 200) avec `id`, `status`, `itemName`.
- ResolveAssetItemNameProcessor : constructeur figé par les tests (voir ci-dessous), `process()` ne fait que renvoyer la ressource avec l'id de l'URI. Sans cet id, API Platform répond 400 « Unable to generate an IRI » et les rouges ne tomberaient pas sur une assertion.
- frontend/src/types/index.ts : `Asset.isSingleton: boolean | null`, `Asset.canResolveItemName: boolean` (requis par le type-check des specs).

## Contrat que les tests figent (à ne pas casser sans prévenir)

- Constructeur du processeur, dans cet ordre : Security, CachedAssetRepository, VisibleCorporationAssetsFinder, AssetsSyncService, AssetsService, EntityManagerInterface.
- L'asset est lu par `CachedAssetRepository::find($id)` (le test accepte une chaîne ou un Uuid). Le flush passe par `EntityManagerInterface::flush()` ou `CachedAssetRepository::save($asset, true)` (les deux sont comptés).
- Statuts : 401 anonyme, 403 sans personnage principal (asset corpo), 404, 409, 422, 502, tous via `HttpExceptionInterface::getStatusCode()`. Messages : 422 contient (sans casse) « not a singleton », « singleton flag unknown », « already named » ou « office » ; 409 Director contient « director » ; 502 contient le message ESI.
- Visibilité corpo : `findVisibleAssets($corporationId, null, $user)` est appelé avec la corporation du personnage principal et l'utilisateur courant.
- Données ESI mal formées : `EsiApiException` (règle « un cas par champ lu »). Pour `is_singleton`, le message contient `is_singleton`. Non précisé par la spec : à valider. `item_id` de la réponse des noms n'est pas testé (la spec n'oblige pas à le lire).
- 'None' et nom vide sont filtrés au niveau du service ou du processeur, au choix : le test passe par les deux.
- Front : data-testid `resolve-item-name-<id>`, `item-name-unnamed-<id>`, `item-name-error-<id>` ; POST `/api/me/assets/<id>/item-name` via `fetch` ; l'erreur ESI se lit dans `detail` de la réponse. Le test de la ligne passe à `nom (type)` ne dépend que de l'état local du composant (les props restent inchangées), donc `Assets.vue` (mise à jour de son ref `assets`) n'est pas couvert par un test.

## Rouges prouvés

Unit `--group issue-784` : 45 tests, 40 en échec sur assertion, 5 verts volontaires (lignes non éligibles de la table de vérité, qui gardent le « jamais true » ; drapeau nul par défaut). Un avertissement PHP « Undefined array key is_singleton » sur le cas `is_singleton` absent (code existant), le test échoue bien sur « exception not thrown ».
Functional : 6 sur 6 en échec sur assertion (statut 200 au lieu de 404/422/502, `status` vide, drapeau nul).
Front : 7 sur 7 en échec sur `expected false to be true` (le bouton n'existe pas, assertion posée avant le clic).

## Voisins verts

tests/Unit/Service/ESI, Unit/Service/Sync, Unit/State/Processor/Assets, Unit/State/Provider/Assets, Unit/Entity hors issue-784 : 1000 tests OK. Functional CharacterAssetsApiTest et CorpAssetVisibilityApiTest, Integration State/Provider/Assets : 12 OK. Front tests/views/Assets* et tests/components/assets : verts. `npm run build` (vue-tsc) OK, `npm run lint` 0 erreur (253 avertissements, aucun dans mes fichiers). Aucun test existant ne fige `is_singleton`, `isSingleton` ou une forme d'`AssetItemResource` ; tous les `rawAsset` existants portent déjà `is_singleton`.

## Base de test

Base isolée `eve_app_testn784` créée et alimentée par `doctrine:schema:update --force` (pas de migration `is_singleton` : à écrire par l'implementer ; le jeton TEST_TOKEN=n784 sert aux runs Functional/Integration).

## Non applicable

Règles « endpoint d'écriture » (bornes, corps sans champ requis, doublon UNIQUE) : l'opération n'a pas de corps (EmptyInput) et aucune contrainte UNIQUE.

## Message de commit proposé

test(assets): pin the one-name resolution of an unnamed singleton, its scoping and the singleton flag (#784)
