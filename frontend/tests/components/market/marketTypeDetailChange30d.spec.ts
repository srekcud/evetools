import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import MarketTypeDetail from '@/components/market/MarketTypeDetail.vue'
import { useMarketStore, type MarketTypeDetail as TypeDetailType } from '@/stores/market'

const JITA_SELL_PRICE = 1_200_000

function buildDetail(overrides: Partial<TypeDetailType> = {}): TypeDetailType {
  return {
    typeId: 34,
    typeName: 'Tritanium',
    groupName: 'Mineral',
    categoryName: 'Material',
    jitaSell: JITA_SELL_PRICE,
    jitaBuy: 1_100_000,
    spread: 9.1,
    sellOrders: [],
    buyOrders: [],
    structureSellOrders: [],
    structureBuyOrders: [],
    structureSell: null,
    structureBuy: null,
    structureName: null,
    structurePrices: [],
    hasPreferredStructure: false,
    avgDailyVolume: 5000,
    change30d: 0,
    isFavorite: false,
    ...overrides,
  }
}

function detailWithoutChange30d(): TypeDetailType {
  const detail: Partial<TypeDetailType> = buildDetail()
  delete detail.change30d
  return detail as TypeDetailType
}

function mountDetail(detail: TypeDetailType) {
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.spyOn(useMarketStore(), 'fetchHistory').mockResolvedValue()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(MarketTypeDetail, {
    global: {
      plugins: [pinia, i18n],
      stubs: {
        MarketHistoryChart: true,
        OrderBookPanel: true,
        PriceComparisonTable: true,
        AlertFormModal: true,
        OpenInGameButton: true,
      },
    },
    props: { detail },
  })
}

function changeCard(wrapper: ReturnType<typeof mountDetail>) {
  const label = en.market.detail.change30d
  const labelNode = wrapper.findAll('div').find((node) => node.text() === label)
  const card = wrapper.findAll('div').find((node) => node.element === labelNode?.element.parentElement)
  if (!card) throw new Error('30-day change card not found')
  return card
}

describe('MarketTypeDetail 30-day change', () => {
  it('shows --- when the API leaves the change out', () => {
    const wrapper = mountDetail(detailWithoutChange30d())

    const card = changeCard(wrapper)
    expect(card.text()).toContain('---')
    expect(card.text()).not.toContain('%')
  })

  it('shows --- when the change is null', () => {
    const wrapper = mountDetail(buildDetail({ change30d: null }))

    expect(changeCard(wrapper).text()).toContain('---')
  })

  it('greys the change out when the API leaves it out', () => {
    const wrapper = mountDetail(detailWithoutChange30d())

    const value = changeCard(wrapper).find('.font-mono')
    expect(value.classes()).toContain('text-slate-500')
    expect(value.classes()).not.toContain('text-emerald-400')
    expect(value.classes()).not.toContain('text-red-400')
  })

  it('shows no previous price when the change is left out', () => {
    const wrapper = mountDetail(detailWithoutChange30d())

    expect(changeCard(wrapper).text()).not.toContain('→')
  })

  it('still shows a positive change with its sign and colour', () => {
    const wrapper = mountDetail(buildDetail({ change30d: 12.34 }))

    const card = changeCard(wrapper)
    expect(card.text()).toContain('+12.3%')
    expect(card.find('.font-mono').classes()).toContain('text-emerald-400')
  })

  it('still shows a negative change in red', () => {
    const wrapper = mountDetail(buildDetail({ change30d: -5.55 }))

    const card = changeCard(wrapper)
    expect(card.text()).toContain('-5.5%')
    expect(card.find('.font-mono').classes()).toContain('text-red-400')
  })
})
