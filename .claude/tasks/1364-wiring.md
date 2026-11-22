# #1364 — câblage (courses à faire, `GET /industry/projects/{projectId}/transport-legs`)

Spec : `docs/specs/1364-transport-legs-read-endpoint.md`. Base vérifiée : 64be595dd.

## Classes proposées (7 nouvelles, 1 touchée)

Toutes dans la couche deptrac `Service` (`src/Service/Industry/Project/Haul/`), sauf provider (`StateProvider`) et ressource (`ApiResource`). `Service -> IndustryApplication` est permis (ItemVolumes), comme `ProjectStationTransfers`.

| Classe | Rôle |
|---|---|
| `Haul/HaulReadings` (VO d'entrée) | lectures déjà faites : `list<TypeStationQuantities>`, `list<PurchaseToDeliver>`, `list<TransferLegState>` + extrémités de chaque route, `?PersonalStock` + `?string personalStockUnknownReason`, `array<int locationId, array<int typeId, ?DateTimeImmutable>> productionEnd` (absent = pas de job), `array<int|string placeKey, HaulPlace> places`, `array<int eveCharacterId, array<placeKey, StructureAccessStatus>> access`, `list<int> viewerCharacterIds`, `array<int typeId, ?float> unitVolumes`, `list<int> planLocationIds` |
| `Haul/HaulLegsBuilder` (pur) | `build(HaulReadings): HaulLegs` — R1 à R11 ; aucune dépendance au constructeur |
| `Haul/HaulLegs`, `Haul/HaulLeg`, `Haul/HaulLine`, `Haul/HaulPlace` (VO de sortie) | `HaulLegs{legs, unreadable, personalStockUnknownReason}` ; `HaulPlace{locationId, stationName, solarSystemId, solarSystemName}` |
| `Haul/ProjectHaulLegs` (lecteur) | `of(IndustryProject, User viewer): HaulLegs` : lit les sources, construit `HaulReadings`, appelle le builder ; R12, R13 |
| `State/Provider/Industry/Project/ProjectTransportLegsProvider` | auth, `UuidOrNotFound`, `assertAcceptedMember`, mapping VO -> ressource |
| `ApiResource/Industry/Project/ProjectTransportLegsResource` | `Get`, `Link` vers `IndustryProjectResource`, `skip_null_values: false`, `security: ROLE_USER` |
| touchée : `Courier/PurchaseOriginResolver` | extraire `solarSystemIdOfLocation(int $locationId): ?int` (corps actuel après la lecture de la transaction), `solarSystemIdOf` délègue |

Fichiers de doc : `docs/modules/industry.md` (« Où chercher », une ligne courses), glossaire déjà fait.

## Dépendances de `ProjectHaulLegs` et fourniture par le harnais

| Dépendance | Type réel | Harnais unitaire |
|---|---|---|
| `StationQuantitiesSource` | interface, impl. `ProjectStationQuantities` | `createStub(StationQuantitiesSource::class)` renvoyant des `TypeStationQuantities` construits à la main |
| `PurchasesToDeliverBuilder` | `final readonly` | non stubbable : le construire sur `ProjectContainerFulfillment`, `PurchaseOriginResolver`, `MapSolarSystemRepository` comme `PurchasesToDeliverBuilderTest` ; ou tester le lecteur seulement en fonctionnel (recommandé, le builder pur porte les exemples) |
| `ProjectStationTransfers` | `final readonly` (repo `IndustryProjectStationTransferRepository`, `TransferLegStateCalculator`, `ItemVolumes`) | idem : construire sur un stub de repository, comme `TransferLegStateCalculatorTest` ; `ProjectContainerReadings` fourni pour les readings |
| `ProjectContainerReadings`, `ProjectContainerStations` | services finals | fonctionnel (`BuildsIshtarGroupProject`) |
| `PersonalStockReader` | `final readonly` | trait `tests/Unit/Support/StubsPersonalStockReader` (`personalStockReaderOn(CachedAssetRepository)`) |
| `CachedAssetRepository::hasPersonalAssetsOf` | repository | `createStub` |
| `CharacterStructureAccess` | interface, impl. `CharacterStructureAccessReader` | `createStub` avec `willReturnCallback` par (personnage, lieu) ; `BuildsStructureAccess` pour fabriquer les `Character` |
| `ItemVolumes` | interface (`IndustryApplication`) | `tests/Unit/Industry/Application/Double/InMemoryItemVolumes` |
| `PurchaseOriginResolver` | `final readonly` | construit sur stubs `StaStationRepository`, `CachedStructureRepository`, `IndustryStructureConfigRepository` (`PurchaseOriginResolverTest`) |
| `MapSolarSystemRepository::findBySolarSystemId` | repository | `createStub` |
| `IndustryStructureConfigRepository::findByUser` (owner) | repository | `createStub` ; sert `plannedStructureId -> locationId` pour `productionEnd` (R7) |

Le builder pur ne dépend de rien : les 22 exemples E1-E22 sont des tests de `HaulLegsBuilderTest` (`tests/Unit/Service/Industry/Project/Haul/`), entrées construites à la main (`TypeStationQuantities`, `StationQuantity`, `PurchaseToDeliver`, `TransferLegState`, `StationTransferLineState`, `PersonalStock`, enums `StructureAccessStatus`). E23 et une course de bout en bout : `ProjectTransportLegsApiTest` (`tests/Functional/Api/Industry/Project/`, trait `BuildsIshtarGroupProject`, base `make test-db`), `#[Group('issue-1364')]`.

Lecteur : un test unitaire de `ProjectHaulLegs` pour R12/R13 (projet `completed` ne lit pas les assets : `hasPersonalAssetsOf` jamais appelé ; `hasPersonalAssetsOf` faux -> `personal_assets_not_synced`) et pour la carte `productionEnd` (deux matches, une fin nulle).

## Tests existants appelés à casser

Aucun. `PurchaseOriginResolverTest`, `PurchasesToDeliverBuilderTest`, `CourierOverviewBuilderTest` restent verts (refactor sans changement de sortie). Aucun test ne référence `ProjectStationQuantities` hors `ProjectStationQuantitiesApiTest`, non touché.

## Forme HTTP

`GET /api/industry/projects/{projectId}/transport-legs` -> 200

```json
{
  "id": "<projectId>",
  "personalStockUnknownReason": null,
  "unreadable": [{"typeId": 11399, "typeName": "Morphite", "locationId": 1035000000002, "reason": "corporation_assets_not_synced"}],
  "legs": [{
    "key": "1035000000001>1035000000002",
    "departure": {"locationId": 1035000000001, "stationName": "Azbel A", "solarSystemId": 30000001, "solarSystemName": "…"},
    "arrival":   {"locationId": 1035000000002, "stationName": "Sotiyo B", "solarSystemId": 30000002, "solarSystemName": "…"},
    "access": "granted",
    "carrierCharacterIds": [2112000001],
    "volume": 400.0,
    "lines": [{"typeId": 1, "typeName": "Composant X", "quantity": 40, "state": "ready", "source": "project",
               "readyAt": null, "unitVolume": 10.0, "volume": 400.0, "unknownReason": null}]
  }]
}
```

`quantity` nul seulement pour `in_transit` illisible ; `readyAt` ISO 8601 (`DATE_ATOM`) ou `null` ; `departure.locationId` nul pour un achat au hub. Erreurs : 401, 403 (`You do not have access to this project`), 404.

## Questions de spec ouvertes

- Double lecture : `ProjectStationQuantities::of` relit containers et transferts que `ProjectHaulLegs` lit aussi (R6). Accepté (lecture seule) ; sinon exposer une variante qui reçoit les readings.
- `PurchaseOriginResolver::FIRST_STRUCTURE_ID` vaut 1e9, `StructureIds::FIRST_STRUCTURE_ID` 1e12 : sans effet ici (stations NPC ~6e7), non corrigé.
- Couple `deptrac` : `Haul/*` n'importe que `Service`, `Entity`, `Repository`, `Enum`, `IndustryApplication` ; le provider n'a pas besoin de `skip_violations`.
