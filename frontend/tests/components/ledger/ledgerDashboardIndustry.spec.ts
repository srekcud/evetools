import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LedgerDashboardTab from '@/components/ledger/LedgerDashboardTab.vue'
import type { LedgerDashboard } from '@/stores/ledger'

const DASHBOARD_WITH_INDUSTRY: LedgerDashboard = {
  period: { from: '2026-10-08', to: '2026-11-07', days: 30 },
  totals: { total: 8_000_000, pve: 5_000_000, mining: 1_000_000, industry: 2_000_000, expenses: 500_000, profit: 7_500_000 },
  industryBreakdown: { profit: 2_000_000, projectCount: 3, unknownProjectCount: 1 },
  pveBreakdown: { bounties: 4_200_000, ess: 650_000, missions: 150_000, lootSales: 0, corpProjects: 0 },
  miningBreakdown: { sold: 1_000_000, corpProject: 0, industry: 0, unknown: 0 },
  expensesByType: { fuel: 500_000 },
  iskPerDay: 250_000,
  pvePercent: 62.5,
  miningPercent: 12.5,
  industryPercent: 25,
  missingPriceTypeIds: [],
  lastSync: { pve: null, mining: null },
  settings: { corpProjectAccounting: 'pve' },
  oldestEntryDate: '2026-02-14',
}

const DASHBOARD_WITH_UNKNOWN_MINING: LedgerDashboard = {
  ...DASHBOARD_WITH_INDUSTRY,
  totals: { ...DASHBOARD_WITH_INDUSTRY.totals, total: null, mining: null, profit: null },
  iskPerDay: null,
  pvePercent: null,
  miningPercent: null,
  industryPercent: null,
}

function mountDashboard(dashboard: LedgerDashboard) {
  return mount(LedgerDashboardTab, {
    props: { dashboard, dailyStats: null, selectedDays: 30, maxDailyValue: 0 },
    global: { plugins: [createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })] },
  })
}

type DashboardWrapper = ReturnType<typeof mountDashboard>

function cardWhere(wrapper: DashboardWrapper, matches: (cardText: string, label: string | undefined) => boolean) {
  return wrapper.findAll('div.rounded-xl').find(div => matches(div.text(), div.find('span').exists() ? div.get('span').text() : undefined))
}

enableAutoUnmount(afterEach)

describe('LedgerDashboardTab industry', () => {
  it('shows an Industrie card with the known profit, the project count and the projects without a known profit', () => {
    const card = cardWhere(mountDashboard(DASHBOARD_WITH_INDUSTRY), (_, label) => label === 'Industrie')

    expect(card?.get('.text-2xl').text()).toBe('2,00 M')
    expect(card?.text()).toMatch(/\b3\b/)
    expect(card?.text()).toContain('dont 1 à profit inconnu')
  })

  it('shows the industry share in the total split, unknown when the total is unknown', () => {
    const known = cardWhere(mountDashboard(DASHBOARD_WITH_INDUSTRY), text => /PVE\s*62\.5%/.test(text))
    const unknown = cardWhere(mountDashboard(DASHBOARD_WITH_UNKNOWN_MINING), text => /PVE\s*—/.test(text))

    expect(known?.text()).toMatch(/Industrie\s*25\.0%/)
    expect(unknown?.text()).toMatch(/Industrie\s*—/)
  })
})
