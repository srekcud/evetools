import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import { useSyncStore, type SyncProgress } from '@/stores/sync'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PILOT_TWO_JOBS_SYNC_END: SyncProgress = {
  syncType: 'industry-jobs',
  status: 'completed',
  progress: 100,
  message: '4 active jobs, 3 ready to deliver',
  data: { total: 9, active: 4, ready: 3 },
  timestamp: '2026-10-29T12:00:05+00:00',
}

const PILOT_THREE_JOBS_SYNC_ERROR: SyncProgress = {
  syncType: 'industry-jobs',
  status: 'error',
  progress: null,
  message: 'boom A',
  data: null,
  timestamp: '2026-10-29T12:00:13+00:00',
}

const PILOT_FOUR_NOT_SYNCED_JOBS_SYNC_END: SyncProgress = {
  syncType: 'industry-jobs',
  status: 'completed',
  progress: 100,
  message: '2 active jobs, 1 ready to deliver, 1 characters not synced',
  data: { total: 3, active: 2, ready: 1, failedCharacters: [{ characterName: 'Pilot Four', reason: 'missing_scope', statusCode: null }] },
  timestamp: '2026-10-30T10:30:05+00:00',
}

async function receive(message: SyncProgress) {
  const syncStore = useSyncStore()
  syncStore.syncStatus = { ...syncStore.syncStatus, [message.syncType]: message }
  await flushPromises()
}

function slotsRequestCount(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === '/industry/slots').length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockReturnValue(new Promise(() => {}))
})

describe('IndustryDashboard on jobs sync events (issue 215)', () => {
  it('reloads the slots on a jobs sync end and shows no banner on a jobs sync error', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
    const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n] } })
    await flushPromises()

    await receive(PILOT_TWO_JOBS_SYNC_END)
    await receive(PILOT_THREE_JOBS_SYNC_ERROR)

    expect(slotsRequestCount()).toBe(2)
    expect(wrapper.find('[data-testid="jobs-sync-error"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('boom A')
  })

  it('reloads the slots on a user jobs sync end naming a character not synced and shows no banner', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
    const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n] } })
    await flushPromises()

    await receive(PILOT_FOUR_NOT_SYNCED_JOBS_SYNC_END)

    expect(slotsRequestCount()).toBe(2)
    expect(wrapper.find('[data-testid="last-jobs-sync-failures"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Pilot Four')
  })
})
