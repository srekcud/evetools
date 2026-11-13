import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'

const LOCATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'

function asset(): Asset {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    itemId: 1050000000101,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 50,
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
    cachedAt: '2026-11-13T10:00:00+00:00',
  }
}

function mountHeader(locale: 'en' | 'fr', extra: Record<string, unknown>) {
  return mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: 60003760,
        locationName: LOCATION_NAME,
        solarSystemName: 'Jita',
        assets: [asset()],
        totalQuantity: 50,
        ...extra,
      },
      expandedContainers: new Set<number>(),
      containerContents: new Map<number, Asset[]>(),
      collapsedLocations: new Set<string>(),
      ...(extra.maxQuantity === undefined ? {} : { maxQuantity: extra.maxQuantity }),
    } as never,
  })
}

describe('Asset location group header summary', () => {
  it('sizes the item count bar against the largest location', () => {
    const wrapper = mountHeader('en', { maxQuantity: 200 })

    expect(wrapper.get('[data-testid="location-quantity-bar"]').attributes('style')).toContain('width: 25%')
  })

  it('fills the bar of the largest location', () => {
    const wrapper = mountHeader('en', { maxQuantity: 50 })

    expect(wrapper.get('[data-testid="location-quantity-bar"]').attributes('style')).toContain('width: 100%')
  })

  it('draws no bar when the largest location is unknown', () => {
    const wrapper = mountHeader('en', {})

    expect(wrapper.find('[data-testid="location-quantity-bar"]').exists()).toBe(false)
  })

  it('shows the ship count in English and French', () => {
    expect(mountHeader('en', { shipCount: 3 }).get('[data-testid="location-ship-count"]').text()).toBe('3 ships')
    expect(mountHeader('en', { shipCount: 1 }).get('[data-testid="location-ship-count"]').text()).toBe('1 ship')
    expect(mountHeader('fr', { shipCount: 3 }).get('[data-testid="location-ship-count"]').text()).toBe('3 vaisseaux')
  })

  it('hides the ship count when the location holds no ship', () => {
    expect(mountHeader('en', { shipCount: 0 }).find('[data-testid="location-ship-count"]').exists()).toBe(false)
    expect(mountHeader('en', {}).find('[data-testid="location-ship-count"]').exists()).toBe(false)
  })

  it('lists the division breakdown with the item count of each division', () => {
    const wrapper = mountHeader('en', {
      divisions: [{ name: 'INDUSTRIE', count: 6601 }, { name: 'ACCES MEMBRE', count: 12 }],
    })

    const entries = wrapper.findAll('[data-testid="location-division"]').map(entry => entry.text())

    expect(entries).toEqual(['INDUSTRIE 6,601', 'ACCES MEMBRE 12'])
  })

  it('shows no division breakdown without divisions', () => {
    expect(mountHeader('en', { divisions: [] }).find('[data-testid="location-division"]').exists()).toBe(false)
    expect(mountHeader('en', {}).find('[data-testid="location-division"]').exists()).toBe(false)
  })

  it('keeps the existing counts next to the new summary', () => {
    const text = mountHeader('en', { shipCount: 2, maxQuantity: 100 }).text()

    expect(text).toContain('1 item type')
    expect(text).toContain('50 items')
  })
})
