import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import type { Asset } from '@/types'

const STATION_ID = 60003760
const CONTAINER_ITEM_ID = 1050000000201
const PLAIN_ITEM_ID = 1050000000202
const NESTED_ITEM_ID = 1050000000203
const STRUCTURE_ID = 1035466617946

function asset(overrides: Partial<Asset>): Asset {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    itemId: PLAIN_ITEM_ID,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 50,
    locationId: STATION_ID,
    locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
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

const container = asset({
  id: '00000000-0000-4000-8000-000000000002',
  itemId: CONTAINER_ITEM_ID,
  typeId: 3293,
  typeName: 'Medium Standard Container',
  isSingleton: true,
})
const plain = asset({})
const nested = asset({
  id: '00000000-0000-4000-8000-000000000003',
  itemId: NESTED_ITEM_ID,
  locationId: CONTAINER_ITEM_ID,
  locationType: 'item',
})

function mountGroup(locationId: number, locationType: string | undefined, expanded = false) {
  return mount(AssetLocationGroup, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: { OpenInGameButton: true },
    },
    props: {
      location: {
        locationId,
        locationType,
        locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
        solarSystemName: 'Jita',
        assets: [container, plain],
        totalQuantity: 100,
      },
      expandedContainers: new Set<number>(expanded ? [CONTAINER_ITEM_ID] : []),
      containerContents: new Map<number, Asset[]>([[CONTAINER_ITEM_ID, [nested]]]),
      collapsedLocations: new Set<string>(),
    } as never,
  })
}

function buttonsOfType(wrapper: ReturnType<typeof mountGroup>, type: string) {
  return wrapper.findAllComponents(OpenInGameButton).filter(button => button.props('type') === type)
}

describe('Inventory in-game actions', () => {
  it.each([
    ['station', STATION_ID],
    ['solar_system', 30000142],
    ['other', STRUCTURE_ID],
  ])('offers to set a %s location as destination from its header', (locationType, locationId) => {
    const wrapper = mountGroup(locationId, locationType)

    const buttons = buttonsOfType(wrapper, 'waypoint')
    expect(buttons).toHaveLength(1)
    expect(buttons[0].props('targetId')).toBe(locationId)
  })

  it('offers no destination for a location whose identifier is an item', () => {
    expect(buttonsOfType(mountGroup(CONTAINER_ITEM_ID, 'item'), 'waypoint')).toHaveLength(0)
  })

  it('offers no destination when the location type is unknown', () => {
    expect(buttonsOfType(mountGroup(STATION_ID, undefined), 'waypoint')).toHaveLength(0)
  })

  it('offers the in-game info on each container and only on containers', () => {
    const wrapper = mountGroup(STATION_ID, 'station')

    const buttons = buttonsOfType(wrapper, 'info')
    expect(buttons).toHaveLength(1)
    expect(buttons[0].props('targetId')).toBe(CONTAINER_ITEM_ID)
  })

  it('keeps one market button per item row, container contents included', () => {
    const wrapper = mountGroup(STATION_ID, 'station', true)

    expect(buttonsOfType(wrapper, 'market')).toHaveLength(3)
    expect(buttonsOfType(wrapper, 'info')).toHaveLength(1)
  })
})
