import { enableAutoUnmount, mount } from '@vue/test-utils'
import LedgerDashboardTab from '@/components/ledger/LedgerDashboardTab.vue'
import type { LedgerDashboard } from '@/stores/ledger'
import { MYSTERY_ORE_TYPE_ID, UNLOADED_ORE_TYPE_ID, i18nPlugin } from './miningLedgerHarness'

const DASHBOARD_WITH_UNKNOWN_MINING: LedgerDashboard = {
  period: { from: '2026-09-22', to: '2026-10-22', days: 30 },
  totals: { total: null, pve: 5_000_000, mining: null, expenses: 400_000, profit: null },
  pveBreakdown: { bounties: 4_200_000, ess: 650_000, missions: 150_000, lootSales: 0, corpProjects: 0 },
  miningBreakdown: { sold: 831_214.8, corpProject: 0, industry: null, unknown: 0 },
  expensesByType: { fuel: 400_000 },
  iskPerDay: null,
  pvePercent: null,
  miningPercent: null,
  missingPriceTypeIds: [MYSTERY_ORE_TYPE_ID],
  lastSync: { pve: null, mining: null },
  settings: { corpProjectAccounting: 'pve' },
  oldestEntryDate: '2026-02-14',
}

function mountDashboard(dashboard: LedgerDashboard) {
  return mount(LedgerDashboardTab, {
    props: { dashboard, dailyStats: null, selectedDays: 30, maxDailyValue: 0 },
    global: { plugins: [i18nPlugin()] },
  })
}

type DashboardWrapper = ReturnType<typeof mountDashboard>

function kpiCard(wrapper: DashboardWrapper, label: string) {
  const found = wrapper.findAll('div.rounded-xl').find(div => div.find('span').exists() && div.get('span').text() === label)
  if (!found) throw new Error(`No card labelled ${label}`)
  return found
}

function kpiValue(wrapper: DashboardWrapper, label: string) {
  return kpiCard(wrapper, label).get('.text-2xl')
}

enableAutoUnmount(afterEach)

describe('LedgerDashboardTab while the mining value is unknown', () => {
  it('shows the mining value and its ISK per day as unknown', () => {
    const wrapper = mountDashboard(DASHBOARD_WITH_UNKNOWN_MINING)

    const miningValue = kpiValue(wrapper, 'Mining')
    expect(miningValue.text()).toBe('—')
    expect(miningValue.get('[title]').attributes('title')).toBe('Unknown value')
    expect(kpiCard(wrapper, 'Mining').text()).toMatch(/ISK\/jour:\s*—/)
  })

  it('tells how many ores have no price on the mining card', () => {
    const wrapper = mountDashboard(DASHBOARD_WITH_UNKNOWN_MINING)

    expect(kpiCard(wrapper, 'Mining').text()).toContain('1 ore without a price: mining value unknown')
  })

  it('counts several ores without a price in the plural', () => {
    const wrapper = mountDashboard({ ...DASHBOARD_WITH_UNKNOWN_MINING, missingPriceTypeIds: [MYSTERY_ORE_TYPE_ID, UNLOADED_ORE_TYPE_ID] })

    expect(kpiCard(wrapper, 'Mining').text()).toContain('2 ores without a price: mining value unknown')
  })

  it('shows the total and the PVE and mining shares as unknown', () => {
    const wrapper = mountDashboard(DASHBOARD_WITH_UNKNOWN_MINING)

    expect(kpiValue(wrapper, 'Total').text()).toBe('—')
    expect(kpiCard(wrapper, 'Total').text()).toMatch(/PVE\s*—/)
    expect(kpiCard(wrapper, 'Total').text()).toMatch(/Mining\s*—/)
  })

  it('still shows the known PVE value and bounties', () => {
    const wrapper = mountDashboard(DASHBOARD_WITH_UNKNOWN_MINING)

    expect(kpiValue(wrapper, 'PVE').text()).toBe('5.00 M')
    expect(kpiCard(wrapper, 'PVE').text()).toContain('Bounties: 4.20 M')
  })
})
