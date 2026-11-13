import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'

const STATION = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const CONTAINER_ITEM_ID = 1050000875001

function asset(overrides: Partial<Asset>): Asset {
  return {
    id: '00000000-0000-4000-8000-000000875010',
    itemId: 1050000875010,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 8800,
    locationId: 60003760,
    locationName: STATION,
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: null,
    cachedAt: '2026-11-13T10:00:00+00:00',
    ...overrides,
  }
}

const container = asset({ id: '00000000-0000-4000-8000-000000875011', itemId: CONTAINER_ITEM_ID, typeId: 17366, typeName: 'Station Container', categoryId: 2 })
const stack = asset({})
const fuelInContainer = asset({
  id: '00000000-0000-4000-8000-000000875012',
  itemId: 1050000875012,
  typeId: 4246,
  typeName: 'Hydrogen Fuel Block',
  locationId: CONTAINER_ITEM_ID,
  locationType: 'item',
})

function mountExpandedGroup() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale: 'fr', messages: { fr } }), pinia], stubs: { OpenInGameButton: true } },
    props: {
      location: { locationId: 60003760, locationName: STATION, solarSystemName: 'Jita', assets: [stack, container], totalQuantity: 8801 },
      expandedContainers: new Set([CONTAINER_ITEM_ID]),
      collapsedLocations: new Set<string>(),
      containerContents: new Map([[CONTAINER_ITEM_ID, [fuelInContainer]]]),
    },
  })
}

describe('AssetLocationGroup item icons', () => {
  it('loads every row icon lazily, container contents included', () => {
    const wrapper = mountExpandedGroup()

    const icons = wrapper.findAll('img')

    expect(icons).toHaveLength(3)
    expect(icons.map(icon => icon.attributes('loading'))).toEqual(['lazy', 'lazy', 'lazy'])
  })
})
