import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import type { CorporationMiningExtractionsResponse } from '@/composables/useCorporationMiningExtractions'
import { i18nPlugin } from './miningLedgerHarness'
import {
  CORPORATION_A,
  CORPORATION_B,
  DEEP_CORE_CHUNK,
  DENIED_CORPORATION,
  DENIED_HISTORY_CHUNK,
  EXPLODED_CHUNK,
  LONG_STRUCTURE_NAME,
  NOW,
  READY_CHUNK,
  ROCK_HOLLOW_CHUNK,
  SYNCED_CORPORATION,
  UNNAMED_MOON_ID,
  UNNAMED_STRUCTURE_ID,
  corporation,
  response,
} from './moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

async function mountTab(loaded: CorporationMiningExtractionsResponse) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/mining/corporation-extractions') return Promise.resolve(loaded)
    return Promise.resolve(null)
  })
  const wrapper = mount(MoonChunksTab, { global: { plugins: [i18nPlugin()] } })
  await flushPromises()
  return wrapper
}

type TabWrapper = Awaited<ReturnType<typeof mountTab>>

function present(wrapper: TabWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists(), selector).toBe(true)
  return found
}

function inSection(corporationId: number, selector: string): string {
  return `[data-corporation="${corporationId}"] ${selector}`
}

function rowIds(wrapper: TabWrapper, corporationId: number): string[] {
  return wrapper.findAll(inSection(corporationId, '[data-extraction-id]')).map(row => row.attributes('data-extraction-id') ?? '')
}

function cell(wrapper: TabWrapper, extractionId: string, name: string) {
  return present(wrapper, `[data-extraction-id="${extractionId}"] [data-cell="${name}"]`)
}

describe('MoonChunksTab list', () => {
  it('loads the corporation extractions when it is shown', async () => {
    await mountTab(response(SYNCED_CORPORATION))

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toEqual(['/mining/corporation-extractions'])
  })

  it('lists the chunks of each corporation by arrival', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION, DENIED_CORPORATION))

    expect(rowIds(wrapper, CORPORATION_A)).toEqual([EXPLODED_CHUNK.id, READY_CHUNK.id, ROCK_HOLLOW_CHUNK.id, DEEP_CORE_CHUNK.id])
    expect(rowIds(wrapper, CORPORATION_B)).toEqual([DENIED_HISTORY_CHUNK.id])
  })

  it('shows the moon, refinery, system, arrival, natural decay and countdown of a chunk in EVE time', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))

    expect(['moon', 'structure', 'system', 'arrival', 'decay', 'countdown'].map(name => cell(wrapper, ROCK_HOLLOW_CHUNK.id, name).text())).toEqual([
      'NOL-M9 VII - Moon 3',
      'NOL-M9 - Rock Hollow',
      'NOL-M9',
      '2026-11-05 20:00',
      '2026-11-05 23:00',
      '1h 00m',
    ])
  })

  it('shows the status of each chunk as text, not by colour alone', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))

    const statuses = [EXPLODED_CHUNK, READY_CHUNK, ROCK_HOLLOW_CHUNK].map(chunk => cell(wrapper, chunk.id, 'status'))
    const labels = statuses.map(status => status.text())

    expect(statuses.map(status => status.attributes('data-status'))).toEqual(['exploded', 'ready', 'extracting'])
    expect(labels.every(label => label.length > 0)).toBe(true)
    expect(new Set(labels).size).toBe(3)
  })

  it('shows the full long name of a refinery', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))

    expect(cell(wrapper, DEEP_CORE_CHUNK.id, 'structure').text()).toBe(LONG_STRUCTURE_NAME)
  })

  it('shows the id of an unnamed moon and flags its name as unknown', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))
    const moon = cell(wrapper, READY_CHUNK.id, 'moon')

    expect(moon.text()).toContain(String(UNNAMED_MOON_ID))
    expect(moon.find('[data-unknown-name]').exists()).toBe(true)
    expect(cell(wrapper, ROCK_HOLLOW_CHUNK.id, 'moon').find('[data-unknown-name]').exists()).toBe(false)
  })

  it('shows the id of an unnamed refinery and flags its name as unknown', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION))
    const structure = cell(wrapper, DENIED_HISTORY_CHUNK.id, 'structure')

    expect(structure.text()).toContain(String(UNNAMED_STRUCTURE_ID))
    expect(structure.find('[data-unknown-name]').exists()).toBe(true)
  })

  it('converts the chunk times to the local time zone on demand', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))
    expect(present(wrapper, '[data-time-mode="eve"]').attributes('aria-pressed')).toBe('true')

    await present(wrapper, '[data-time-mode="local"]').trigger('click')

    const localTime = new Intl.DateTimeFormat('en-CA', {
      timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .format(new Date(ROCK_HOLLOW_CHUNK.chunkArrivalTime))
      .replace(',', '')
    expect(present(wrapper, '[data-time-mode="local"]').attributes('aria-pressed')).toBe('true')
    expect(cell(wrapper, ROCK_HOLLOW_CHUNK.id, 'arrival').text()).toBe(localTime)
  })
})

