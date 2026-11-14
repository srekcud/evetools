import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const ALPHA = 'Alpha Station'
const BRAVO = 'Bravo Station'
const CHARLIE = 'Charlie Station'
const DELTA = 'Delta Station'
const RIFTER = 587
const TRITANIUM = 34
const CARGO_CONTAINER = 3296
const STATION_WAREHOUSE_CONTAINER = 17368
const UNLISTED_TYPE = 999001
const BOX_ITEM_ID = 1050000000080
const WAREHOUSE_ITEM_ID = 1050000000090

function asset(id: number, overrides: Record<string, unknown>) {
  return {
    id: `00000000-0000-4000-8000-${String(id).padStart(12, '0')}`,
    itemId: 1050000000000 + id,
    typeId: TRITANIUM,
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
  asset(1, { locationName: ALPHA, locationId: 60000001, typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 3, isSingleton: false }),
  asset(2, { locationName: BRAVO, locationId: 60000002, quantity: 1000 }),
  asset(80, { itemId: BOX_ITEM_ID, locationName: BRAVO, locationId: 60000002, typeId: CARGO_CONTAINER, typeName: 'Cargo Container', quantity: 1, isSingleton: true }),
  asset(81, { locationName: BRAVO, locationId: BOX_ITEM_ID, locationType: 'item', typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 2 }),
  asset(90, { itemId: WAREHOUSE_ITEM_ID, locationName: CHARLIE, locationId: 60000003, typeId: STATION_WAREHOUSE_CONTAINER, typeName: 'Station Warehouse Container', quantity: 1, isSingleton: true }),
  asset(91, { locationName: CHARLIE, locationId: WAREHOUSE_ITEM_ID, locationType: 'item', quantity: 100 }),
  asset(92, { locationName: DELTA, locationId: 60000004, typeId: UNLISTED_TYPE, typeName: 'Mystery Crate', quantity: 4 }),
]

const volumesAnswer = {
  id: 'packaged-volumes',
  volumes: { [RIFTER]: 2500, [TRITANIUM]: 0.01, [CARGO_CONTAINER]: 100, [STATION_WAREHOUSE_CONTAINER]: 10000000 },
  excludedTypeIds: [17366, 17367, 17368],
  refreshedAt: '2026-11-10T04:00:00+00:00',
}

const writeText = vi.fn<(text: string) => Promise<void>>()
let volumesResponse: () => Response

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
  vi.stubGlobal('fetch', vi.fn(assetBrowseFetch(rawAssets, url => (url.includes('/me/assets/packaged-volumes') ? volumesResponse() : null))))
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

type MountedAssets = Awaited<ReturnType<typeof mountAssets>>

function groupOf(wrapper: MountedAssets, locationName: string) {
  const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.text().includes(locationName))
  if (group === undefined) {
    throw new Error(`No location group for ${locationName}`)
  }
  return group
}

function locationNames(wrapper: MountedAssets): string[] {
  return wrapper.findAll('[data-testid="location-group"] h3').map(title => title.text())
}

async function selectLocation(wrapper: MountedAssets, locationName: string): Promise<void> {
  await wrapper.get(`[aria-label="Select ${locationName}"]`).trigger('click')
  await flushPromises()
}

async function sortByVolume(wrapper: MountedAssets): Promise<void> {
  await wrapper.get('[data-testid="location-sort"]').setValue('volume')
  await flushPromises()
}

async function openLocation(wrapper: MountedAssets, locationName: string): Promise<void> {
  await groupOf(wrapper, locationName).get('[aria-expanded]').trigger('click')
  await flushPromises()
}

