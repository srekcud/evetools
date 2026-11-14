import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const JITA_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const AMARR_NAME = 'Amarr VIII (Oris) - Emperor Family Academy'
const SHIP_CATEGORY_ID = 6
const SEARCH_DEBOUNCE_MS = 300
const COLLAPSE_THRESHOLD_FILLER_COUNT = 201

let nextItemId = 1080000000000

function nextId(): { id: string; itemId: number } {
  nextItemId += 1
  return { id: `00000000-0000-4000-8000-${String(nextItemId % 1000000000000).padStart(12, '0')}`, itemId: nextItemId }
}

function stationAsset(overrides: Record<string, unknown>) {
  return {
    ...nextId(),
    categoryId: 4,
    locationId: 60003760,
    locationName: JITA_NAME,
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    cachedAt: '2026-11-13T10:00:00+00:00',
    ...overrides,
  }
}

function ship(typeName: string, itemName: string | null, overrides: Record<string, unknown> = {}) {
  return stationAsset({
    typeId: typeName === 'Rifter' ? 587 : 597,
    typeName,
    categoryId: SHIP_CATEGORY_ID,
    quantity: 1,
    isSingleton: true,
    itemName,
    ...overrides,
  })
}

function tritanium() {
  return stationAsset({ typeId: 34, typeName: 'Tritanium', quantity: 8800 })
}

function inventoryWithShips(): Record<string, unknown>[] {
  return [
    ship('Rifter', 'Doctrine Alpha'),
    ship('Rifter', 'Doctrine Alpha'),
    ship('Punisher', 'Frégate Béta'),
    tritanium(),
  ]
}

function largeInventoryWithShipsInOtherStation(): Record<string, unknown>[] {
  const fillers = Array.from({ length: COLLAPSE_THRESHOLD_FILLER_COUNT }, () =>
    stationAsset({ typeId: 34, typeName: 'Tritanium', quantity: 1 }),
  )
  const otherStationOverrides = { locationId: 60008494, locationName: AMARR_NAME, solarSystemName: 'Amarr' }
  return [
    ...fillers,
    ship('Rifter', 'Spare Hull', otherStationOverrides),
    ship('Rifter', 'Spare Hull', otherStationOverrides),
  ]
}

async function mountAssets(items: Record<string, unknown>[]) {
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
  vi.stubGlobal('fetch', vi.fn(assetBrowseFetch(items)))
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

async function searchFor(wrapper: Awaited<ReturnType<typeof mountAssets>>, query: string): Promise<string> {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  await wrapper.find('input[type="text"]').setValue(query)
  await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
  await flushPromises()
  return wrapper.text().replace(/\s+/g, ' ')
}

describe('Assets search on ship groups', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('finds a ship group by its ship type', async () => {
    const wrapper = await mountAssets(inventoryWithShips())

    const text = await searchFor(wrapper, 'rifter')

    expect(text).toContain('Rifter · Doctrine Alpha ×2')
    expect(text).not.toContain('Punisher')
    expect(text).not.toContain('Tritanium')
  })

  it('finds a ship group by its custom name', async () => {
    const wrapper = await mountAssets(inventoryWithShips())

    const text = await searchFor(wrapper, 'doctrine alpha')

    expect(text).toContain('Rifter · Doctrine Alpha ×2')
    expect(text).not.toContain('Punisher')
  })

  it('finds a ship group from an unaccented and differently cased name', async () => {
    const wrapper = await mountAssets(inventoryWithShips())

    const text = await searchFor(wrapper, 'FREGATE beta')

    expect(text).toContain('Punisher · Frégate Béta ×1')
    expect(text).not.toContain('Rifter')
  })

  it('shows no ship group when neither the type nor the name matches', async () => {
    const wrapper = await mountAssets(inventoryWithShips())

    const text = await searchFor(wrapper, 'tritanium')

    expect(text).toContain('Tritanium')
    expect(text).not.toContain('Rifter')
    expect(text).not.toContain('Punisher')
  })

  it('opens a collapsed location matched only through a ship group name', async () => {
    const wrapper = await mountAssets(largeInventoryWithShipsInOtherStation())

    const text = await searchFor(wrapper, 'spare hull')

    expect(text).toContain('Rifter · Spare Hull ×2')
    expect(text).not.toContain('Tritanium')
  })

  it('opens a collapsed location matched only through a ship group type', async () => {
    const wrapper = await mountAssets(largeInventoryWithShipsInOtherStation())

    const text = await searchFor(wrapper, 'rifter')

    expect(text).toContain('Rifter · Spare Hull ×2')
  })

  it('keeps a collapsed location closed when only its name matches', async () => {
    const wrapper = await mountAssets(largeInventoryWithShipsInOtherStation())

    const text = await searchFor(wrapper, 'amarr')

    expect(text).toContain(AMARR_NAME)
    expect(text).not.toContain('Rifter · Spare Hull')
  })
})
