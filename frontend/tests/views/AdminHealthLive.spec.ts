import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Admin from '@/views/Admin.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'

vi.mock('@/services/api', () => ({ apiRequest: vi.fn() }))

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const ADMIN_HEALTH_DEBOUNCE_MS = 1000
const SIGNAL_SPACING_MS = 100

const apiRequestMock = vi.mocked(apiRequest)

async function mountAdmin(): Promise<VueWrapper> {
  route.query = {}
  apiRequestMock.mockImplementation(async (path: string) => {
    if (path === '/admin/access') return { hasAccess: true, characterName: null }
    if (path === '/admin/queues') return { queues: { async: 0, failed: 0 } }
    if (path === '/admin/status') return { level: 'ok', rules: [] }
    return {}
  })
  const wrapper = mount(Admin, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        teleport: true,
        MainLayout: { template: '<div><slot /></div>' },
        AdminStatusBanner: true,
        AdminKpiGrid: true,
        SchedulerHealthTable: true,
        MaintenanceActions: true,
        FailedMessagesCard: true,
        AdminChartsGrid: true,
        PveCorpRevenue: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function callsTo(path: string): number {
  return apiRequestMock.mock.calls.filter(([calledPath]) => calledPath === path).length
}

function signal(scope: string, timestamp: string): void {
  const syncStore = useSyncStore()
  syncStore.adminHealthSignals = { ...syncStore.adminHealthSignals, [scope]: timestamp }
}

describe('Admin page, live health signals', () => {
  enableAutoUnmount(afterEach)

  beforeEach(() => {
    setActivePinia(createPinia())
    apiRequestMock.mockReset()
    vi.stubGlobal('EventSource', class { close(): void {} })
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 500 })))
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('reads the stats again after an esi-error-budget signal and leaves the queues alone', async () => {
    await mountAdmin()
    vi.useFakeTimers()
    const statsBefore = callsTo('/admin/stats')
    const queuesBefore = callsTo('/admin/queues')

    signal('esi-error-budget', '2026-11-13T08:00:00+00:00')
    await vi.advanceTimersByTimeAsync(ADMIN_HEALTH_DEBOUNCE_MS)

    expect(callsTo('/admin/stats')).toBe(statsBefore + 1)
    expect(callsTo('/admin/queues')).toBe(queuesBefore)
  })

  it('reads the queues once after the debounce when three failed-messages signals arrive within 300 ms', async () => {
    await mountAdmin()
    vi.useFakeTimers()
    const queuesBefore = callsTo('/admin/queues')
    const statsBefore = callsTo('/admin/stats')

    signal('failed-messages', '2026-11-13T08:00:00+00:00')
    await vi.advanceTimersByTimeAsync(SIGNAL_SPACING_MS)
    signal('failed-messages', '2026-11-13T08:00:01+00:00')
    await vi.advanceTimersByTimeAsync(SIGNAL_SPACING_MS)
    signal('failed-messages', '2026-11-13T08:00:02+00:00')
    await vi.advanceTimersByTimeAsync(SIGNAL_SPACING_MS)

    expect(callsTo('/admin/queues')).toBe(queuesBefore)

    await vi.advanceTimersByTimeAsync(ADMIN_HEALTH_DEBOUNCE_MS)

    expect(callsTo('/admin/queues')).toBe(queuesBefore + 1)
    expect(callsTo('/admin/stats')).toBe(statsBefore)
  })

  it('makes no extra request when no signal arrives', async () => {
    await mountAdmin()
    vi.useFakeTimers()
    const requestsBefore = apiRequestMock.mock.calls.length

    await vi.advanceTimersByTimeAsync(ADMIN_HEALTH_DEBOUNCE_MS * 5)

    expect(apiRequestMock.mock.calls.length).toBe(requestsBefore)
  })
})
