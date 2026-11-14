import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
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

const HOME_STATION = 'Home Station'
const MARKET_STATION = 'Jita IV - Moon 4'
const TRITANIUM = 34
const PYERITE = 35
const UNNAMED_TYPE_ID = 999001
const SEARCH_DEBOUNCE_MS = 300

function row(itemId: number, typeId: number, typeName: string | null, quantity: number, locationName: string) {
  return {
    id: `00000000-0000-4000-8000-${String(itemId).padStart(12, '0')}`,
    itemId,
    typeId,
    typeName,
    quantity,
    categoryId: 4,
    locationId: locationName === HOME_STATION ? 1035466617946 : 60003760,
    locationName,
    locationType: 'station',
    locationFlag: 'Hangar',
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    cachedAt: '2026-11-12T10:00:00+00:00',
  }
}

const rawAssets = [
  row(1, TRITANIUM, 'Tritanium', 8800, HOME_STATION),
  row(2, TRITANIUM, 'Tritanium', 1200, MARKET_STATION),
  row(3, PYERITE, 'Pyerite', 500, HOME_STATION),
]

const volumesAnswer = {
  id: 'packaged-volumes',
  volumes: { [TRITANIUM]: 0.01, [PYERITE]: 0.5 },
  excludedTypeIds: [17366, 17367, 17368],
  refreshedAt: '2026-11-10T04:00:00+00:00',
}

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
      id: '00000000-0000-4000-8000-0000000000c1',
      eveCharacterId: 2112000001,
      name: 'Main Pilot',
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
  vi.stubGlobal('fetch', vi.fn(assetBrowseFetch(items, url => (
    url.includes('/me/assets/packaged-volumes') ? new Response(JSON.stringify(volumesAnswer), { status: 200 }) : null
  ))))
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PatrimoineModuleTabs: true,
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountAssets>>

async function openTypeView(wrapper: Wrapper) {
  const toggle = wrapper.findAll('button').find(button => button.text() === en.assets.groupBy.type)
  if (!toggle) {
    throw new Error('the By type toggle is not rendered')
  }
  await toggle.trigger('click')
  await flushPromises()
}

async function selectType(wrapper: Wrapper, typeName: string): Promise<void> {
  await wrapper.get(`[aria-label="Select ${typeName}"]`).trigger('click')
  await flushPromises()
}

const bar = (wrapper: Wrapper) => wrapper.find('[data-testid="asset-selection-bar"]')

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  push.mockReset()
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('Assets By type row selection', () => {
  it('shows a checkbox on each type and no selection bar while nothing is selected', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    expect(wrapper.findAll('[data-testid="type-select"]')).toHaveLength(2)
    expect(bar(wrapper).exists()).toBe(false)
  })

  it('shows the number of types, the items and the total packaged volume of the selection', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await selectType(wrapper, 'Tritanium')
    await selectType(wrapper, 'Pyerite')

    expect(bar(wrapper).text()).toContain('2 types selected')
    expect(bar(wrapper).text()).toContain('10,500 items')
    expect(wrapper.get('[data-testid="asset-selection-volume"]').text()).toContain('350 m³')
  })

  it('counts a single type in the singular', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await selectType(wrapper, 'Pyerite')

    expect(bar(wrapper).text()).toContain('1 type selected')
    expect(wrapper.get('[data-testid="asset-selection-volume"]').text()).toContain('250 m³')
  })

  it('copies the full quantity of the selected types as a multibuy', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)
    await selectType(wrapper, 'Tritanium')
    await selectType(wrapper, 'Pyerite')

    await wrapper.get('[data-testid="multibuy-copy"]').trigger('click')

    expect(writeText.mock.calls).toEqual([['Pyerite\t500\nTritanium\t10000']])
  })

  it('sends the selected types to Appraisal', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)
    await selectType(wrapper, 'Pyerite')

    await wrapper.get('[data-testid="multibuy-evaluate"]').trigger('click')

    expect(useAppraisalHandoffStore().pendingText).toBe('Pyerite\t500')
    expect(push).toHaveBeenCalledWith({ name: 'appraisal' })
  })

  it('drafts a courier contract from every location holding the selected types', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)
    await selectType(wrapper, 'Tritanium')

    await wrapper.get('[data-testid="courier-contract-open"]').trigger('click')

    const draft = wrapper.get('[data-testid="courier-contract-text"]').text()
    expect(draft).toContain(`${HOME_STATION}, ${MARKET_STATION}`)
    expect(draft).toContain('100 m³')
  })

  it('unselects a type when its checkbox is clicked again', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)
    await selectType(wrapper, 'Tritanium')
    await selectType(wrapper, 'Pyerite')

    await selectType(wrapper, 'Tritanium')

    expect(bar(wrapper).text()).toContain('1 type selected')
    expect(bar(wrapper).text()).toContain('500 items')
  })

  it('clears the selection', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)
    await selectType(wrapper, 'Tritanium')

    await wrapper.get('[data-testid="asset-selection-clear"]').trigger('click')

    expect(bar(wrapper).exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="type-select"]').every(box => !(box.element as HTMLInputElement).checked)).toBe(true)
  })

  it('does not open the locations of a type when its checkbox is clicked', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await selectType(wrapper, 'Tritanium')

    expect(wrapper.find('[data-testid="asset-type-locations"]').exists()).toBe(false)
    expect(wrapper.get('[aria-label="Select Tritanium"]').element.closest('[data-testid="asset-type-row"]')?.querySelector('[aria-expanded="true"]')).toBeNull()
  })

  it('counts only the types still shown when a search hides a selected one', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)
    await selectType(wrapper, 'Tritanium')
    await selectType(wrapper, 'Pyerite')

    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await wrapper.get('input[type="search"], input[type="text"]').setValue('Pyerite')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
    await flushPromises()

    expect(bar(wrapper).text()).toContain('1 type selected')
    expect(bar(wrapper).text()).toContain('500 items')
  })

  it('warns about a type without a name and leaves it out of the multibuy', async () => {
    const wrapper = await mountAssets(rawAssets.concat(row(4, UNNAMED_TYPE_ID, null, 3, HOME_STATION)))
    await openTypeView(wrapper)

    await selectType(wrapper, `Unknown name (#${UNNAMED_TYPE_ID})`)
    await selectType(wrapper, 'Pyerite')
    await wrapper.get('[data-testid="multibuy-copy"]').trigger('click')

    expect(wrapper.get('[data-testid="asset-selection-unknown"]').text()).toContain('1')
    expect(writeText.mock.calls).toEqual([['Pyerite\t500']])
  })

  it('keeps the location selection apart from the type selection', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)
    await selectType(wrapper, 'Pyerite')

    const locationToggle = wrapper.findAll('button').find(button => button.text() === en.assets.groupBy.location)
    await locationToggle?.trigger('click')
    await flushPromises()

    expect(bar(wrapper).exists()).toBe(false)
  })
})
