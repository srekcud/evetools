import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import ReprocessingProfilePanel from '@/components/ledger/ReprocessingProfilePanel.vue'
import type { OreValueTableResponse } from '@/composables/useOreValueTable'
import type { ReprocessingProfile, ReprocessingProfileInput } from '@/composables/useReprocessingProfile'
import { apiRequest } from '@/services/api'
import { mountOreValueTab } from './oreValueTabHarness'
import { VELDSPAR_TYPE_ID, oreValueRow } from './oreValueTableHarness'
import { valuationProfileServer } from './miningValuationProfileHarness'
import {
  COHERENT_ORE_PROCESSING,
  ESI_ERROR_PROFILE,
  NPC_STATION_PROFILE,
  PILOT_ONE,
  PILOT_TWO,
  PILOT_TWO_ID,
  TATARA_PROFILE,
  UNCONFIGURED_PROFILE,
} from './reprocessingProfileHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TATARA_INPUT: ReprocessingProfileInput = {
  characterId: PILOT_TWO_ID,
  oreProcessingSkillTypeId: COHERENT_ORE_PROCESSING,
  location: 'tatara',
  rigTier: 't2',
  security: 'nullsec',
  implantPercent: 1,
}

const REFRESHED_PROFILE: ReprocessingProfile = { ...ESI_ERROR_PROFILE, reprocessingLevel: 5, reprocessingEfficiencyLevel: 4, oreProcessingLevel: 3, reprocessYield: 0.6912, failure: null }

function oreValueTable(reprocessYieldKnown: boolean): OreValueTableResponse {
  return {
    market: 'jita',
    side: 'sell',
    structureId: null,
    reprocessYieldKnown,
    rows: [oreValueRow({
      rawIskPerM3: 183.7,
      compressedIskPerM3: 196.1,
      reprocessedIskPerM3: reprocessYieldKnown ? 133.224 : null,
      bestOption: 'compressed',
      bestIskPerM3: 196.1,
      gapToBestPercent: 0,
    })],
  }
}

let loadedProfile: () => Promise<ReprocessingProfile>

const valuationProfile = valuationProfileServer()

function respond(endpoint: string, options?: RequestInit): Promise<unknown> {
  return valuationProfile.respondingWith(respondToOreValueTab)(endpoint, options)
}

function respondToOreValueTab(endpoint: string, options?: RequestInit): Promise<unknown> {
  const method = options?.method?.toUpperCase() ?? 'GET'
  if (endpoint.startsWith('/mining/ore-values')) {
    return Promise.resolve(oreValueTable(new URLSearchParams(endpoint.split('?')[1]).has('reprocessYield')))
  }
  if (endpoint === '/mining/reprocessing-profile/refresh' && method === 'POST') return Promise.resolve(REFRESHED_PROFILE)
  if (endpoint === '/mining/reprocessing-profile' && method === 'PUT') return Promise.resolve(TATARA_PROFILE)
  if (endpoint === '/mining/reprocessing-profile') return loadedProfile()
  return new Promise(() => {})
}

function oreValueQueries(): URLSearchParams[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => endpoint)
    .filter(endpoint => endpoint.startsWith('/mining/ore-values'))
    .map(endpoint => new URLSearchParams(endpoint.split('?')[1]))
}

function lastOreValueQuery(): URLSearchParams {
  const queries = oreValueQueries()
  const last = queries[queries.length - 1]
  if (last === undefined) throw new Error('The ore value table was never requested')
  return last
}

function mountTab() {
  return mountOreValueTab([PILOT_ONE, PILOT_TWO])
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(respond)
  loadedProfile = () => Promise.resolve(NPC_STATION_PROFILE)
})

afterEach(() => {
  localStorage.clear()
})

