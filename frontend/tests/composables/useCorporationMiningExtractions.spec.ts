import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useSyncStore, type SyncStatus } from '@/stores/sync'
import {
  chunkCountdown,
  chunkTimeZone,
  formatChunkTime,
  moonChunkStatusAt,
  useCorporationMiningExtractions,
} from '@/composables/useCorporationMiningExtractions'
import {
  DEEP_CORE_CHUNK,
  DENIED_CORPORATION,
  ROCK_HOLLOW_CHUNK,
  SYNCED_CORPORATION,
  response,
} from '../components/ledger/moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function requests(): Array<{ endpoint: string; method: string; body: unknown }> {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => ({
    endpoint,
    method: options?.method?.toUpperCase() ?? 'GET',
    body: typeof options?.body === 'string' ? JSON.parse(options.body) : null,
  }))
}

function reportSync(status: SyncStatus, progress: number | null): void {
  useSyncStore().syncStatus['corporation-mining-extractions'] = {
    syncType: 'corporation-mining-extractions',
    status,
    progress,
    message: null,
    data: null,
    timestamp: '2026-11-05T19:00:05+00:00',
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('useCorporationMiningExtractions loading', () => {
  it('loads one entry per corporation of the user', async () => {
    vi.mocked(apiRequest).mockResolvedValue(response(SYNCED_CORPORATION, DENIED_CORPORATION))
    const { corporations, hasError, load } = useCorporationMiningExtractions()

    await load()

    expect(requests()).toEqual([{ endpoint: '/mining/corporation-extractions', method: 'GET', body: null }])
    expect(corporations.value).toEqual([SYNCED_CORPORATION, DENIED_CORPORATION])
    expect(hasError.value).toBe(false)
  })

  it('reports an error and keeps the previous corporations when the load fails', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(response(DENIED_CORPORATION)).mockRejectedValueOnce(new Error('HTTP 500'))
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { corporations, hasError, load } = useCorporationMiningExtractions()

    await load()
    await load()

    expect(hasError.value).toBe(true)
    expect(corporations.value).toEqual([DENIED_CORPORATION])
  })
})

describe('useCorporationMiningExtractions refresh', () => {
  it('asks for a sync, follows its Mercure progress and reloads once it completes', async () => {
    vi.mocked(apiRequest)
      .mockResolvedValueOnce(null)
      .mockResolvedValueOnce(response(SYNCED_CORPORATION))
    const { corporations, isRefreshing, syncProgress, refresh } = useCorporationMiningExtractions()

    await refresh()
    reportSync('in_progress', 40)
    await flushPromises()

    expect(isRefreshing.value).toBe(true)
    expect(syncProgress.value?.progress).toBe(40)
    expect(requests()).toEqual([{ endpoint: '/mining/corporation-extractions/sync', method: 'POST', body: {} }])

    reportSync('completed', 100)
    await flushPromises()

    expect(isRefreshing.value).toBe(false)
    expect(requests()).toEqual([
      { endpoint: '/mining/corporation-extractions/sync', method: 'POST', body: {} },
      { endpoint: '/mining/corporation-extractions', method: 'GET', body: null },
    ])
    expect(corporations.value).toEqual([SYNCED_CORPORATION])
  })

  it('stops refreshing without reloading when the sync reports an error', async () => {
    vi.mocked(apiRequest).mockResolvedValue(null)
    const { isRefreshing, refresh } = useCorporationMiningExtractions()

    await refresh()
    reportSync('started', 0)
    await flushPromises()
    expect(isRefreshing.value).toBe(true)

    reportSync('error', null)
    await flushPromises()

    expect(isRefreshing.value).toBe(false)
    expect(requests()).toEqual([{ endpoint: '/mining/corporation-extractions/sync', method: 'POST', body: {} }])
  })

  it('reports an error and stops refreshing when the sync request is refused', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('HTTP 503'))
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { hasError, isRefreshing, refresh } = useCorporationMiningExtractions()

    await refresh()

    expect(hasError.value).toBe(true)
    expect(isRefreshing.value).toBe(false)
  })
})

describe('moon chunk status', () => {
  it('is extracting before the chunk arrival, ready after it and exploded at the natural decay', () => {
    expect([
      moonChunkStatusAt(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T19:00:00Z')),
      moonChunkStatusAt(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T20:30:00Z')),
      moonChunkStatusAt(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T23:00:00Z')),
    ]).toEqual(['extracting', 'ready', 'exploded'])
  })

  it('is ready exactly at the chunk arrival and exploded exactly at the natural decay', () => {
    expect([
      moonChunkStatusAt(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T19:59:59Z')),
      moonChunkStatusAt(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T20:00:00Z')),
      moonChunkStatusAt(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T22:59:59Z')),
      moonChunkStatusAt(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T23:00:00Z')),
    ]).toEqual(['extracting', 'ready', 'ready', 'exploded'])
  })
})

describe('moon chunk countdown', () => {
  it('counts down to the chunk arrival while extracting, to the natural decay once ready, and stops once exploded', () => {
    expect([
      chunkCountdown(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T19:00:00Z')),
      chunkCountdown(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T20:30:00Z')),
      chunkCountdown(DEEP_CORE_CHUNK, new Date('2026-11-05T19:00:00Z')),
      chunkCountdown(ROCK_HOLLOW_CHUNK, new Date('2026-11-05T23:00:00Z')),
    ]).toEqual(['1h 00m', '2h 30m', '2d 11h 15m', null])
  })
})

describe('moon chunk times', () => {
  it('shows EVE time by default', () => {
    const { timeMode } = useCorporationMiningExtractions()

    expect(timeMode.value).toBe('eve')
    expect(chunkTimeZone('eve')).toBe('UTC')
  })

  it('uses the time zone of the browser in local mode', () => {
    expect(chunkTimeZone('local')).toBe(Intl.DateTimeFormat().resolvedOptions().timeZone)
  })

  it('formats a chunk time in EVE time or converted to another time zone, across midnight', () => {
    expect([
      formatChunkTime('2026-11-05T20:00:00+00:00', 'UTC'),
      formatChunkTime('2026-11-05T20:00:00+00:00', 'Europe/Paris'),
      formatChunkTime('2026-11-05T23:30:00+00:00', 'Europe/Paris'),
      formatChunkTime('2026-11-08T06:15:00+00:00', 'America/New_York'),
    ]).toEqual(['2026-11-05 20:00', '2026-11-05 21:00', '2026-11-06 00:30', '2026-11-08 01:15'])
  })
})
