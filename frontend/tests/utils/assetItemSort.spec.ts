import { sortAssetItems } from '@/utils/assetItemSort'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'
import type { Asset } from '@/types'

const TRITANIUM = 34
const PYERITE = 35
const RIFTER = 587
const CARGO_CONTAINER = 3296

function asset(itemId: number, typeId: number, typeName: string, quantity: number): Asset {
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
  }
}

const catalog: PackagedVolumeCatalog = {
  volumes: new Map([[TRITANIUM, 0.01], [PYERITE, 0.01], [RIFTER, 2500], [CARGO_CONTAINER, 100]]),
  excludedTypeIds: new Set(),
}

const pyerite = asset(1, PYERITE, 'Pyerite', 5000)
const rifter = asset(2, RIFTER, 'Rifter', 1)
const tritanium = asset(3, TRITANIUM, 'Tritanium', 100)
const serverOrder = [pyerite, rifter, tritanium]

function names(assets: Asset[]): (string | null)[] {
  return assets.map(item => item.typeName)
}

function sort(assets: Asset[], itemSort: 'name' | 'quantity' | 'volume', extra: { catalog?: PackagedVolumeCatalog | null; contentsOf?: (itemId: number) => Asset[]; projectPinnedItemIds?: Set<number> } = {}): Asset[] {
  return sortAssetItems(assets, {
    sort: itemSort,
    catalog: extra.catalog === undefined ? catalog : extra.catalog,
    contentsOf: extra.contentsOf ?? (() => []),
    projectPinnedItemIds: extra.projectPinnedItemIds ?? new Set(),
  })
}

describe('sortAssetItems', () => {
  it('keeps the server order for the name sort', () => {
    expect(names(sort(serverOrder, 'name'))).toEqual(['Pyerite', 'Rifter', 'Tritanium'])
  })

  it('sorts by quantity, largest first', () => {
    expect(names(sort(serverOrder, 'quantity'))).toEqual(['Pyerite', 'Tritanium', 'Rifter'])
  })

  it('breaks a quantity tie with the server order', () => {
    const first = asset(10, TRITANIUM, 'Tritanium', 7)
    const second = asset(11, PYERITE, 'Pyerite', 7)

    expect(sort([first, second], 'quantity').map(item => item.itemId)).toEqual([10, 11])
  })

  it('sorts by volume, largest first', () => {
    expect(names(sort(serverOrder, 'volume'))).toEqual(['Rifter', 'Pyerite', 'Tritanium'])
  })

  it('counts the contents of a container in its volume', () => {
    const box = asset(20, CARGO_CONTAINER, 'Cargo Container', 1)
    const insideBox = [asset(21, RIFTER, 'Rifter', 3)]
    const bulk = asset(22, TRITANIUM, 'Tritanium', 100000)

    const sorted = sort([bulk, box], 'volume', { contentsOf: itemId => (itemId === box.itemId ? insideBox : []) })

    expect(names(sorted)).toEqual(['Cargo Container', 'Tritanium'])
  })

  it('falls back on the server order for the volume sort without a catalog', () => {
    expect(names(sort(serverOrder, 'volume', { catalog: null }))).toEqual(['Pyerite', 'Rifter', 'Tritanium'])
  })

  it('keeps project pinned items first whatever the sort', () => {
    const pinned = new Set([tritanium.itemId])

    expect(names(sort(serverOrder, 'quantity', { projectPinnedItemIds: pinned }))).toEqual(['Tritanium', 'Pyerite', 'Rifter'])
    expect(names(sort(serverOrder, 'volume', { projectPinnedItemIds: pinned }))).toEqual(['Tritanium', 'Rifter', 'Pyerite'])
    expect(names(sort(serverOrder, 'name', { projectPinnedItemIds: pinned }))).toEqual(['Tritanium', 'Pyerite', 'Rifter'])
  })

  it('does not mutate the list it is given', () => {
    const input = [...serverOrder]

    sort(input, 'quantity')

    expect(input).toEqual(serverOrder)
  })
})
