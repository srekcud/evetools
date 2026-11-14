import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import { useAppraisalHandoffStore } from '@/stores/appraisalHandoff'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

const push = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn(), push })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const ALPHA = 'Alpha Station'
const BRAVO = 'Bravo Station'
const CHARLIE = 'Charlie Station'
const CAN_ITEM_ID = 1050000000090
const SEARCH_DEBOUNCE_MS = 300

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
    canResolveItemName: false,
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

const rawAssets = [
  asset(1, { locationName: ALPHA, locationId: 60000001, quantity: 10 }),
  asset(2, { locationName: BRAVO, locationId: 60000002, quantity: 100 }),
  asset(3, { locationName: BRAVO, locationId: 60000002, quantity: 200, typeId: 35, typeName: 'Pyerite' }),
  asset(90, { itemId: CAN_ITEM_ID, locationName: CHARLIE, locationId: 60000003, quantity: 1, typeId: 3293, typeName: 'Station Container', isSingleton: true }),
  asset(91, { locationName: CHARLIE, locationId: CAN_ITEM_ID, locationType: 'item', quantity: 5, typeId: 36, typeName: 'Mexallon' }),
]

const writeText = vi.fn<(text: string) => Promise<void>>()

async function mountAssets(items: Record<string, unknown>[] = rawAssets) {
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

async function selectLocation(wrapper: MountedAssets, locationName: string): Promise<void> {
  await wrapper.get(`[aria-label="Select ${locationName}"]`).trigger('click')
  await flushPromises()
}

async function openLocation(wrapper: MountedAssets, locationName: string): Promise<void> {
  await wrapper.get(`[aria-label="Select ${locationName}"]`).element.closest('[role="button"]')?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

const bar = (wrapper: MountedAssets) => wrapper.find('[data-testid="asset-selection-bar"]')

describe('Assets row selection', () => {
  beforeEach(() => {
    push.mockReset()
    writeText.mockReset()
    writeText.mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('shows no selection bar while nothing is selected', async () => {
    const wrapper = await mountAssets()

    expect(wrapper.findAll('[data-testid="location-select"]')).toHaveLength(3)
    expect(bar(wrapper).exists()).toBe(false)
  })

  it('shows the number of locations and of items once locations are selected', async () => {
    const wrapper = await mountAssets()

    await selectLocation(wrapper, ALPHA)
    await selectLocation(wrapper, BRAVO)

    expect(bar(wrapper).text()).toContain('2 locations selected')
    expect(bar(wrapper).text()).toContain('310 items')
  })

  it('copies the selection as a multibuy, containers contents included', async () => {
    const wrapper = await mountAssets()
    await selectLocation(wrapper, BRAVO)
    await selectLocation(wrapper, CHARLIE)

    await wrapper.get('[data-testid="multibuy-copy"]').trigger('click')

    expect(writeText.mock.calls).toEqual([['Mexallon\t5\nPyerite\t200\nStation Container\t1\nTritanium\t100']])
  })

  it('sends the selection to Appraisal', async () => {
    const wrapper = await mountAssets()
    await selectLocation(wrapper, ALPHA)
    await selectLocation(wrapper, BRAVO)

    await wrapper.get('[data-testid="multibuy-evaluate"]').trigger('click')

    expect(useAppraisalHandoffStore().pendingText).toBe('Pyerite\t200\nTritanium\t110')
    expect(push).toHaveBeenCalledWith({ name: 'appraisal' })
  })

  it('clears the selection', async () => {
    const wrapper = await mountAssets()
    await selectLocation(wrapper, ALPHA)

    await wrapper.get('[data-testid="asset-selection-clear"]').trigger('click')

    expect(bar(wrapper).exists()).toBe(false)
    expect((wrapper.get('[data-testid="location-select"]').element as HTMLInputElement).checked).toBe(false)
  })

  it('unselects a location when its checkbox is clicked again', async () => {
    const wrapper = await mountAssets()
    await selectLocation(wrapper, ALPHA)
    await selectLocation(wrapper, BRAVO)

    await selectLocation(wrapper, BRAVO)

    expect(bar(wrapper).text()).toContain('1 location selected')
    expect(bar(wrapper).text()).toContain('10 items')
  })

  it('does not collapse a location when its checkbox is clicked', async () => {
    const wrapper = await mountAssets()
    await openLocation(wrapper, ALPHA)

    await selectLocation(wrapper, ALPHA)

    const header = wrapper.get(`[aria-label="Select ${ALPHA}"]`).element.closest('[role="button"]')
    expect(header?.getAttribute('aria-expanded')).toBe('true')
  })

  it('counts only the locations still shown when a search hides a selected one', async () => {
    const wrapper = await mountAssets()
    await selectLocation(wrapper, ALPHA)
    await selectLocation(wrapper, BRAVO)

    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await wrapper.get('input[type="search"], input[type="text"]').setValue('Pyerite')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
    await flushPromises()

    expect(bar(wrapper).text()).toContain('1 location selected')
    expect(bar(wrapper).text()).toContain('200 items')
  })

  it('warns about a type without a name and leaves it out of the multibuy', async () => {
    const unnamed = rawAssets.concat(asset(5, { locationName: ALPHA, locationId: 60000001, quantity: 3, typeId: 999001, typeName: null }))
    const wrapper = await mountAssets(unnamed)

    await selectLocation(wrapper, ALPHA)
    await wrapper.get('[data-testid="multibuy-copy"]').trigger('click')

    expect(wrapper.get('[data-testid="asset-selection-unknown"]').text()).toContain('1')
    expect(writeText.mock.calls).toEqual([['Tritanium\t10']])
  })
})
