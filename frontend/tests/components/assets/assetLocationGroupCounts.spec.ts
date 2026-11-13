import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'

const LOCATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'

function asset(index: number, quantity: number): Asset {
  return {
    id: `00000000-0000-4000-8000-00000000000${index}`,
    itemId: 1050000000100 + index,
    typeId: 34 + index,
    typeName: `Type ${index}`,
    categoryId: 4,
    quantity,
    locationId: 60003760,
    locationName: LOCATION_NAME,
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: null,
    cachedAt: '2026-10-22T10:00:00+00:00',
  }
}

function headerText(locale: 'en' | 'fr', assets: Asset[]): string {
  const wrapper = mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: 60003760,
        locationName: LOCATION_NAME,
        solarSystemName: 'Jita',
        assets,
        totalQuantity: assets.reduce((sum, candidate) => sum + candidate.quantity, 0),
      },
      expandedContainers: new Set<number>(),
      containerContents: new Map<number, Asset[]>(),
      collapsedLocations: new Set<string>(),
    },
  })
  return wrapper.text()
}

describe('Asset location group header counts', () => {
  it('shows English counts under the English locale', () => {
    const text = headerText('en', [asset(1, 5), asset(2, 7)])

    expect(text).toContain('2 item types')
    expect(text).toContain('12 items')
    expect(text).not.toContain("types d'items")
  })

  it('uses the singular for one item type and one item', () => {
    const text = headerText('en', [asset(1, 1)])

    expect(text).toContain('1 item type')
    expect(text).not.toContain('1 item types')
    expect(text).toContain('1 item type1 item')
    expect(text).not.toContain('1 items')
  })

  it('shows French counts under the French locale', () => {
    const text = headerText('fr', [asset(1, 5), asset(2, 7)])

    expect(text).toContain("2 types d'items")
    expect(text).toContain('12 items')
  })
})
