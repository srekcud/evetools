import { summarizeVolume, type PackagedVolumeCatalog } from '@/utils/assetVolume'
import type { Asset } from '@/types'

const RIFTER = 587
const TRITANIUM = 34
const CARGO_CONTAINER = 3296
const STATION_WAREHOUSE_CONTAINER = 17368
const UNLISTED_TYPE = 999001

function asset(itemId: number, typeId: number, quantity: number, overrides: Partial<Asset> = {}): Asset {
  return {
    id: `00000000-0000-4000-8000-${String(itemId).padStart(12, '0')}`,
    itemId,
    typeId,
    typeName: `Type ${typeId}`,
    categoryId: 4,
    quantity,
    locationId: 60003760,
    locationName: 'Jita IV - Moon 4',
    locationType: 'station',
    locationFlag: 'Hangar',
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

const catalog: PackagedVolumeCatalog = {
  volumes: new Map([
    [RIFTER, 2500],
    [TRITANIUM, 0.01],
    [CARGO_CONTAINER, 100],
    [STATION_WAREHOUSE_CONTAINER, 10000000],
  ]),
  excludedTypeIds: new Set([17366, 17367, 17368]),
}

const noContents = () => []

function contentsFrom(entries: [number, Asset[]][]): (itemId: number) => Asset[] {
  const byItemId = new Map(entries)
  return itemId => byItemId.get(itemId) ?? []
}

describe('summarizeVolume', () => {
  it('multiplies the packaged volume by the quantity', () => {
    expect(summarizeVolume([asset(1, RIFTER, 3)], noContents, catalog)).toEqual({ cubicMeters: 7500, unknownTypeCount: 0 })
  })

  it('adds the stacks of different types', () => {
    const summary = summarizeVolume([asset(1, TRITANIUM, 1000), asset(2, RIFTER, 1)], noContents, catalog)

    expect(summary.cubicMeters).toBeCloseTo(2510)
  })

  it('counts a container with its contents', () => {
    const container = asset(10, CARGO_CONTAINER, 1)
    const inside = [asset(11, RIFTER, 2)]

    const summary = summarizeVolume([container], contentsFrom([[10, inside]]), catalog)

    expect(summary).toEqual({ cubicMeters: 5100, unknownTypeCount: 0 })
  })

  it('leaves a station container out but keeps what it holds', () => {
    const station = asset(20, STATION_WAREHOUSE_CONTAINER, 1)
    const inside = [asset(21, TRITANIUM, 100)]

    const summary = summarizeVolume([station], contentsFrom([[20, inside]]), catalog)

    expect(summary).toEqual({ cubicMeters: 1, unknownTypeCount: 0 })
  })

  it('adds nothing for a type without volume and counts it once per type', () => {
    const summary = summarizeVolume(
      [asset(30, RIFTER, 5), asset(31, UNLISTED_TYPE, 2), asset(32, UNLISTED_TYPE, 7)],
      noContents,
      catalog,
    )

    expect(summary).toEqual({ cubicMeters: 12500, unknownTypeCount: 1 })
  })

  it('reads the contents of one item only once', () => {
    const container = asset(40, CARGO_CONTAINER, 1)
    const listedTwice = [container, container]
    const inside = [asset(41, RIFTER, 1)]

    const summary = summarizeVolume(listedTwice, contentsFrom([[40, inside]]), catalog)

    expect(summary.cubicMeters).toBe(100 + 100 + 2500)
  })

  it('returns zero for no asset', () => {
    expect(summarizeVolume([], noContents, catalog)).toEqual({ cubicMeters: 0, unknownTypeCount: 0 })
  })
})
