import { enableAutoUnmount, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { createI18n } from 'vue-i18n'
import MarketHistoryChart from '@/components/market/MarketHistoryChart.vue'
import type { HistoryEntry } from '@/stores/market'

interface CapturedDataset {
  label: string
  data: Array<number | null>
}

const captured: { datasets: CapturedDataset[] } = { datasets: [] }

vi.mock(import('vue-chartjs'), () => ({
  Bar: defineComponent({
    props: { data: { type: Object, required: true } },
    setup(props) {
      captured.datasets = (props.data as { datasets: CapturedDataset[] }).datasets
      return () => null
    },
  }),
}) as never)

enableAutoUnmount(afterEach)

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    en: {
      market: {
        period: { '30d': '30d', '90d': '90d', '365d': '365d' },
        detail: {
          sellPrice: 'Sell Price',
          buyPrice: 'Buy Price',
          averagePrice: 'Average',
          volume: 'Volume',
          listedVolume: 'Listed units',
        },
      },
    },
  },
})

const SNAPSHOT_DAY: HistoryEntry = {
  date: '2026-11-15',
  average: null,
  highest: null,
  lowest: null,
  orderCount: null,
  volume: null,
  sellMin: null,
  buyMax: 900,
  sellVolume: 11861,
  buyVolume: 632,
}

const JITA_DAY: HistoryEntry = {
  date: '2026-11-15',
  average: 5.5,
  highest: 6,
  lowest: 5,
  orderCount: 1000,
  volume: 50000,
}

function mountChart(source: 'jita' | 'structure', data: HistoryEntry[]): void {
  mount(MarketHistoryChart, {
    props: { data, selectedPeriod: 30, source },
    global: { plugins: [i18n] },
  })
}

describe('MarketHistoryChart order book snapshots (#994)', () => {
  beforeEach(() => {
    captured.datasets = []
  })

  it('labels the structure bars as listed units and not as volume', () => {
    mountChart('structure', [SNAPSHOT_DAY])

    const bars = captured.datasets[0]
    expect(bars.label).toBe('Listed units')
    expect(bars.data).toEqual([12493])
  })

  it('draws no average line for a structure', () => {
    mountChart('structure', [SNAPSHOT_DAY])

    expect(captured.datasets.map(dataset => dataset.label)).not.toContain('Average')
  })

  it('keeps a day without sell order unknown on the sell line', () => {
    mountChart('structure', [SNAPSHOT_DAY])

    const sellLine = captured.datasets.find(dataset => dataset.label === 'Sell Price')
    expect(sellLine?.data).toEqual([null])
  })

  it('keeps the Jita volume bars and average line', () => {
    mountChart('jita', [JITA_DAY])

    expect(captured.datasets[0].label).toBe('Volume')
    expect(captured.datasets[0].data).toEqual([50000])
    expect(captured.datasets.find(dataset => dataset.label === 'Average')?.data).toEqual([5.5])
  })
})
