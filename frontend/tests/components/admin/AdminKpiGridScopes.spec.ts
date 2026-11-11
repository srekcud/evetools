import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AdminKpiGrid from '@/components/admin/AdminKpiGrid.vue'

enableAutoUnmount(afterEach)

type KpiScope = 'operations' | 'users' | 'statistics'

const kpi = en.admin.kpi

const LABELS_BY_SCOPE: Record<KpiScope, string[]> = {
  operations: [kpi.invalidAuth, kpi.expiring24h, kpi.expired, kpi.syncScope, kpi.queue, kpi.failed],
  users: [en.admin.users, kpi.validAuth, kpi.active7d, kpi.active30d, kpi.characters, kpi.tokens, kpi.tokensHealthy],
  statistics: [
    kpi.assets,
    kpi.activeProjects,
    kpi.esiJobs,
    kpi.structures,
    kpi.notifications,
    kpi.unread,
    kpi.pushSubs,
    kpi.historyTypes,
    kpi.alertsActive,
    kpi.alertsTriggered,
    kpi.favorites,
  ],
}

const SCOPES: KpiScope[] = ['operations', 'users', 'statistics']

function mountGrid(scope: KpiScope): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(AdminKpiGrid, { props: { stats: null, queues: null, scope }, global: { plugins: [i18n] } })
}

function tileLabels(wrapper: VueWrapper): string[] {
  return wrapper.findAll('span.uppercase').map(label => label.text())
}

describe('AdminKpiGrid scopes', () => {
  it.each(SCOPES)('shows exactly the %s tiles', scope => {
    const labels = tileLabels(mountGrid(scope))

    expect([...labels].sort()).toEqual([...LABELS_BY_SCOPE[scope]].sort())
  })

  it('shares no tile between two scopes', () => {
    const allLabels = SCOPES.flatMap(scope => LABELS_BY_SCOPE[scope])

    expect(new Set(allLabels).size).toBe(allLabels.length)
  })
})