describe('OreValueTab reprocess yield from the reprocessing profile', () => {
  it('sends the known reprocess yield of the profile to the ore value table', async () => {
    localStorage.setItem('miningReprocessYield', '78')

    const wrapper = await mountTab()

    expect(lastOreValueQuery().get('reprocessYield')).toBe('0.72358')
    expect(wrapper.find('[data-reprocess-yield-missing]').exists()).toBe(false)
  })

  it('sends no reprocess yield without a profile, even with a yield stored in the browser', async () => {
    localStorage.setItem('miningReprocessYield', '78')
    loadedProfile = () => Promise.resolve(UNCONFIGURED_PROFILE)

    const wrapper = await mountTab()

    expect(oreValueQueries().length).toBeGreaterThan(0)
    expect(oreValueQueries().map(query => query.has('reprocessYield'))).not.toContain(true)
    expect(wrapper.find('[data-reprocess-yield-missing]').exists()).toBe(true)
    expect(wrapper.get(`tbody tr[data-type-id="${VELDSPAR_TYPE_ID}"]`).get('td[data-column="reprocessed"]').text()).toContain('—')
  })

  it('sends no reprocess yield when the skills of the profile were unreadable', async () => {
    loadedProfile = () => Promise.resolve(ESI_ERROR_PROFILE)

    const wrapper = await mountTab()

    expect(oreValueQueries().length).toBeGreaterThan(0)
    expect(oreValueQueries().map(query => query.has('reprocessYield'))).not.toContain(true)
    expect(wrapper.find('[data-reprocess-yield-missing]').exists()).toBe(true)
  })

  it('still shows the ore value table without reprocess yield when the profile cannot be loaded', async () => {
    loadedProfile = () => Promise.reject(new Error('HTTP 500'))
    vi.spyOn(console, 'error').mockImplementation(() => {})

    const wrapper = await mountTab()

    expect(oreValueQueries().length).toBeGreaterThan(0)
    expect(oreValueQueries().map(query => query.has('reprocessYield'))).not.toContain(true)
    expect(wrapper.find(`tbody tr[data-type-id="${VELDSPAR_TYPE_ID}"]`).exists()).toBe(true)
  })

  it('shows the reprocessing profile panel with the loaded profile and the characters of the user', async () => {
    loadedProfile = () => Promise.resolve(TATARA_PROFILE)

    const wrapper = await mountTab()

    const panel = wrapper.findComponent(ReprocessingProfilePanel)
    expect(panel.exists()).toBe(true)
    expect(panel.props('profile')).toEqual(TATARA_PROFILE)
    expect(panel.props('characters')).toEqual([PILOT_ONE, PILOT_TWO])
  })

  it('saves the settings of the panel and reloads the table with the saved yield', async () => {
    const wrapper = await mountTab()

    const panel = wrapper.findComponent(ReprocessingProfilePanel)
    expect(panel.exists()).toBe(true)
    panel.vm.$emit('save', TATARA_INPUT)
    await flushPromises()

    const put = vi.mocked(apiRequest).mock.calls.find(([endpoint, options]) => endpoint === '/mining/reprocessing-profile' && options?.method === 'PUT')
    expect(put === undefined ? null : JSON.parse(String(put[1]?.body))).toEqual(TATARA_INPUT)
    expect(lastOreValueQuery().get('reprocessYield')).toBe('0.7809530995302404')
  })

  it('recomputes the profile from the panel and reloads the table with the recomputed yield', async () => {
    loadedProfile = () => Promise.resolve(ESI_ERROR_PROFILE)
    const wrapper = await mountTab()

    const panel = wrapper.findComponent(ReprocessingProfilePanel)
    expect(panel.exists()).toBe(true)
    panel.vm.$emit('refresh')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.some(([endpoint, options]) => endpoint === '/mining/reprocessing-profile/refresh' && options?.method === 'POST')).toBe(true)
    expect(lastOreValueQuery().get('reprocessYield')).toBe('0.6912')
    expect(wrapper.find('[data-reprocess-yield-missing]').exists()).toBe(false)
  })
})
