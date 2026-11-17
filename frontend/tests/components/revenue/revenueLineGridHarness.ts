import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { RouterLinkStub } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import RevenueLineGrid from '@/components/revenue/RevenueLineGrid.vue'
import type { RevenueGridColumn, RevenueSeries } from '@/types/revenueGrid'

export const PERIOD = { to: '2026-11-17', days: 7 }
export const WEEKLY_PERIOD = { to: '2026-11-17', days: 90 }

export function column(overrides: Partial<RevenueGridColumn> & Pick<RevenueGridColumn, 'id' | 'label' | 'value'>): RevenueGridColumn {
  return { tone: 'pve', kind: 'actual', change: 10, daily: [{ date: '2026-11-17', amount: 1 }], contributors: [{ id: 1, name: 'Main', value: 1 }], ...overrides }
}

export const NET_SERIES: RevenueSeries = { change: 5, daily: [{ date: '2026-11-16', amount: 2 }], contributors: [{ id: 1, name: 'Main', value: 2 }] }

export function mountGrid(props: Partial<InstanceType<typeof RevenueLineGrid>['$props']> & { columns: RevenueGridColumn[] }, locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(RevenueLineGrid, {
    props: { title: 'PVE revenue', rowLabel: 'PVE', net: NET_SERIES, period: PERIOD, ...props },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })
}
