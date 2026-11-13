import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'

const LOCATION_NAME = 'Jita IV - Moon 4'
const BOX_ITEM_ID = 1050000000201
const PLAIN_ITEM_ID = 1050000000202

function asset(itemId: number, overrides: Partial<Asset> = {}): Asset {
  return {
    id: `00000000-0000-4000-8000-${String(itemId).padStart(12, '0')}`,
    itemId,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 100,
    locationId: 60003760,
    locationName: LOCATION_NAME,
    locationType: 'station',
    locationFlag: 'CorpSAG2',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: 'Reactions',
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

const box = asset(BOX_ITEM_ID, { typeId: 17366, typeName: 'Giant Secure Container', itemName: 'Sylvain - Reactions', isSingleton: true })
const plain = asset(PLAIN_ITEM_ID)
const boxContent = asset(1050000000203, { locationId: BOX_ITEM_ID, locationType: 'item' })

function mountGroup(props: { canPin?: boolean; pinnedItemIds?: Set<number>; projectPinnedItemIds?: Set<number> }) {
  return mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })], stubs: { OpenInGameButton: true } },
    props: {
      location: { locationId: 60003760, locationName: LOCATION_NAME, solarSystemName: 'Jita', assets: [plain, box], totalQuantity: 202 },
      expandedContainers: new Set<number>(),
      containerContents: new Map<number, Asset[]>([[BOX_ITEM_ID, [boxContent]]]),
      collapsedLocations: new Set<string>(),
      ...props,
    },
  })
}

describe('AssetLocationGroup container pin', () => {
  it('shows no star when pinning is not available', () => {
    expect(mountGroup({}).find('[data-testid="container-pin"]').exists()).toBe(false)
  })

  it('shows a star on containers only', () => {
    const wrapper = mountGroup({ canPin: true })

    expect(wrapper.findAll('[data-testid="container-pin"]')).toHaveLength(1)
  })

  it('offers to pin an unpinned container and asks for it without toggling the container', async () => {
    const wrapper = mountGroup({ canPin: true, pinnedItemIds: new Set() })
    const star = wrapper.get('[data-testid="container-pin"]')

    await star.trigger('click')

    expect(star.attributes('aria-label')).toBe('Pin Sylvain - Reactions')
    expect(star.attributes('aria-pressed')).toBe('false')
    expect(wrapper.emitted('toggle-pin')).toEqual([[BOX_ITEM_ID]])
    expect(wrapper.emitted('toggle-container')).toBeUndefined()
  })

  it('offers to unpin a pinned container', () => {
    const star = mountGroup({ canPin: true, pinnedItemIds: new Set([BOX_ITEM_ID]) }).get('[data-testid="container-pin"]')

    expect(star.attributes('aria-label')).toBe('Unpin Sylvain - Reactions')
    expect(star.attributes('aria-pressed')).toBe('true')
  })

  it('locks the star of a container pinned by a project', async () => {
    const wrapper = mountGroup({ canPin: true, pinnedItemIds: new Set([BOX_ITEM_ID]), projectPinnedItemIds: new Set([BOX_ITEM_ID]) })
    const star = wrapper.get('[data-testid="container-pin"]')

    await star.trigger('click')

    expect(star.attributes('disabled')).toBeDefined()
    expect(star.attributes('aria-pressed')).toBe('true')
    expect(wrapper.emitted('toggle-pin')).toBeUndefined()
  })
})
