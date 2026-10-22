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
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'

const rawAssets = [
  {
    id: '00000000-0000-4000-8000-000000000001',
    itemId: 1050000000101,
    typeId: 34,
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
  },
  {
    id: '00000000-0000-4000-8000-000000000002',
    itemId: 1050000000102,
    typeId: 9848,
    typeName: null,
    categoryId: null,
    quantity: 6300,
    locationId: 60003760,
    locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    cachedAt: '2026-10-22T10:00:00+00:00',
  },
  {
    id: '00000000-0000-4000-8000-000000000003',
    itemId: 1050000000103,
    typeId: 35,
    typeName: 'Pyerite',
    categoryId: 4,
    quantity: 2100,
    locationId: 60003760,
    locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    cachedAt: '2026-10-22T10:00:00+00:00',
  },
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

describe('Assets search with an unknown type name', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('finds the asset without type name by its type id', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, '9848')

    expect(text).toContain('Nom inconnu (#9848)')
    expect(text).not.toContain('Tritanium')
    expect(text).not.toContain('Pyerite')
    expect(text).not.toContain('Failed to load assets')
  })

  it('finds the asset without type name by the unknown type name wording', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, 'inconnu')

    expect(text).toContain('Nom inconnu (#9848)')
    expect(text).not.toContain('Tritanium')
    expect(text).not.toContain('Pyerite')
  })

  it('still finds a named asset by its type name', async () => {
    const wrapper = await mountAssets()

    const text = await searchFor(wrapper, 'pyer')

    expect(text).toContain('Pyerite')
    expect(text).not.toContain('Tritanium')
    expect(text).not.toContain('Nom inconnu (#9848)')
  })
})