describe('Assets packaged volumes', () => {
  beforeEach(() => {
    writeText.mockReset()
    writeText.mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    volumesResponse = () => new Response(JSON.stringify(volumesAnswer), { status: 200 })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the packaged volume of each location', async () => {
    const wrapper = await mountAssets()
    await sortByVolume(wrapper)

    expect(groupOf(wrapper, ALPHA).get('[data-testid="location-volume"]').text()).toContain('7,500 m³')
    expect(groupOf(wrapper, BRAVO).get('[data-testid="location-volume"]').text()).toContain('5,110 m³')
  })

  it('leaves the station container out of a location total but keeps its contents', async () => {
    const wrapper = await mountAssets()
    await sortByVolume(wrapper)

    expect(groupOf(wrapper, CHARLIE).get('[data-testid="location-volume"]').text()).toContain('1 m³')
  })

  it('says how many types have no volume instead of counting them as zero', async () => {
    const wrapper = await mountAssets()
    await sortByVolume(wrapper)

    expect(groupOf(wrapper, DELTA).get('[data-testid="volume-unknown"]').text()).toContain('1 type without volume')
    expect(groupOf(wrapper, ALPHA).find('[data-testid="volume-unknown"]').exists()).toBe(false)
  })

  it('shows the volume of a container with its contents', async () => {
    const wrapper = await mountAssets()
    await sortByVolume(wrapper)
    await openLocation(wrapper, BRAVO)

    const containerRow = groupOf(wrapper, BRAVO).get(`[data-asset-item-id="${BOX_ITEM_ID}"]`)

    expect(containerRow.get('[data-testid="container-volume"]').text()).toContain('5,100 m³')
  })

  it('sorts the locations by volume, the largest first', async () => {
    const wrapper = await mountAssets()

    await sortByVolume(wrapper)

    expect(locationNames(wrapper)).toEqual([ALPHA, BRAVO, CHARLIE, DELTA])
  })

  it('shows the volume of the selection in the selection bar', async () => {
    const wrapper = await mountAssets()

    await selectLocation(wrapper, ALPHA)
    await selectLocation(wrapper, BRAVO)

    expect(wrapper.get('[data-testid="asset-selection-volume"]').text()).toContain('12,610 m³')
  })

  it('opens a courier contract draft with the volume and the origin, and copies it', async () => {
    const wrapper = await mountAssets()
    await selectLocation(wrapper, ALPHA)

    await wrapper.get('[data-testid="courier-contract-open"]').trigger('click')

    const panel = wrapper.get('[data-testid="courier-contract-panel"]')
    expect(panel.text()).toContain('7,500 m³')
    expect(panel.text()).toContain(ALPHA)

    await wrapper.get('[data-testid="courier-contract-copy"]').trigger('click')

    expect(writeText).toHaveBeenCalledTimes(1)
    expect(writeText.mock.calls[0][0]).toContain('7,500 m³')
    expect(writeText.mock.calls[0][0]).toContain(ALPHA)
  })

  it('disables the courier contract when the selection has no known volume', async () => {
    const wrapper = await mountAssets()
    await selectLocation(wrapper, DELTA)

    const button = wrapper.get('[data-testid="courier-contract-open"]')

    expect((button.element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.get('[data-testid="asset-selection-volume"]').text()).toContain('0 m³')
  })

  it('shows a notice and no volume when the table is still empty', async () => {
    volumesResponse = () => new Response(JSON.stringify({ ...volumesAnswer, volumes: [], refreshedAt: null }), { status: 200 })

    const wrapper = await mountAssets()

    expect(wrapper.get('[data-testid="packaged-volumes-notice"]').text()).toContain('Packaged volumes are not loaded yet')
    expect(wrapper.find('[data-testid="location-volume"]').exists()).toBe(false)
  })

  it('still lists the assets and shows the notice when the volumes cannot be read', async () => {
    volumesResponse = () => new Response('{}', { status: 500 })
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    const wrapper = await mountAssets()

    expect(wrapper.findAll('[data-testid="location-group"]')).toHaveLength(4)
    expect(wrapper.find('[data-testid="packaged-volumes-notice"]').exists()).toBe(true)
  })
})
