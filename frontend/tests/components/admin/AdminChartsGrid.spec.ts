import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import AdminChartsGrid from '@/components/admin/AdminChartsGrid.vue'
import type { AdminChartData } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import type { TestLocale } from '../../localeTexts'

interface CapturedChart {
  labels: string[]
}

vi.mock('vue-chartjs', async () => {
  const { defineComponent, h } = await import('vue')
  const chartStub = (name: string) => defineComponent({
    name,
    props: { data: { type: Object, required: true } },
    render: () => h('div', { 'data-chart': name }),
  })
  return { Bar: chartStub('Bar'), Line: chartStub('Line'), Doughnut: chartStub('Doughnut') }
})

const CHARTS: AdminChartData = {
  registrations: { labels: ['2026-W45', '2026-W46'], data: [2, 5] },
  activity: { labels: ['2026-11-09', '2026-11-10'], logins: [4, 6] },
}

const CHARTS_FROM_AN_OLDER_API = {
  ...CHARTS,
  assetDistribution: { labels: ['personal', 'corporation'], data: [7, 3] },
} as unknown as AdminChartData

const TWELVE_WEEKS = Array.from({ length: 12 }, (_, index) => `2026-W${String(index + 35).padStart(2, '0')}`)

function mountGrid(charts: AdminChartData, locale: TestLocale = 'en') {
  return mount(AdminChartsGrid, {
    props: { charts },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function labelsOfCharts(locale: TestLocale): Record<string, string[]> {
  const wrapper = mount(AdminChartsGrid, {
    props: { charts: CHARTS },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  const labelsOf = (name: string): string[] => (wrapper.findComponent({ name }).props('data') as CapturedChart).labels
  return { registrations: labelsOf('Bar'), activity: labelsOf('Line') }
}

describe('AdminChartsGrid labels', () => {
  it('words the registration weeks in English', () => {
    expect(labelsOfCharts('en').registrations).toEqual(['Wk 45', 'Wk 46'])
  })

  it('words the registration weeks in French', () => {
    expect(labelsOfCharts('fr').registrations).toEqual(['Sem 45', 'Sem 46'])
  })

  it('writes the login days with the weekday of the locale', () => {
    expect(labelsOfCharts('en').activity).toEqual(['Mon', 'Tue'])
    expect(labelsOfCharts('fr').activity).toEqual(['lun.', 'mar.'])
  })

  it('plots the twelve registration weeks it receives', () => {
    const wrapper = mountGrid({ ...CHARTS, registrations: { labels: TWELVE_WEEKS, data: TWELVE_WEEKS.map((_, index) => index) } })
    const labels = (wrapper.findComponent({ name: 'Bar' }).props('data') as CapturedChart).labels
    expect(labels).toHaveLength(12)
    expect(labels[0]).toBe('Wk 35')
    expect(labels[11]).toBe('Wk 46')
  })
})

describe('AdminChartsGrid layout', () => {
  it('shows the registrations and the activity charts only, even when the API still sends the asset distribution', () => {
    const wrapper = mountGrid(CHARTS_FROM_AN_OLDER_API)
    expect(wrapper.findComponent({ name: 'Bar' }).exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'Line' }).exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'Doughnut' }).exists()).toBe(false)
  })

  it('lays the two charts out on two columns', () => {
    const wrapper = mountGrid(CHARTS)
    expect(wrapper.classes()).toContain('lg:grid-cols-2')
    expect(wrapper.classes()).not.toContain('lg:grid-cols-3')
  })
})
