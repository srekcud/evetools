import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const NOW = new Date('2026-11-13T12:00:00+00:00')
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
    locationType: 'station',
    locationFlag: 'Hangar',
    itemName: null,
    isSingleton: false,
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

const ALPHA = 'Alpha Station'
const BRAVO = 'Bravo Station'
const CHARLIE = 'Charlie Station'

const rawAssets = [
  asset(1, { locationName: ALPHA, locationId: 60000001, quantity: 10 }),
  asset(2, { locationName: BRAVO, locationId: 60000002, quantity: 100, divisionName: 'INDUSTRIE' }),
  asset(3, { locationName: BRAVO, locationId: 60000002, quantity: 200, divisionName: 'ACCES MEMBRE', typeId: 35 }),
  asset(4, { locationName: CHARLIE, locationId: 60000003, quantity: 50 }),
  asset(5, { locationName: CHARLIE, locationId: 60000003, quantity: 1, typeId: 587, typeName: 'Rifter', categoryId: SHIP_CATEGORY_ID, isSingleton: true }),
  asset(6, { locationName: CHARLIE, locationId: 60000003, quantity: 1, typeId: 587, typeName: 'Rifter', categoryId: SHIP_CATEGORY_ID, isSingleton: true }),
  asset(7, { locationName: CHARLIE, locationId: 60000003, quantity: 5, typeId: 587, typeName: 'Rifter', categoryId: SHIP_CATEGORY_ID, isSingleton: false }),
]

async function mountAssets(items: unknown[] = rawAssets) {
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
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ items }), { status: 200 })))
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
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

type MountedAssets = Awaited<ReturnType<typeof mountAssets>>

function headings(wrapper: MountedAssets): string[] {
  return wrapper.findAll('h3').map(heading => heading.text())
}

describe('Assets location headers', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('sorts the locations by item count, largest first, by default', async () => {
    const wrapper = await mountAssets()

    expect(headings(wrapper)).toEqual([BRAVO, CHARLIE, ALPHA])
  })

  it('sorts the locations by name when asked', async () => {
    const wrapper = await mountAssets()

    await wrapper.get('[data-testid="location-sort"]').setValue('name')

    expect(headings(wrapper)).toEqual([ALPHA, BRAVO, CHARLIE])
  })

  it('goes back to the item count order when asked', async () => {
    const wrapper = await mountAssets()
    const sort = wrapper.get('[data-testid="location-sort"]')

    await sort.setValue('name')
    await sort.setValue('items')

    expect(headings(wrapper)).toEqual([BRAVO, CHARLIE, ALPHA])
  })

  it('breaks an item count tie by name', async () => {
    const wrapper = await mountAssets([
      asset(1, { locationName: 'Zulu Station', locationId: 60000009, quantity: 5 }),
      asset(2, { locationName: ALPHA, locationId: 60000001, quantity: 5 }),
    ])

    expect(headings(wrapper)).toEqual([ALPHA, 'Zulu Station'])
  })

  it('sizes each bar against the largest location', async () => {
    const wrapper = await mountAssets()

    const widths = wrapper.findAll('[data-testid="location-quantity-bar"]').map(bar => bar.attributes('style'))

    expect(widths).toEqual([
      expect.stringContaining('width: 100%'),
      expect.stringContaining('width: 19'),
      expect.stringContaining('width: 3.'),
    ])
  })

  it('counts only the assembled ships of each location', async () => {
    const wrapper = await mountAssets()

    const shipCounts = wrapper.findAll('[data-testid="location-ship-count"]').map(count => count.text())

    expect(shipCounts).toEqual(['2 ships'])
  })

  it('lists the divisions of a location with their item counts', async () => {
    const wrapper = await mountAssets()

    const divisions = wrapper.findAll('[data-testid="location-division"]').map(entry => entry.text())

    expect(divisions).toEqual(['ACCES MEMBRE 200', 'INDUSTRIE 100'])
  })

  it('shows when the inventory was last synced from its newest row', async () => {
    const wrapper = await mountAssets([
      asset(1, { locationName: ALPHA, cachedAt: '2026-11-13T11:30:00+00:00' }),
      asset(2, { locationName: ALPHA, cachedAt: '2026-11-13T11:48:00+00:00' }),
    ])

    expect(wrapper.get('[data-testid="assets-freshness"]').text()).toBe('Synced 12min ago')
  })

  it('says the inventory was synced just now right after a sync', async () => {
    const wrapper = await mountAssets([
      asset(1, { locationName: ALPHA, cachedAt: NOW.toISOString() }),
    ])

    expect(wrapper.get('[data-testid="assets-freshness"]').text()).toBe('Synced just now')
  })

  it('shows no freshness without assets', async () => {
    const wrapper = await mountAssets([])

    expect(wrapper.find('[data-testid="assets-freshness"]').exists()).toBe(false)
  })

  it('keeps the unresolved location badge on its header', async () => {
    const wrapper = await mountAssets([
      asset(1, { locationName: 'Structure #1060000000013', locationId: 1060000000013 }),
    ])

    expect(wrapper.find('[data-testid="location-badge"]').exists()).toBe(true)
  })
})
