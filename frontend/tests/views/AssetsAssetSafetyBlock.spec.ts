import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const NAMED_STATION = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const ASSET_SAFETY = 'Asset Safety (Amarr)'
const SHIP_CATEGORY_ID = 6

function asset(id: number, overrides: Record<string, unknown>) {
  return {
    id: `00000000-0000-4000-8000-${String(id).padStart(12, '0')}`,
    itemId: 1050000000000 + id,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 100,
    locationId: 60003760,
    locationType: 'other',
    locationFlag: 'Hangar',
    itemName: null,
    isSingleton: false,
    cachedAt: '2026-11-13T10:00:00+00:00',
    ...overrides,
  }
}

const stationAsset = asset(1, { locationName: NAMED_STATION, solarSystemId: 30000142, solarSystemName: 'Jita' })
const safetyItem = asset(2, { locationName: ASSET_SAFETY, locationId: 3200000011, locationFlag: 'AssetSafety', solarSystemId: 30002187, solarSystemName: 'Amarr', quantity: 250 })
const safetyShip = asset(3, {
  typeId: 17738,
  typeName: 'Machariel',
  categoryId: SHIP_CATEGORY_ID,
  isSingleton: true,
  quantity: 1,
  locationName: ASSET_SAFETY,
  locationId: 3200000011,
  locationFlag: 'AssetSafety',
  solarSystemId: 30002187,
  solarSystemName: 'Amarr',
})

async function mountAssets(rawAssets: unknown[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [{
      id: CHARACTER_ID,
      eveCharacterId: 2112000001,
      name: 'Test Pilot',
      corporationId: 98000001,
      corporationName: 'Test Corp',
      allianceId: null,
      allianceName: null,
      isMain: true,
      hasValidToken: true,
      lastSyncAt: null,
    }],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as User
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ items: rawAssets }), { status: 200 })))
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr } })
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('Assets Asset Safety block', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the system, item count, ship count and the in-game reminder when Asset Safety holds assets', async () => {
    const wrapper = await mountAssets([stationAsset, safetyItem, safetyShip])

    const block = wrapper.find('[data-testid="asset-safety-block"]')

    expect(block.exists()).toBe(true)
    expect(block.text()).toContain('Amarr')
    expect(block.find('[data-testid="asset-safety-items"]').text()).toContain('251')
    expect(block.find('[data-testid="asset-safety-ships"]').text()).toContain('1')
    expect(block.find('[data-testid="asset-safety-reminder"]').text()).toBe(fr.assets.assetSafety.reminder)
  })

  it('shows no block when Asset Safety is empty', async () => {
    const wrapper = await mountAssets([stationAsset])

    expect(wrapper.find('[data-testid="asset-safety-block"]').exists()).toBe(false)
  })

  it('opens the Asset Safety location in the list from "Show content"', async () => {
    const wrapper = await mountAssets([stationAsset, safetyItem, safetyShip])

    await wrapper.find('[data-testid="asset-safety-show-content"]').trigger('click')
    await flushPromises()
    const headings = wrapper.findAll('h3').map(heading => heading.text())

    expect(headings).toEqual([ASSET_SAFETY])
  })

  it('expands the Asset Safety location when it was collapsed', async () => {
    const wrapper = await mountAssets([stationAsset, safetyItem, safetyShip])
    const header = () => wrapper.findAll('[data-testid="location-group"]')
      .find(candidate => candidate.text().includes(ASSET_SAFETY))
      ?.find('[role="button"]')
    await header()?.trigger('click')
    await flushPromises()
    const expandedWhileCollapsed = header()?.attributes('aria-expanded')

    await wrapper.find('[data-testid="asset-safety-show-content"]').trigger('click')
    await flushPromises()

    expect(expandedWhileCollapsed).toBe('false')
    expect(header()?.attributes('aria-expanded')).toBe('true')
  })

  it('keeps listing every location while the block is shown', async () => {
    const wrapper = await mountAssets([stationAsset, safetyItem])

    const headings = wrapper.findAll('h3').map(heading => heading.text())

    expect(headings).toEqual([ASSET_SAFETY, NAMED_STATION])
  })
})
