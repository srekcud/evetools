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
const UNRESOLVED_STRUCTURE = 'Structure #1060000000013'
const UNKNOWN_STRUCTURE_OFFICE = 'Office — Unknown structure (0UBC-R)'
const ASSET_SAFETY = 'Asset Safety (Amarr)'

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
    cachedAt: '2026-11-13T10:00:00+00:00',
    ...overrides,
  }
}

const rawAssets = [
  asset(1, { locationName: NAMED_STATION, solarSystemId: 30000142, solarSystemName: 'Jita' }),
  asset(2, { locationName: UNRESOLVED_STRUCTURE, locationId: 1060000000013, solarSystemId: 30004000, solarSystemName: '0UBC-R' }),
  asset(3, { locationName: UNKNOWN_STRUCTURE_OFFICE, locationId: 3100000011, solarSystemId: 30004000, solarSystemName: '0UBC-R' }),
  asset(4, { locationName: ASSET_SAFETY, locationId: 3200000011, solarSystemId: 30002187, solarSystemName: 'Amarr' }),
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

function locationSelect(wrapper: Awaited<ReturnType<typeof mountAssets>>) {
  const select = wrapper.findAll('select').find(candidate => candidate.attributes('aria-label') === fr.assets.location)
  if (!select) {
    throw new Error('location filter not rendered')
  }
  return select
}

describe('Assets with unresolved or unnamed locations', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('keeps an unresolved structure in the location filter under an explicit label', async () => {
    const wrapper = await mountAssets()

    const options = locationSelect(wrapper).findAll('option').slice(1).map(option => option.text())

    expect(options).toContain('Structure inconnue (0UBC-R)')
  })

  it('filters on an unresolved structure through its option', async () => {
    const wrapper = await mountAssets()

    await locationSelect(wrapper).setValue(UNRESOLVED_STRUCTURE)
    await flushPromises()
    const headings = wrapper.findAll('h3').map(heading => heading.text())

    expect(headings).toEqual([UNRESOLVED_STRUCTURE])
  })

  it('keeps the named locations in the filter next to the unresolved one', async () => {
    const wrapper = await mountAssets()

    const options = locationSelect(wrapper).findAll('option').slice(1).map(option => option.text())

    expect(options).toContain(NAMED_STATION)
    expect(options).toContain(ASSET_SAFETY)
    expect(options).toHaveLength(4)
  })

  it('badges an unresolved structure with its reason in a tooltip', async () => {
    const wrapper = await mountAssets()

    const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.text().includes(UNRESOLVED_STRUCTURE))
    const badge = group?.find('[data-testid="location-badge"]')

    expect(badge?.text()).toBe('Structure non résolue')
    expect(badge?.attributes('title')).toBeTruthy()
  })

  it('badges a location with a missing name with its reason in a tooltip', async () => {
    const wrapper = await mountAssets()

    const badgeTexts = wrapper.findAll('[data-testid="location-group"]')
      .filter(group => group.text().includes(UNKNOWN_STRUCTURE_OFFICE) || group.text().includes(ASSET_SAFETY))
      .map(group => group.find('[data-testid="location-badge"]').text())

    expect(badgeTexts).toEqual(['Nom manquant', 'Nom manquant'])
  })

  it('shows no badge on a normally named location', async () => {
    const wrapper = await mountAssets()

    const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.text().includes(NAMED_STATION))

    expect(group?.exists()).toBe(true)
    expect(group?.find('[data-testid="location-badge"]').exists()).toBe(false)
  })
})
