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
const STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const CONTAINER_ITEM_ID = 1050000000201

function stationAsset(overrides: Record<string, unknown>) {
  return {
    categoryId: 4,
    locationId: 60003760,
    locationName: STATION_NAME,
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    cachedAt: '2026-10-22T10:00:00+00:00',
    ...overrides,
  }
}

function containedAsset(overrides: Record<string, unknown>) {
  return stationAsset({
    locationId: CONTAINER_ITEM_ID,
    locationName: `Project Box — ${STATION_NAME}`,
    locationType: 'item',
    locationFlag: 'Unlocked',
    ...overrides,
  })
}

const rawAssets = [
  stationAsset({ id: '00000000-0000-4000-8000-000000000001', itemId: 1050000000101, typeId: 34, typeName: 'Tritanium', quantity: 8800 }),
  stationAsset({ id: '00000000-0000-4000-8000-000000000002', itemId: CONTAINER_ITEM_ID, typeId: 3296, typeName: 'Station Container', quantity: 1, itemName: 'Project Box' }),
  containedAsset({ id: '00000000-0000-4000-8000-000000000003', itemId: 1050000000301, typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 120 }),
  containedAsset({ id: '00000000-0000-4000-8000-000000000004', itemId: 1050000000302, typeId: 4247, typeName: 'Oxygen Fuel Block', quantity: 45 }),
]

async function mountAssets() {
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

async function searchFor(wrapper: Awaited<ReturnType<typeof mountAssets>>, query: string): Promise<string> {
  await wrapper.find('input[type="text"]').setValue(query)
  await flushPromises()
  return wrapper.text().replace(/\s+/g, ' ')
}

function containerRow(wrapper: Awaited<ReturnType<typeof mountAssets>>) {
  const row = wrapper.findAll('[role="button"]').find(candidate => candidate.text().includes('Station Container'))
  if (!row) {
    throw new Error('container row not rendered')
  }
  return row
}

describe('Assets search inside containers', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows an item stored in a container, expanded under its container', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, 'hydrogen')

    expect(text).toContain('Hydrogen Fuel Block')
    expect(text).toContain('Project Box (Station Container)')
    expect(text).not.toContain('Tritanium')
  })

  it('hides the sibling items of the container that do not match', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, 'hydrogen')

    expect(text).not.toContain('Oxygen Fuel Block')
  })

  it('finds a container by its custom name', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, 'project box')

    expect(text).toContain('Project Box (Station Container)')
    expect(text).not.toContain('Tritanium')
  })

  it('keeps every content of a container found by its own name', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, 'project box')
    await containerRow(wrapper).trigger('click')

    expect(text).toContain('Project Box (Station Container)')
    expect(wrapper.text()).toContain('Hydrogen Fuel Block')
    expect(wrapper.text()).toContain('Oxygen Fuel Block')
  })

  it('lets the user collapse a container opened by the search', async () => {
    const wrapper = await mountAssets()
    await searchFor(wrapper, 'hydrogen')

    await containerRow(wrapper).trigger('click')

    expect(wrapper.text()).not.toContain('Hydrogen Fuel Block')
  })

  it('still finds a top-level item without showing the container', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, 'tritanium')

    expect(text).toContain('Tritanium')
    expect(text).not.toContain('Project Box (Station Container)')
    expect(text).not.toContain('Hydrogen Fuel Block')
  })

  it('keeps container contents collapsed when nothing is searched', async () => {
    const wrapper = await mountAssets()

    const text = wrapper.text().replace(/\s+/g, ' ')

    expect(text).toContain('Project Box (Station Container)')
    expect(text).not.toContain('Hydrogen Fuel Block')
  })
})
