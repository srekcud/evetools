import { summarizeAssetSafety, summarizeAssetSafetyLocations, summarizeAssetSafetyVolumes } from '@/utils/assetSafety'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'
import type { Asset, AssetLocationSummaryRow } from '@/types'

const SHIP_CATEGORY_ID = 6
const MODULE_CATEGORY_ID = 7

function asset(itemId: number, overrides: Partial<Asset>): Asset {
  return {
    id: `asset-${itemId}`,
    itemId,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 1,
    locationId: 60003760,
    locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    locationType: 'other',
    locationFlag: 'Hangar',
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    cachedAt: '2026-11-13T10:00:00+00:00',
    ...overrides,
  }
}

function safetyAsset(itemId: number, system: string, overrides: Partial<Asset> = {}): Asset {
  return asset(itemId, {
    locationName: `Asset Safety (${system})`,
    locationFlag: 'AssetSafety',
    solarSystemName: system,
    ...overrides,
  })
}

describe('summarizeAssetSafety', () => {
  it('returns nothing when no asset is in Asset Safety', () => {
    const assets = [asset(1, {}), asset(2, { quantity: 40 })]

    expect(summarizeAssetSafety(assets)).toEqual([])
  })

  it('counts the quantity of every asset held in an Asset Safety location', () => {
    const assets = [
      safetyAsset(1, 'Amarr', { quantity: 100 }),
      safetyAsset(2, 'Amarr', { quantity: 25 }),
      asset(3, { quantity: 9999 }),
    ]

    const [amarr] = summarizeAssetSafety(assets)

    expect(amarr.itemCount).toBe(125)
  })

  it('counts assembled ships only', () => {
    const assets = [
      safetyAsset(1, 'Amarr', { typeId: 17738, categoryId: SHIP_CATEGORY_ID, isSingleton: true }),
      safetyAsset(2, 'Amarr', { typeId: 17738, categoryId: SHIP_CATEGORY_ID, isSingleton: true }),
      safetyAsset(3, 'Amarr', { typeId: 17738, categoryId: SHIP_CATEGORY_ID, isSingleton: false, quantity: 5 }),
      safetyAsset(4, 'Amarr', { typeId: 17738, categoryId: SHIP_CATEGORY_ID, isSingleton: null }),
      safetyAsset(5, 'Amarr', { typeId: 2048, categoryId: MODULE_CATEGORY_ID, isSingleton: true }),
    ]

    const [amarr] = summarizeAssetSafety(assets)

    expect(amarr.shipCount).toBe(2)
  })

  it('includes the content of containers held in Asset Safety', () => {
    const assets = [
      safetyAsset(1, 'Amarr', { typeId: 17738, categoryId: SHIP_CATEGORY_ID, isSingleton: true }),
      asset(2, {
        locationName: 'Mining Barge — Asset Safety (Amarr)',
        locationType: 'item',
        locationId: 1,
        locationFlag: 'Cargo',
        solarSystemName: 'Amarr',
        quantity: 700,
      }),
    ]

    const [amarr] = summarizeAssetSafety(assets)

    expect(amarr.itemCount).toBe(701)
    expect(amarr.locationName).toBe('Asset Safety (Amarr)')
  })

  it('reports one entry per solar system, named after the Asset Safety location', () => {
    const assets = [
      safetyAsset(1, 'Amarr'),
      safetyAsset(2, 'Jita'),
      safetyAsset(3, 'Amarr'),
    ]

    const summaries = summarizeAssetSafety(assets)

    expect(summaries.map(summary => [summary.locationName, summary.solarSystemName, summary.itemCount])).toEqual([
      ['Asset Safety (Amarr)', 'Amarr', 2],
      ['Asset Safety (Jita)', 'Jita', 1],
    ])
  })

  it('does not treat a location that only mentions Asset Safety in its name as Asset Safety', () => {
    const assets = [asset(1, { locationName: 'Asset Safety Warehouse - Jita' })]

    expect(summarizeAssetSafety(assets)).toEqual([])
  })
})

function summaryRow(locationName: string, overrides: Partial<AssetLocationSummaryRow>): AssetLocationSummaryRow {
  return {
    locationId: 60003760,
    locationType: 'other',
    locationName,
    solarSystemName: 'Misaba',
    itemCount: 1,
    shipCount: 0,
    divisions: [],
    cachedAt: '2026-11-14T10:00:00+00:00',
    ...overrides,
  }
}

