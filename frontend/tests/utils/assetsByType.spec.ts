import { filterTypeGroups, groupAssetsByType, sortTypeGroups, typeGroupVolume, type TypeGroup } from '@/utils/assetsByType'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'
import type { Asset } from '@/types'

const HOME_STATION = 'Home Station'
const MARKET_STATION = 'Jita IV - Moon 4'
const TRITANIUM = 34
const PYERITE = 35
const CONTAINER = 3465

let nextItemId = 1

function asset(overrides: Partial<Asset>): Asset {
  const itemId = nextItemId++
  return {
    id: `asset-${itemId}`,
    itemId,
    typeId: TRITANIUM,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 1,
    locationId: 60003760,
    locationName: HOME_STATION,
    locationType: 'station',
    locationFlag: 'Hangar',
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: null,
    canResolveItemName: false,
    cachedAt: '2026-11-12T10:00:00+00:00',
    ...overrides,
  }
}

function labelOf(item: Asset): string {
  return item.typeName ?? `Type ${item.typeId}`
}

describe('groupAssetsByType', () => {
  it('sums the quantity of a type across every location', () => {
    const groups = groupAssetsByType([
      asset({ quantity: 100, locationName: HOME_STATION }),
      asset({ quantity: 250, locationName: MARKET_STATION }),
      asset({ typeId: PYERITE, typeName: 'Pyerite', quantity: 7, locationName: HOME_STATION }),
    ], labelOf)

    const tritanium = groups.find(group => group.typeId === TRITANIUM)
    expect(tritanium?.totalQuantity).toBe(350)
    expect(groups).toHaveLength(2)
  })

  it('lists one entry per location with the quantity held there', () => {
    const groups = groupAssetsByType([
      asset({ quantity: 100, locationName: HOME_STATION }),
      asset({ quantity: 50, locationName: HOME_STATION }),
      asset({ quantity: 250, locationName: MARKET_STATION }),
    ], labelOf)

    expect(groups[0].locations.map(location => [location.locationName, location.quantity])).toEqual([
      [MARKET_STATION, 250],
      [HOME_STATION, 150],
    ])
  })

  it('counts an item inside a container in the location of the container', () => {
    const container = asset({ typeId: CONTAINER, typeName: 'Large Secure Container', locationName: HOME_STATION })
    const groups = groupAssetsByType([
      container,
      asset({ quantity: 40, locationName: `Box - ${HOME_STATION}`, locationType: 'item', locationId: container.itemId }),
      asset({ quantity: 60, locationName: HOME_STATION }),
    ], labelOf)

    const tritanium = groups.find(group => group.typeId === TRITANIUM)
    expect(tritanium?.locations).toEqual([
      { locationName: HOME_STATION, solarSystemName: 'Jita', quantity: 100 },
    ])
  })

  it('resolves nested containers up to the first location', () => {
    const outer = asset({ typeId: CONTAINER, typeName: 'Large Secure Container', locationName: HOME_STATION })
    const inner = asset({ typeId: CONTAINER, typeName: 'Large Secure Container', locationName: `Outer - ${HOME_STATION}`, locationType: 'item', locationId: outer.itemId })
    const groups = groupAssetsByType([
      outer,
      inner,
      asset({ quantity: 9, locationName: `Inner - ${HOME_STATION}`, locationType: 'item', locationId: inner.itemId }),
    ], labelOf)

    const tritanium = groups.find(group => group.typeId === TRITANIUM)
    expect(tritanium?.locations.map(location => location.locationName)).toEqual([HOME_STATION])
  })

  it('names a type from the label function', () => {
    const groups = groupAssetsByType([asset({ typeName: null, typeId: 99 })], labelOf)

    expect(groups[0].typeName).toBe('Type 99')
  })
})

describe('sortTypeGroups', () => {
  const groups = groupAssetsByType([
    asset({ typeId: PYERITE, typeName: 'Pyerite', quantity: 500 }),
    asset({ typeId: TRITANIUM, typeName: 'Tritanium', quantity: 8800 }),
    asset({ typeId: 587, typeName: 'Rifter', quantity: 1 }),
  ], labelOf)

  it('sorts by quantity, largest first', () => {
    expect(sortTypeGroups(groups, 'quantity', 'desc').map(group => group.typeName)).toEqual(['Tritanium', 'Pyerite', 'Rifter'])
  })

  it('sorts by quantity, smallest first', () => {
    expect(sortTypeGroups(groups, 'quantity', 'asc').map(group => group.typeName)).toEqual(['Rifter', 'Pyerite', 'Tritanium'])
  })

  it('sorts by name', () => {
    expect(sortTypeGroups(groups, 'name', 'asc').map(group => group.typeName)).toEqual(['Pyerite', 'Rifter', 'Tritanium'])
  })

  it('does not reorder the list it is given', () => {
    const before = groups.map(group => group.typeName)

    sortTypeGroups(groups, 'name', 'desc')

    expect(groups.map(group => group.typeName)).toEqual(before)
  })
})

