import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Admin from '@/views/Admin.vue'
import { apiRequest } from '@/services/api'

vi.mock('@/services/api', () => ({ apiRequest: vi.fn() }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: vi.fn(() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const ACTION_PATH_PREFIX = '/admin/actions/'
const SUCCESS_MESSAGE = 'Action accepted'
const FAILURE_MESSAGE = 'Backend refused the action'

const maintenanceButtons: ReadonlyArray<{ label: string; actionSlug: string }> = [
  { label: en.admin.actions.retryFailed, actionSlug: 'retry-failed' },
  { label: en.admin.actions.purgeFailed, actionSlug: 'purge-failed' },
  { label: en.admin.clearCache, actionSlug: 'clear-cache' },
  { label: en.admin.actions.checkMarketAlerts, actionSlug: 'check-market-alerts' },
  { label: en.admin.actions.purgeNotifications, actionSlug: 'purge-notifications' },
  { label: en.admin.actions.purgeMarketHistory, actionSlug: 'purge-market-history' },
]

const apiRequestMock = vi.mocked(apiRequest)

function respondToApi(actionResponse: () => Promise<unknown>): void {
  apiRequestMock.mockImplementation(async (path: string) => {
    if (path === '/admin/access') return { hasAccess: true, characterName: null }
    if (path.startsWith(ACTION_PATH_PREFIX)) return actionResponse()
    return {}
  })
}

function actionCalls(): string[] {
  return apiRequestMock.mock.calls
    .map(([path]) => path)
    .filter(path => path.startsWith(ACTION_PATH_PREFIX))
}

async function mountAdmin(): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Admin, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        AdminKpiGrid: true,
        SchedulerHealthTable: true,
        AdminChartsGrid: true,
        PveCorpRevenue: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function maintenanceButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  if (!button) throw new Error(`Maintenance button "${label}" not found`)
  return button
}

describe('Admin maintenance buttons', () => {
  enableAutoUnmount(afterEach)

  beforeEach(() => {
    setActivePinia(createPinia())
    apiRequestMock.mockReset()
  })

  it.each(maintenanceButtons)('posts the $actionSlug action once when "$label" is clicked', async ({ label, actionSlug }) => {
    respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }))
    const wrapper = await mountAdmin()

    await maintenanceButton(wrapper, label).trigger('click')
    await flushPromises()

    expect(actionCalls()).toEqual([`${ACTION_PATH_PREFIX}${actionSlug}`])
  })

  it.each(maintenanceButtons)('shows the success message after "$label"', async ({ label }) => {
    respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }))
    const wrapper = await mountAdmin()

    await maintenanceButton(wrapper, label).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain(SUCCESS_MESSAGE)
  })

  it.each(maintenanceButtons)('shows the error message after "$label" fails', async ({ label }) => {
    respondToApi(async () => ({ success: false, message: FAILURE_MESSAGE }))
    const wrapper = await mountAdmin()

    await maintenanceButton(wrapper, label).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain(FAILURE_MESSAGE)
  })
})
