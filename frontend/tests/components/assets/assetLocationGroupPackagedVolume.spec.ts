import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'
import type { Asset } from '@/types'

const LOCATION_ID = 60003760
const RIFTER = 587
const DAMAGE_CONTROL = 2048

let nextItemId = 8000

function asset(overrides: Partial<Asset>): Asset {
  nextItemId += 1
  return {
    id: `00000000-0000-4000-8000-${String(nextItemId).padStart(12, '0')}`,
    itemId: nextItemId,
    typeId: 34,
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
  volumes: new Map([[RIFTER, 2500], [DAMAGE_CONTROL, 5]]),
  excludedTypeIds: new Set([17366, 17367, 17368]),
}

function mountGroup(assets: Asset[], contents: Asset[], packagedVolumes: PackagedVolumeCatalog | null, volume?: { cubicMeters: number; unknownTypeCount: number }) {
  const byParent = new Map<number, Asset[]>()
  for (const content of contents) {
    byParent.set(content.locationId, [...(byParent.get(content.locationId) ?? []), content])
  }
  return mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: LOCATION_ID,
        locationName: 'Jita IV',
        solarSystemName: 'Jita',
        assets,
        totalQuantity: assets.length,
        volume,
      },
      expandedContainers: new Set<number>(),
      collapsedLocations: new Set<string>(),
      containerContents: byParent,
      packagedVolumes,
    },
  })
}

describe('AssetLocationGroup packaged volume', () => {
  it('shows the volume of the location it is given in the header', () => {
    const wrapper = mountGroup([asset({})], [], catalog, { cubicMeters: 1234.5, unknownTypeCount: 0 })

    expect(wrapper.get('[data-testid="location-volume"]').text()).toContain('1,234.5 m³')
  })

  it('shows no volume when the location has none', () => {
    const wrapper = mountGroup([asset({})], [], null)

    expect(wrapper.find('[data-testid="location-volume"]').exists()).toBe(false)
  })

  it('shows the volume of a ship group with its fit', () => {
    const hull = asset({ typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 1, isSingleton: true, itemName: 'Alpha' })
    const fitted = asset({ typeId: DAMAGE_CONTROL, typeName: 'Damage Control II', categoryId: 7, quantity: 1, locationId: hull.itemId, locationType: 'item', locationFlag: 'LoSlot0' })

    const wrapper = mountGroup([hull], [fitted], catalog)

    expect(wrapper.get('[data-testid="ship-group-volume"]').text()).toContain('2,505 m³')
  })

  it('shows the volume of a container with its contents', () => {
    const box = asset({ typeId: RIFTER, typeName: 'Rifter', quantity: 1, isSingleton: true })
    const inside = asset({ typeId: DAMAGE_CONTROL, typeName: 'Damage Control II', categoryId: 7, quantity: 4, locationId: box.itemId, locationType: 'item' })

    const wrapper = mountGroup([box], [inside], catalog)

    expect(wrapper.get('[data-testid="container-volume"]').text()).toContain('2,520 m³')
  })
})
