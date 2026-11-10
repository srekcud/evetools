import { enableAutoUnmount, mount, RouterLinkStub } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LedgerDashboardTab from '@/components/ledger/LedgerDashboardTab.vue'
import type { LedgerDashboard } from '@/stores/ledger'

const SOLD_ESCALATIONS_ISK = 100_000_000

const DASHBOARD_WITH_SOLD_ESCALATION: LedgerDashboard = {
  period: { from: '2026-10-11', to: '2026-11-10', days: 30 },
  totals: { total: 106_200_000, pve: 105_100_000, mining: 600_000, industry: 500_000, expenses: 90_000, profit: 106_110_000 },
  industryBreakdown: { profit: 500_000, projectCount: 2, unknownProjectCount: 0 },
  pveBreakdown: { bounties: 3_700_000, ess: 800_000, missions: 300_000, lootSales: 250_000, corpProjects: 50_000, escalations: SOLD_ESCALATIONS_ISK },
  miningBreakdown: { sold: 600_000, corpProject: 0, industry: 0, unknown: 0 },
  expensesByType: { ammo: 90_000 },
  iskPerDay: 20_000,
  pvePercent: 98.97,
  miningPercent: 0.56,
  industryPercent: 0.47,
  missingPriceTypeIds: [],
  lastSync: { pve: null, mining: null },
  settings: { corpProjectAccounting: 'pve' },
  oldestEntryDate: '2026-02-14',
}

function mountDashboard() {
  return mount(LedgerDashboardTab, {
    props: { dashboard: DASHBOARD_WITH_SOLD_ESCALATION, dailyStats: null, selectedDays: 30, maxDailyValue: 0 },
    global: {
      plugins: [createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
}

type DashboardWrapper = ReturnType<typeof mountDashboard>

function activityCard(wrapper: DashboardWrapper, label: string) {
  const found = wrapper.findAll('div.rounded-xl').find(div => div.find('span').exists() && div.get('span').text() === label)
  if (!found) throw new Error(`No card labelled ${label}`)
  return found
}

function breakdownCard(wrapper: DashboardWrapper, title: string) {
  const found = wrapper.findAll('div.rounded-xl').find(div => div.find('h3').exists() && div.get('h3').text() === title)
  if (!found) throw new Error(`No breakdown titled ${title}`)
  return found
}

enableAutoUnmount(afterEach)

describe('LedgerDashboardTab pve module summary', () => {
  it('links each activity card to its module', () => {
    const wrapper = mountDashboard()

    const linksByCard = ['PVE', 'Mining', 'Industrie'].map(label => {
      const card = activityCard(wrapper, label)
      return wrapper.findAllComponents(RouterLinkStub).filter(link => card.element.contains(link.element)).map(link => link.props('to'))
    })

    expect(linksByCard).toEqual([['/pve/revenue'], ['/mining/my-mining'], ['/industry']])
  })

  it('shows the sold escalations in the pve breakdown', () => {
    const wrapper = mountDashboard()

    expect(breakdownCard(wrapper, fr.ledger.dashboard.pveBreakdown).text()).toMatch(/Escalations\s*100,00 M/)
  })
})
