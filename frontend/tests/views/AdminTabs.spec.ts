import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Admin from '@/views/Admin.vue'
import AdminStatusBanner from '@/components/admin/AdminStatusBanner.vue'
import AdminKpiGrid from '@/components/admin/AdminKpiGrid.vue'
import SchedulerHealthTable from '@/components/admin/SchedulerHealthTable.vue'
import MaintenanceActions from '@/components/admin/MaintenanceActions.vue'
import AdminChartsGrid from '@/components/admin/AdminChartsGrid.vue'
import PveCorpRevenue from '@/components/admin/PveCorpRevenue.vue'
import { apiRequest } from '@/services/api'

vi.mock('@/services/api', () => ({ apiRequest: vi.fn() }))

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace, push: vi.fn() })) as never,
}))

const TAB_LABELS = ['Health', 'Users', 'Statistics']
const REMOVED_SCHEDULER_BLOCK_TITLE = 'Scheduler & Rate Limiting'

const apiRequestMock = vi.mocked(apiRequest)

async function mountAdmin(query: LocationQuery = {}): Promise<VueWrapper> {
  route.query = query
  apiRequestMock.mockImplementation(async (path: string) => {
    if (path === '/admin/access') return { hasAccess: true, characterName: null }
    if (path === '/admin/queues') return { queues: { async: 0, failed: 0 } }
    if (path === '/admin/status') return { level: 'ok', rules: [] }
    return {}
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Admin, {
    global: {
      plugins: [i18n],
      stubs: {
        teleport: true,
        MainLayout: { template: '<div><slot /></div>' },
        AdminStatusBanner: true,
        AdminKpiGrid: true,
        SchedulerHealthTable: true,
        MaintenanceActions: true,
        AdminChartsGrid: true,
        PveCorpRevenue: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function tabButtons(wrapper: VueWrapper) {
  return wrapper.findAll('[role="tab"]')
}

function selectedTabLabel(wrapper: VueWrapper): string | undefined {
  return tabButtons(wrapper).find(tab => tab.attributes('aria-selected') === 'true')?.text()
}

function kpiScopes(wrapper: VueWrapper): unknown[] {
  return wrapper.findAllComponents(AdminKpiGrid).map(grid => grid.props('scope'))
}

describe('Admin tabs', () => {
  enableAutoUnmount(afterEach)

  beforeEach(() => {
    setActivePinia(createPinia())
    apiRequestMock.mockReset()
    routerReplace.mockReset()
  })

  it('offers Health, Users and Statistics tabs and opens on Health by default', async () => {
    const wrapper = await mountAdmin()

    expect(tabButtons(wrapper).map(tab => tab.text())).toEqual(TAB_LABELS)
    expect(selectedTabLabel(wrapper)).toBe('Health')
  })

  it('shows the status banner, the operations tiles, the health table and the maintenance actions on Health only', async () => {
    const wrapper = await mountAdmin()

    expect(wrapper.findComponent(AdminStatusBanner).exists()).toBe(true)
    expect(kpiScopes(wrapper)).toEqual(['operations'])
    expect(wrapper.findComponent(SchedulerHealthTable).exists()).toBe(true)
    expect(wrapper.findComponent(MaintenanceActions).exists()).toBe(true)
    expect(wrapper.findComponent(AdminChartsGrid).exists()).toBe(false)
    expect(wrapper.findComponent(PveCorpRevenue).exists()).toBe(false)
  })

  it('shows the product tiles, the charts and the PVE revenue by corporation on Statistics only', async () => {
    const wrapper = await mountAdmin({ tab: 'statistics' })

    expect(selectedTabLabel(wrapper)).toBe('Statistics')
    expect(kpiScopes(wrapper)).toEqual(['statistics'])
    expect(wrapper.findComponent(AdminChartsGrid).exists()).toBe(true)
    expect(wrapper.findComponent(PveCorpRevenue).exists()).toBe(true)
    expect(wrapper.findComponent(AdminStatusBanner).exists()).toBe(false)
    expect(wrapper.findComponent(SchedulerHealthTable).exists()).toBe(false)
    expect(wrapper.findComponent(MaintenanceActions).exists()).toBe(false)
  })

  it('shows the user and token tiles on Users only', async () => {
    const wrapper = await mountAdmin({ tab: 'users' })

    expect(selectedTabLabel(wrapper)).toBe('Users')
    expect(kpiScopes(wrapper)).toEqual(['users'])
    expect(wrapper.findComponent(AdminStatusBanner).exists()).toBe(false)
    expect(wrapper.findComponent(SchedulerHealthTable).exists()).toBe(false)
    expect(wrapper.findComponent(AdminChartsGrid).exists()).toBe(false)
  })

  it('falls back to Health when the tab in the URL is unknown', async () => {
    const wrapper = await mountAdmin({ tab: 'billing' })

    expect(selectedTabLabel(wrapper)).toBe('Health')
  })

  it('writes the selected tab in the URL', async () => {
    const wrapper = await mountAdmin()

    const statisticsTab = tabButtons(wrapper).find(tab => tab.text() === 'Statistics')
    await statisticsTab?.trigger('click')
    await flushPromises()

    expect(routerReplace).toHaveBeenCalledWith({ query: { tab: 'statistics' } })
    expect(selectedTabLabel(wrapper)).toBe('Statistics')
  })

  it('no longer shows the scheduler config block', async () => {
    const wrapper = await mountAdmin()

    expect(wrapper.text()).not.toContain(REMOVED_SCHEDULER_BLOCK_TITLE)
  })
})
