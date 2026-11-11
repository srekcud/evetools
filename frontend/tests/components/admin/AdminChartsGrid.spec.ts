import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import AdminChartsGrid from '@/components/admin/AdminChartsGrid.vue'
import type { AdminChartData } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { messageOf, type TestLocale } from '../../localeTexts'

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
  assetDistribution: { labels: ['personal', 'corporation'], data: [7, 3] },
}

function labelsOfCharts(locale: TestLocale): Record<string, string[]> {
  const wrapper = mount(AdminChartsGrid, {
    props: { charts: CHARTS },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  const labelsOf = (name: string): string[] => (wrapper.findComponent({ name }).props('data') as CapturedChart).labels
  return { registrations: labelsOf('Bar'), activity: labelsOf('Line'), assetDistribution: labelsOf('Doughnut') }
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

  it('translates the asset distribution keys', () => {
    for (const locale of ['en', 'fr'] as const) {
      expect(labelsOfCharts(locale).assetDistribution).toEqual([
        messageOf(locale, 'admin.charts.assetKinds.personal'),
        messageOf(locale, 'admin.charts.assetKinds.corporation'),
      ])
    }
  })

  it('names the personal assets differently in French and in English', () => {
    expect(messageOf('fr', 'admin.charts.assetKinds.personal')).toBe('Personnel')
    expect(messageOf('en', 'admin.charts.assetKinds.personal')).toBe('Personal')
  })
})
