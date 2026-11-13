import { summarizeSelection } from '@/utils/assetSelection'
import type { Asset } from '@/types'

function asset(itemId: number, typeId: number, typeName: string | null, quantity: number, overrides: Partial<Asset> = {}): Asset {
  return {
    id: `00000000-0000-4000-8000-${String(itemId).padStart(12, '0')}`,
    itemId,
    typeId,
    typeName,
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

const NO_CONTAINERS = new Map<number, Asset[]>()

describe('summarizeSelection', () => {
  it('adds up the quantity of one type across locations and stacks, sorted by type name', () => {
    const jita = [asset(1, 34, 'Tritanium', 100), asset(2, 35, 'Pyerite', 20)]
    const amarr = [asset(3, 34, 'Tritanium', 10)]

    const summary = summarizeSelection([{ assets: jita }, { assets: amarr }], NO_CONTAINERS)

    expect(summary.lines).toEqual([
      { typeName: 'Pyerite', quantity: 20 },
      { typeName: 'Tritanium', quantity: 110 },
    ])
    expect(summary.itemCount).toBe(130)
    expect(summary.unknownTypeCount).toBe(0)
  })

  it('counts the contents of a container with the container itself', () => {
    const can = asset(10, 3293, 'Station Container', 1)
    const inside = asset(11, 34, 'Tritanium', 5, { locationType: 'item', locationId: 10 })

    const summary = summarizeSelection([{ assets: [can] }], new Map([[10, [inside]]]))

    expect(summary.lines).toEqual([
      { typeName: 'Station Container', quantity: 1 },
      { typeName: 'Tritanium', quantity: 5 },
    ])
    expect(summary.itemCount).toBe(6)
  })

  it('follows a container held inside another container', () => {
    const outer = asset(20, 3293, 'Station Container', 1)
    const inner = asset(21, 3296, 'Giant Secure Container', 1, { locationType: 'item', locationId: 20 })
    const deep = asset(22, 34, 'Tritanium', 7, { locationType: 'item', locationId: 21 })

    const summary = summarizeSelection([{ assets: [outer] }], new Map([[20, [inner]], [21, [deep]]]))

    expect(summary.lines.map(line => line.typeName)).toEqual(['Giant Secure Container', 'Station Container', 'Tritanium'])
    expect(summary.itemCount).toBe(9)
  })

  it('leaves a type without a name out of the lines and counts it once per type', () => {
    const summary = summarizeSelection(
      [{ assets: [asset(30, 999001, null, 4), asset(31, 999001, null, 6), asset(32, 34, 'Tritanium', 1)] }],
      NO_CONTAINERS,
    )

    expect(summary.lines).toEqual([{ typeName: 'Tritanium', quantity: 1 }])
    expect(summary.unknownTypeCount).toBe(1)
    expect(summary.itemCount).toBe(11)
  })

  it('gives an empty summary for an empty selection', () => {
    expect(summarizeSelection([], NO_CONTAINERS)).toEqual({ lines: [], itemCount: 0, unknownTypeCount: 0 })
  })
})
