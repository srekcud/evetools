import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'

const LOCATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'

const tritanium: Asset = {
  id: '00000000-0000-4000-8000-000000000001',
  itemId: 1050000000101,
  typeId: 34,
  typeName: 'Tritanium',
  categoryId: 4,
  quantity: 100,
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
  cachedAt: '2026-11-13T11:00:00+00:00',
}

function mountGroup(selected: boolean | undefined) {
  return mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: 60003760,
        locationName: LOCATION_NAME,
        solarSystemName: 'Jita',
        assets: [tritanium],
        totalQuantity: 100,
      },
      selected,
      expandedContainers: new Set<number>(),
      containerContents: new Map<number, Asset[]>(),
      collapsedLocations: new Set<string>(),
    },
  })
}

describe('AssetLocationGroup selection checkbox', () => {
  it('shows an unchecked checkbox labelled with the location when the location is not selected', () => {
    const checkbox = mountGroup(false).get('[data-testid="location-select"]')

    expect((checkbox.element as HTMLInputElement).checked).toBe(false)
    expect(checkbox.attributes('aria-label')).toBe(`Select ${LOCATION_NAME}`)
  })

  it('shows a checked checkbox when the location is selected', () => {
    const checkbox = mountGroup(true).get('[data-testid="location-select"]')

    expect((checkbox.element as HTMLInputElement).checked).toBe(true)
  })

  it('asks to toggle the selection of the location without collapsing it', async () => {
    const wrapper = mountGroup(false)

    await wrapper.get('[data-testid="location-select"]').trigger('click')

    expect(wrapper.emitted('toggle-selection')).toEqual([[LOCATION_NAME]])
    expect(wrapper.emitted('toggle-location')).toBeUndefined()
  })

  it('still collapses the location when its header is clicked', async () => {
    const wrapper = mountGroup(false)

    await wrapper.get('h3').trigger('click')

    expect(wrapper.emitted('toggle-location')).toEqual([[LOCATION_NAME]])
    expect(wrapper.emitted('toggle-selection')).toBeUndefined()
  })
})
