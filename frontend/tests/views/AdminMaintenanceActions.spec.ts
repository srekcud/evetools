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
const FAILED_MESSAGE_COUNT = 12
const CLEAR_CACHE_CONFIRMATION = 'The application cache will be emptied. Pages can be slower until it fills again.'
const PURGE_NOTIFICATIONS_CONFIRMATION = 'Every notification older than 7 days will be deleted.'
const PURGE_MARKET_HISTORY_CONFIRMATION = 'Every market history entry older than 365 days will be deleted.'
const UNKNOWN_FAILED_COUNT_CONFIRMATION = 'The failed messages will be deleted permanently. Their count could not be read.'

interface MaintenanceButton {
  label: string
  actionSlug: string
}

interface DestructiveButton extends MaintenanceButton {
  confirmationMessage: string
}

const directButtons: ReadonlyArray<MaintenanceButton> = [
  { label: en.admin.actions.retryFailed, actionSlug: 'retry-failed' },
  { label: en.admin.actions.checkMarketAlerts, actionSlug: 'check-market-alerts' },
]

const destructiveButtons: ReadonlyArray<DestructiveButton> = [
  { label: en.admin.clearCache, actionSlug: 'clear-cache', confirmationMessage: CLEAR_CACHE_CONFIRMATION },
  { label: en.admin.actions.purgeNotifications, actionSlug: 'purge-notifications', confirmationMessage: PURGE_NOTIFICATIONS_CONFIRMATION },
  { label: en.admin.actions.purgeMarketHistory, actionSlug: 'purge-market-history', confirmationMessage: PURGE_MARKET_HISTORY_CONFIRMATION },
]

const purgeFailedButton: MaintenanceButton = { label: en.admin.actions.purgeFailed, actionSlug: 'purge-failed' }

const apiRequestMock = vi.mocked(apiRequest)

function respondToApi(actionResponse: () => Promise<unknown>, failedMessages: number | null = FAILED_MESSAGE_COUNT): void {
  apiRequestMock.mockImplementation(async (path: string) => {
    if (path === '/admin/access') return { hasAccess: true, characterName: null }
    if (path === '/admin/queues') return { queues: { async: 0, failed: failedMessages } }
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
        teleport: true,
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

function buttonLabelled(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  if (!button) throw new Error(`Button "${label}" not found`)
  return button
}

async function click(wrapper: VueWrapper, label: string): Promise<void> {
  await buttonLabelled(wrapper, label).trigger('click')
  await flushPromises()
}

async function clickAndConfirm(wrapper: VueWrapper, label: string): Promise<void> {
  await click(wrapper, label)
  await click(wrapper, en.common.actions.confirm)
}

describe('Admin maintenance buttons', () => {
  enableAutoUnmount(afterEach)

  beforeEach(() => {
    setActivePinia(createPinia())
    apiRequestMock.mockReset()
  })

  describe.each(directButtons)('non destructive "$label"', ({ label, actionSlug }) => {
    it('posts the action on a single click without asking for a confirmation', async () => {
      respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }))
      const wrapper = await mountAdmin()

      await click(wrapper, label)

      expect(actionCalls()).toEqual([`${ACTION_PATH_PREFIX}${actionSlug}`])
      expect(wrapper.text()).not.toContain(en.common.actions.confirm)
    })

    it('shows the success message', async () => {
      respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }))
      const wrapper = await mountAdmin()

      await click(wrapper, label)

      expect(wrapper.text()).toContain(SUCCESS_MESSAGE)
    })

    it('shows the error message when the action fails', async () => {
      respondToApi(async () => ({ success: false, message: FAILURE_MESSAGE }))
      const wrapper = await mountAdmin()

      await click(wrapper, label)

      expect(wrapper.text()).toContain(FAILURE_MESSAGE)
    })
  })

  describe.each([
    ...destructiveButtons,
    { ...purgeFailedButton, confirmationMessage: `${FAILED_MESSAGE_COUNT}` },
  ])('destructive "$label"', ({ label, actionSlug, confirmationMessage }) => {
    it('asks for a confirmation naming what will be removed and posts nothing yet', async () => {
      respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }))
      const wrapper = await mountAdmin()

      await click(wrapper, label)

      expect(wrapper.text()).toContain(confirmationMessage)
      expect(actionCalls()).toEqual([])
    })

    it('posts the action once after the confirmation', async () => {
      respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }))
      const wrapper = await mountAdmin()

      await clickAndConfirm(wrapper, label)

      expect(actionCalls()).toEqual([`${ACTION_PATH_PREFIX}${actionSlug}`])
      expect(wrapper.text()).toContain(SUCCESS_MESSAGE)
    })

    it('posts nothing and closes the confirmation when cancelled', async () => {
      respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }))
      const wrapper = await mountAdmin()

      await click(wrapper, label)
      await click(wrapper, en.common.actions.cancel)

      expect(actionCalls()).toEqual([])
      expect(wrapper.text()).not.toContain(en.common.actions.confirm)
    })

    it('shows the error message when the confirmed action fails', async () => {
      respondToApi(async () => ({ success: false, message: FAILURE_MESSAGE }))
      const wrapper = await mountAdmin()

      await clickAndConfirm(wrapper, label)

      expect(wrapper.text()).toContain(FAILURE_MESSAGE)
    })
  })

  it('says the failed message count is unknown instead of showing a number when the queue depth could not be read', async () => {
    respondToApi(async () => ({ success: true, message: SUCCESS_MESSAGE }), null)
    const wrapper = await mountAdmin()

    await click(wrapper, purgeFailedButton.label)

    expect(wrapper.text()).toContain(UNKNOWN_FAILED_COUNT_CONFIRMATION)
  })
})