describe('summarizeAssetSafetyLocations', () => {
  it('reads the asset safety locations from the location summary', () => {
    const summary = summarizeAssetSafetyLocations([
      summaryRow('Jita IV - Moon 4 - Caldari Navy Assembly Plant', { itemCount: 900 }),
      summaryRow('Asset Safety (Misaba)', { itemCount: 250, shipCount: 2 }),
      summaryRow('Office — Asset Safety (Misaba)', { locationId: 60003761, itemCount: 11, shipCount: 1 }),
    ])

    expect(summary).toEqual([{ locationName: 'Asset Safety (Misaba)', solarSystemName: 'Misaba', itemCount: 261, shipCount: 3 }])
  })

  it('returns nothing when no location is in asset safety', () => {
    expect(summarizeAssetSafetyLocations([summaryRow('Jita IV - Moon 4 - Caldari Navy Assembly Plant', {})])).toEqual([])
  })
})

const RIFTER = 587
const TRITANIUM = 34
const CARGO_CONTAINER = 3465
const UNLISTED_TYPE = 999001

const catalog: PackagedVolumeCatalog = {
  volumes: new Map([[RIFTER, 2500], [TRITANIUM, 0.01], [CARGO_CONTAINER, 100]]),
  excludedTypeIds: new Set([17368]),
}

describe('summarizeAssetSafetyVolumes', () => {
  const noContents = () => []

  it('sums the packaged volume of the assets of an asset safety location', () => {
    const rows = [summaryRow('Asset Safety (Misaba)', { locationId: 1 })]
    const assetsByLocation = new Map([[1, [
      asset(1, { typeId: RIFTER, quantity: 3 }),
      asset(2, { typeId: TRITANIUM, quantity: 1000 }),
    ]]])

    const volumes = summarizeAssetSafetyVolumes(rows, id => assetsByLocation.get(id) ?? [], noContents, catalog)

    expect(volumes.get('Asset Safety (Misaba)')).toEqual({ cubicMeters: 7510, unknownTypeCount: 0 })
  })

  it('includes the contents of the containers held in the location', () => {
    const rows = [summaryRow('Asset Safety (Misaba)', { locationId: 1 })]
    const box = asset(10, { itemId: 500, typeId: CARGO_CONTAINER, isSingleton: true })
    const contents = new Map([[500, [asset(11, { typeId: RIFTER, quantity: 2 })]]])

    const volumes = summarizeAssetSafetyVolumes(rows, () => [box], id => contents.get(id) ?? [], catalog)

    expect(volumes.get('Asset Safety (Misaba)')?.cubicMeters).toBe(5100)
  })

  it('merges the locations that share an asset safety name into one volume', () => {
    const rows = [
      summaryRow('Asset Safety (Misaba)', { locationId: 1 }),
      summaryRow('Office — Asset Safety (Misaba)', { locationId: 2 }),
    ]
    const assetsByLocation = new Map([
      [1, [asset(1, { typeId: RIFTER, quantity: 1 })]],
      [2, [asset(2, { typeId: RIFTER, quantity: 2 })]],
    ])

    const volumes = summarizeAssetSafetyVolumes(rows, id => assetsByLocation.get(id) ?? [], noContents, catalog)

    expect(volumes.get('Asset Safety (Misaba)')?.cubicMeters).toBe(7500)
  })

  it('counts the types without volume instead of treating them as zero', () => {
    const rows = [summaryRow('Asset Safety (Misaba)', { locationId: 1 })]
    const assets = [
      asset(1, { typeId: RIFTER, quantity: 1 }),
      asset(2, { typeId: UNLISTED_TYPE, quantity: 4 }),
    ]

    const volumes = summarizeAssetSafetyVolumes(rows, () => assets, noContents, catalog)

    expect(volumes.get('Asset Safety (Misaba)')).toEqual({ cubicMeters: 2500, unknownTypeCount: 1 })
  })

  it('reports one volume per solar system and ignores the other locations', () => {
    const rows = [
      summaryRow('Asset Safety (Misaba)', { locationId: 1 }),
      summaryRow('Asset Safety (Jita)', { locationId: 2, solarSystemName: 'Jita' }),
      summaryRow('Jita IV - Moon 4 - Caldari Navy Assembly Plant', { locationId: 3 }),
    ]
    const assetsByLocation = new Map([
      [1, [asset(1, { typeId: RIFTER, quantity: 1 })]],
      [2, [asset(2, { typeId: RIFTER, quantity: 2 })]],
      [3, [asset(3, { typeId: RIFTER, quantity: 9 })]],
    ])

    const volumes = summarizeAssetSafetyVolumes(rows, id => assetsByLocation.get(id) ?? [], noContents, catalog)

    expect([...volumes.keys()].sort()).toEqual(['Asset Safety (Jita)', 'Asset Safety (Misaba)'])
    expect(volumes.get('Asset Safety (Jita)')?.cubicMeters).toBe(5000)
  })
})
