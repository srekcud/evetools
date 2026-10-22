import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'

const UNKNOWN_TYPE_ID = 9848
const UNKNOWN_TYPE_ITEM_ID = 1050000000102
const CONTAINER_ITEM_ID = 1050000000201
const TRITANIUM_TYPE_ID = 34
const TRITANIUM_ITEM_ID = 1050000000101

function asset(overrides: Partial<Asset>): Asset {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    itemId: TRITANIUM_ITEM_ID,
    typeId: TRITANIUM_TYPE_ID,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 8800,
    locationId: 60003760,
    locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    cachedAt: '2026-10-22T10:00:00+00:00',
    ...overrides,
  }
}

function unknownTypeAsset(overrides: Partial<Asset> = {}): Asset {
  return asset({
    id: '00000000-0000-4000-8000-000000000002',
    itemId: UNKNOWN_TYPE_ITEM_ID,
    typeId: UNKNOWN_TYPE_ID,
    typeName: null,
    categoryId: null,
    quantity: 6300,
    ...overrides,
  } as unknown as Partial<Asset>)
}

function mountGroup(assets: Asset[], containerContents: Map<number, Asset[]> = new Map()) {
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr } })
  return mount(AssetLocationGroup, {
    global: { plugins: [i18n], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: 60003760,
        locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
        solarSystemName: 'Jita',
        assets,
        totalQuantity: assets.reduce((sum, a) => sum + a.quantity, 0),
      },
      expandedContainers: new Set(containerContents.keys()),
      collapsedLocations: new Set<string>(),
      containerContents,
    },
  })
}

describe('AssetLocationGroup unknown type name', () => {
  it('keeps labelling a named asset with its type name and its custom name', () => {
    const wrapper = mountGroup([asset({ itemName: 'Minerais' })])

    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Minerais (Tritanium)')
    expect(wrapper.find('img').attributes('alt')).toBe('Tritanium')
  })

  it('labels an asset without type name with the unknown type name and its type id', () => {
    const wrapper = mountGroup([asset({}), unknownTypeAsset()])

    const text = wrapper.text().replace(/\s+/g, ' ')
    expect(text).toContain('Nom inconnu (#9848)')
    expect(text).toContain('Tritanium')
    expect(text).not.toContain('null')
  })

  it('labels an asset without type name and with a custom name as the custom name followed by the unknown type name', () => {
    const wrapper = mountGroup([unknownTypeAsset({ itemName: 'Coffre' })])

    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Coffre (Nom inconnu (#9848))')
  })

  it('uses the unknown type name as the image alt of an asset without type name', () => {
    const wrapper = mountGroup([unknownTypeAsset()])

    expect(wrapper.find('img').attributes('alt')).toBe('Nom inconnu (#9848)')
  })

  it('labels an item without type name inside an expanded container with the unknown type name', () => {
    const container = asset({ id: '00000000-0000-4000-8000-000000000003', itemId: CONTAINER_ITEM_ID, typeId: 17366, typeName: 'Station Container', quantity: 1 })
    const contained = unknownTypeAsset({ locationId: CONTAINER_ITEM_ID, locationType: 'item', locationFlag: 'Unlocked' })

    const wrapper = mountGroup([container], new Map([[CONTAINER_ITEM_ID, [contained]]]))

    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Nom inconnu (#9848)')
  })
})