describe('MoonChunksTab access states', () => {
  it('says the Station_Manager role is missing with the last successful sync and keeps the history readable', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION))

    expect(present(wrapper, inSection(CORPORATION_B, '[data-access-state]')).attributes('data-access-state')).toBe('denied')
    expect(present(wrapper, inSection(CORPORATION_B, '[data-access-state] [data-last-success-at]')).text()).toBe('2026-11-04 10:00')
    expect(rowIds(wrapper, CORPORATION_B)).toEqual([DENIED_HISTORY_CHUNK.id])
    expect(wrapper.find(inSection(CORPORATION_B, '[data-empty-extractions]')).exists()).toBe(false)
  })

  it('never presents a denied corporation without history as having no extraction', async () => {
    const wrapper = await mountTab(response(corporation({ corporationId: CORPORATION_B, access: 'denied', lastSuccessAt: null })))

    expect(present(wrapper, inSection(CORPORATION_B, '[data-access-state]')).attributes('data-access-state')).toBe('denied')
    expect(wrapper.find(inSection(CORPORATION_B, '[data-last-success-at]')).exists()).toBe(false)
    expect(wrapper.find(inSection(CORPORATION_B, '[data-empty-extractions]')).exists()).toBe(false)
  })

  it('says a corporation was never synced', async () => {
    const wrapper = await mountTab(response(corporation({ access: 'never_synced', lastSuccessAt: null })))

    expect(present(wrapper, inSection(CORPORATION_A, '[data-access-state]')).attributes('data-access-state')).toBe('never_synced')
    expect(wrapper.find(inSection(CORPORATION_A, '[data-empty-extractions]')).exists()).toBe(false)
  })

  it('says the ESI read failed with the last successful sync', async () => {
    const wrapper = await mountTab(response(corporation({ access: 'error', lastSuccessAt: '2026-11-05T16:00:00+00:00', extractions: [ROCK_HOLLOW_CHUNK] })))

    expect(present(wrapper, inSection(CORPORATION_A, '[data-access-state]')).attributes('data-access-state')).toBe('error')
    expect(present(wrapper, inSection(CORPORATION_A, '[data-access-state] [data-last-success-at]')).text()).toBe('2026-11-05 16:00')
    expect(rowIds(wrapper, CORPORATION_A)).toEqual([ROCK_HOLLOW_CHUNK.id])
  })

  it('says there is no extraction only for a corporation read successfully with an empty list', async () => {
    const wrapper = await mountTab(response(corporation({ extractions: [] })))

    expect(wrapper.find(inSection(CORPORATION_A, '[data-empty-extractions]')).exists()).toBe(true)
    expect(wrapper.find(inSection(CORPORATION_A, '[data-access-state]')).exists()).toBe(false)
  })
})

describe('MoonChunksTab calendar', () => {
  it('places a Thursday 20:00 chunk in the Thursday cell of the month at 20:00 EVE time', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))

    await present(wrapper, '[data-view="month"]').trigger('click')

    expect(present(wrapper, `[data-calendar-day="2026-11-05"] [data-chunk="${ROCK_HOLLOW_CHUNK.id}"] [data-chunk-time]`).text()).toBe('20:00')
    expect(present(wrapper, `[data-calendar-day="2026-11-08"] [data-chunk="${DEEP_CORE_CHUNK.id}"] [data-chunk-time]`).text()).toBe('06:15')
    expect(wrapper.findAll(`[data-chunk="${ROCK_HOLLOW_CHUNK.id}"]`)).toHaveLength(1)
  })

  it('shows the week of today from Monday to Sunday with its chunks', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))

    await present(wrapper, '[data-view="week"]').trigger('click')

    expect(wrapper.findAll('[data-calendar-day]').map(day => day.attributes('data-calendar-day'))).toEqual([
      '2026-11-02',
      '2026-11-03',
      '2026-11-04',
      '2026-11-05',
      '2026-11-06',
      '2026-11-07',
      '2026-11-08',
    ])
    expect(wrapper.findAll('[data-calendar-day="2026-11-05"] [data-chunk]').map(chunk => chunk.attributes('data-chunk'))).toEqual([
      READY_CHUNK.id,
      ROCK_HOLLOW_CHUNK.id,
    ])
    expect(wrapper.find(`[data-chunk="${EXPLODED_CHUNK.id}"]`).exists()).toBe(false)
  })
})

describe('MoonChunksTab refresh', () => {
  it('asks for a sync and shows its Mercure progress while the refresh button is disabled', async () => {
    const wrapper = await mountTab(response(SYNCED_CORPORATION))

    await present(wrapper, 'button[data-refresh]').trigger('click')
    useSyncStore().syncStatus['corporation-mining-extractions'] = {
      syncType: 'corporation-mining-extractions',
      status: 'in_progress',
      progress: 40,
      message: null,
      data: null,
      timestamp: '2026-11-05T19:00:05+00:00',
    }
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => [endpoint, options?.method?.toUpperCase() ?? 'GET'])).toEqual([
      ['/mining/corporation-extractions', 'GET'],
      ['/mining/corporation-extractions/sync', 'POST'],
    ])
    expect(present(wrapper, '[data-sync-progress]').text()).toContain('40')
    expect(present(wrapper, 'button[data-refresh]').attributes('disabled')).toBeDefined()
  })
})