describe('filterTypeGroups', () => {
  const groups = groupAssetsByType([
    asset({ quantity: 100, locationName: HOME_STATION, solarSystemName: 'Home System' }),
    asset({ quantity: 250, locationName: MARKET_STATION, solarSystemName: 'Jita' }),
    asset({ typeId: 11399, typeName: 'Morphite', quantity: 3, locationName: MARKET_STATION, solarSystemName: 'Jita' }),
  ], labelOf)
  const noFilter = { solarSystem: '', location: '', searchQuery: '' }

  it('keeps every type when no filter is set', () => {
    expect(filterTypeGroups(groups, noFilter)).toEqual(groups)
  })

  it('keeps only the quantity held in the chosen location and recomputes the total', () => {
    const filtered = filterTypeGroups(groups, { ...noFilter, location: MARKET_STATION })

    const tritanium = filtered.find(group => group.typeId === TRITANIUM)
    expect(tritanium?.totalQuantity).toBe(250)
    expect(tritanium?.locations.map(location => location.locationName)).toEqual([MARKET_STATION])
  })

  it('drops a type that is absent from the chosen solar system', () => {
    const filtered = filterTypeGroups(groups, { ...noFilter, solarSystem: 'Home System' })

    expect(filtered.map(group => group.typeName)).toEqual(['Tritanium'])
  })

  it('matches the search on the type name, ignoring case and accents', () => {
    expect(filterTypeGroups(groups, { ...noFilter, searchQuery: 'MORPH' }).map(group => group.typeName)).toEqual(['Morphite'])
    expect(filterTypeGroups(groups, { ...noFilter, searchQuery: 'tritanïum' }).map(group => group.typeName)).toEqual(['Tritanium'])
  })

  it('returns nothing when no type matches', () => {
    expect(filterTypeGroups(groups, { ...noFilter, searchQuery: 'zzz' })).toEqual([])
  })
})

describe('type volume', () => {
  const EXCLUDED_TYPE = 17368
  const UNLISTED_TYPE = 999001
  const catalog: PackagedVolumeCatalog = {
    volumes: new Map([[TRITANIUM, 0.01], [PYERITE, 0.5]]),
    excludedTypeIds: new Set([EXCLUDED_TYPE]),
  }

  function group(typeId: number, typeName: string, totalQuantity: number): TypeGroup {
    return { typeId, typeName, categoryId: 4, totalQuantity, locations: [] }
  }

  it('multiplies the unit volume by the total quantity of the row', () => {
    expect(typeGroupVolume(group(PYERITE, 'Pyerite', 10), catalog)).toEqual({ cubicMeters: 5, unknownTypeCount: 0 })
  })

  it('flags a type absent from the catalogue instead of inventing a volume', () => {
    expect(typeGroupVolume(group(UNLISTED_TYPE, 'Mystery Crate', 4), catalog)).toEqual({ cubicMeters: 0, unknownTypeCount: 1 })
  })

  it('returns no volume for an excluded type', () => {
    expect(typeGroupVolume(group(EXCLUDED_TYPE, 'Station Warehouse Container', 1), catalog)).toBeNull()
  })

  it('sorts by volume, descending then ascending', () => {
    const groups = [group(TRITANIUM, 'Tritanium', 100), group(PYERITE, 'Pyerite', 10), group(36, 'Mexallon', 1)]
    const withMexallon: PackagedVolumeCatalog = { ...catalog, volumes: new Map([...catalog.volumes, [36, 0.25]]) }

    expect(sortTypeGroups(groups, 'volume', 'desc', withMexallon).map(entry => entry.typeName)).toEqual(['Pyerite', 'Tritanium', 'Mexallon'])
    expect(sortTypeGroups(groups, 'volume', 'asc', withMexallon).map(entry => entry.typeName)).toEqual(['Mexallon', 'Tritanium', 'Pyerite'])
  })

  it('keeps types without volume last in both directions, ordered by name', () => {
    const groups = [
      group(UNLISTED_TYPE, 'Mystery Crate', 4),
      group(PYERITE, 'Pyerite', 10),
      group(EXCLUDED_TYPE, 'Station Warehouse Container', 1),
      group(TRITANIUM, 'Tritanium', 100),
    ]

    expect(sortTypeGroups(groups, 'volume', 'desc', catalog).map(entry => entry.typeName)).toEqual(['Pyerite', 'Tritanium', 'Mystery Crate', 'Station Warehouse Container'])
    expect(sortTypeGroups(groups, 'volume', 'asc', catalog).map(entry => entry.typeName)).toEqual(['Tritanium', 'Pyerite', 'Mystery Crate', 'Station Warehouse Container'])
  })

  it('leaves the quantity sort untouched', () => {
    const groups = [group(TRITANIUM, 'Tritanium', 100), group(PYERITE, 'Pyerite', 10)]

    expect(sortTypeGroups(groups, 'quantity', 'desc').map(entry => entry.typeName)).toEqual(['Tritanium', 'Pyerite'])
  })
})
