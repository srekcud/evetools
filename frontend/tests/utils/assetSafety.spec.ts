import { summarizeAssetSafety, summarizeAssetSafetyLocations } from '@/utils/assetSafety'
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
