import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'
import type { Asset } from '@/types'

const LOCATION_ID = 60003760
const RIFTER = 587
const TRITANIUM = 34
const PYERITE = 35
const CARGO_CONTAINER = 3296

let nextItemId = 9000

function asset(overrides: Partial<Asset>): Asset {
  nextItemId += 1
  return {
    id: `00000000-0000-4000-8000-${String(nextItemId).padStart(12, '0')}`,
    itemId: nextItemId,
    typeId: TRITANIUM,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 100,
    locationId: LOCATION_ID,
    locationName: 'Jita IV',
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: null,
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

const catalog: PackagedVolumeCatalog = {
  volumes: new Map([[RIFTER, 2500], [TRITANIUM, 0.01], [PYERITE, 0.01], [CARGO_CONTAINER, 100]]),
  excludedTypeIds: new Set(),
}

interface MountOptions {
  itemSort?: 'name' | 'quantity' | 'volume'
  projectPinnedItemIds?: Set<number>
  packagedVolumes?: PackagedVolumeCatalog | null
  expanded?: number[]
}

function mountGroup(assets: Asset[], contents: Asset[], options: MountOptions = {}) {
  const byParent = new Map<number, Asset[]>()
  for (const content of contents) {
    byParent.set(content.locationId, [...(byParent.get(content.locationId) ?? []), content])
  }
  return mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { OpenInGameButton: true } },
    props: {
      location: { locationId: LOCATION_ID, locationName: 'Jita IV', solarSystemName: 'Jita', assets, totalQuantity: assets.length },
      expandedContainers: new Set<number>(options.expanded ?? []),
      collapsedLocations: new Set<string>(),
      containerContents: byParent,
      packagedVolumes: options.packagedVolumes === undefined ? catalog : options.packagedVolumes,
      itemSort: options.itemSort,
      projectPinnedItemIds: options.projectPinnedItemIds,
    },
  })
}

function rootItemIds(wrapper: ReturnType<typeof mountGroup>): number[] {
  return wrapper.findAll('[data-asset-item-id]').map(row => Number(row.attributes('data-asset-item-id')))
}

function contentNames(wrapper: ReturnType<typeof mountGroup>, containerItemId: number): string[] {
  const container = wrapper.get(`[data-asset-item-id="${containerItemId}"]`).element.nextElementSibling
  return Array.from(container?.querySelectorAll('span.text-sm.text-slate-300') ?? []).map(name => name.textContent?.trim() ?? '')
}

describe('AssetLocationGroup item sort', () => {
  const pyerite = asset({ typeId: PYERITE, typeName: 'Pyerite', quantity: 5000 })
  const rifter = asset({ typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 1 })
  const tritanium = asset({ typeId: TRITANIUM, typeName: 'Tritanium', quantity: 100 })
  const items = [pyerite, rifter, tritanium]

  it('keeps the received order by default', () => {
    expect(rootItemIds(mountGroup(items, []))).toEqual([pyerite.itemId, rifter.itemId, tritanium.itemId])
  })

  it('sorts the items of the location by quantity', () => {
    expect(rootItemIds(mountGroup(items, [], { itemSort: 'quantity' }))).toEqual([pyerite.itemId, tritanium.itemId, rifter.itemId])
  })

  it('sorts the items of the location by volume', () => {
    expect(rootItemIds(mountGroup(items, [], { itemSort: 'volume' }))).toEqual([rifter.itemId, pyerite.itemId, tritanium.itemId])
  })

  it('keeps the received order for the volume sort without a catalog', () => {
    expect(rootItemIds(mountGroup(items, [], { itemSort: 'volume', packagedVolumes: null }))).toEqual([pyerite.itemId, rifter.itemId, tritanium.itemId])
  })

  it('keeps project pinned items first whatever the sort', () => {
    const wrapper = mountGroup(items, [], { itemSort: 'quantity', projectPinnedItemIds: new Set([rifter.itemId]) })

    expect(rootItemIds(wrapper)).toEqual([rifter.itemId, pyerite.itemId, tritanium.itemId])
  })

  it('sorts the contents of an expanded container with the same sort', () => {
    const box = asset({ typeId: CARGO_CONTAINER, typeName: 'Cargo Container', quantity: 1, isSingleton: true })
    const inside = [
      asset({ typeId: PYERITE, typeName: 'Pyerite', quantity: 5000, locationId: box.itemId, locationType: 'item' }),
      asset({ typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 1, locationId: box.itemId, locationType: 'item' }),
      asset({ typeId: TRITANIUM, typeName: 'Tritanium', quantity: 100, locationId: box.itemId, locationType: 'item' }),
    ]

    expect(contentNames(mountGroup([box], inside, { expanded: [box.itemId] }), box.itemId)).toEqual(['Pyerite', 'Rifter', 'Tritanium'])
    expect(contentNames(mountGroup([box], inside, { expanded: [box.itemId], itemSort: 'quantity' }), box.itemId)).toEqual(['Pyerite', 'Tritanium', 'Rifter'])
    expect(contentNames(mountGroup([box], inside, { expanded: [box.itemId], itemSort: 'volume' }), box.itemId)).toEqual(['Rifter', 'Pyerite', 'Tritanium'])
  })

  it('does not reorder the ship groups', () => {
    const smallHull = asset({ typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 1, isSingleton: true, itemName: 'Alpha' })
    const largeHull = asset({ typeId: 24690, typeName: 'Drake', categoryId: 6, quantity: 1, isSingleton: true, itemName: 'Beta' })
    const withoutSort = mountGroup([smallHull, largeHull, pyerite], [])
    const withSort = mountGroup([smallHull, largeHull, pyerite], [], { itemSort: 'quantity' })

    expect(withSort.findComponent({ name: 'AssetShipGroups' }).props('ships')).toEqual(withoutSort.findComponent({ name: 'AssetShipGroups' }).props('ships'))
  })
})
