import { summarizeTypeSelection } from '@/utils/assetSelection'
import { summarizeTypeGroupsVolume, typeGroupLocationNames, type TypeGroup } from '@/utils/assetsByType'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'

const TRITANIUM = 34
const PYERITE = 35
const UNNAMED = 999001
const CONTAINER = 17366

function group(typeId: number, typeName: string, locations: [string, number][]): TypeGroup {
  return {
    typeId,
    typeName,
    categoryId: 4,
    totalQuantity: locations.reduce((sum, [, quantity]) => sum + quantity, 0),
    locations: locations.map(([locationName, quantity]) => ({ locationName, solarSystemName: 'Jita', quantity })),
  }
}

const tritanium = group(TRITANIUM, 'Tritanium', [['Alpha', 100], ['Bravo', 20]])
const pyerite = group(PYERITE, 'Pyerite', [['Bravo', 50]])
const unnamed = group(UNNAMED, 'Unknown name (#999001)', [['Charlie', 3]])
const container = group(CONTAINER, 'Station Container', [['Alpha', 2]])

const catalog: PackagedVolumeCatalog = {
  volumes: new Map([[TRITANIUM, 0.01], [PYERITE, 0.5]]),
  excludedTypeIds: new Set([CONTAINER]),
}

describe('summarizeTypeSelection', () => {
  it('lists the named types by name with their full quantity and totals the items', () => {
    const summary = summarizeTypeSelection([tritanium, pyerite], new Set())

    expect(summary.lines).toEqual([
      { typeName: 'Pyerite', quantity: 50 },
      { typeName: 'Tritanium', quantity: 120 },
    ])
    expect(summary.itemCount).toBe(170)
    expect(summary.unknownTypeCount).toBe(0)
  })

  it('counts a type without a name but leaves it out of the lines', () => {
    const summary = summarizeTypeSelection([tritanium, unnamed], new Set([UNNAMED]))

    expect(summary.lines).toEqual([{ typeName: 'Tritanium', quantity: 120 }])
    expect(summary.itemCount).toBe(123)
    expect(summary.unknownTypeCount).toBe(1)
  })

  it('returns an empty summary for an empty selection', () => {
    expect(summarizeTypeSelection([], new Set())).toEqual({ lines: [], itemCount: 0, unknownTypeCount: 0 })
  })
})

describe('summarizeTypeGroupsVolume', () => {
  it('sums the packaged volume of the full quantity of each type', () => {
    const volume = summarizeTypeGroupsVolume([tritanium, pyerite], catalog)

    expect(volume.cubicMeters).toBeCloseTo(26.2, 6)
    expect(volume.unknownTypeCount).toBe(0)
  })

  it('counts a type absent from the catalogue as unknown without adding volume', () => {
    expect(summarizeTypeGroupsVolume([pyerite, unnamed], catalog)).toEqual({ cubicMeters: 25, unknownTypeCount: 1 })
  })

  it('leaves out the types that have no packaged volume, such as station containers', () => {
    expect(summarizeTypeGroupsVolume([pyerite, container], catalog)).toEqual({ cubicMeters: 25, unknownTypeCount: 0 })
  })
})

describe('typeGroupLocationNames', () => {
  it('lists each location once, sorted by name', () => {
    expect(typeGroupLocationNames([tritanium, pyerite, unnamed])).toEqual(['Alpha', 'Bravo', 'Charlie'])
  })

  it('returns nothing for an empty selection', () => {
    expect(typeGroupLocationNames([])).toEqual([])
  })
})
