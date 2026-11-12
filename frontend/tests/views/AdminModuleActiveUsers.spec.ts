import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Admin from '@/views/Admin.vue'
import ModuleActiveUsersCard from '@/components/admin/ModuleActiveUsersCard.vue'
import { apiRequest } from '@/services/api'

vi.mock('@/services/api', () => ({ apiRequest: vi.fn() }))

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const ACTIVE_USERS_LAST_30_DAYS = 40
const MODULE_ROWS = [
  { module: 'industry', activeLast7Days: 12, activeLast30Days: 30 },
  { module: 'contracts', activeLast7Days: null, activeLast30Days: null },
]

const apiRequestMock = vi.mocked(apiRequest)

async function mountAdmin(query: LocationQuery): Promise<VueWrapper> {
  route.query = query
  apiRequestMock.mockImplementation(async (path: string) => {
    if (path === '/admin/access') return { hasAccess: true, characterName: null }
    if (path === '/admin/queues') return { queues: { async: 0, failed: 0 } }
    if (path === '/admin/status') return { level: 'ok', rules: [] }
    if (path === '/admin/stats') {
      return { users: { activeLastMonth: ACTIVE_USERS_LAST_30_DAYS }, moduleActiveUsers: MODULE_ROWS }
    }
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

describe('Admin active users per module', () => {
  enableAutoUnmount(afterEach)

  beforeEach(() => {
    setActivePinia(createPinia())
    apiRequestMock.mockReset()
  })

  it('shows the active users per module on Statistics with the thirty day active users as the bar reference', async () => {
    const wrapper = await mountAdmin({ tab: 'statistics' })

    const card = wrapper.findComponent(ModuleActiveUsersCard)
    expect(card.exists()).toBe(true)
    expect(card.props('modules')).toEqual(MODULE_ROWS)
    expect(card.props('activeUsersLast30Days')).toBe(ACTIVE_USERS_LAST_30_DAYS)
  })

  it.each(['health', 'users'])('does not show the active users per module on %s', async (tab) => {
    const wrapper = await mountAdmin({ tab })

    expect(wrapper.findComponent(ModuleActiveUsersCard).exists()).toBe(false)
  })
})
