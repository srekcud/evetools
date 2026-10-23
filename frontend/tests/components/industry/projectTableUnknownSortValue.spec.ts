import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject } from '@/stores/industry/types'

const WIDOW_TYPE_ID = 22436
const RIFTER_TYPE_ID = 587
const ISHTAR_TYPE_ID = 12005
const VEXOR_TYPE_ID = 626
const HULK_TYPE_ID = 22544

function project(overrides: Partial<IndustryProject>): IndustryProject {
  return {
    id: 'project',
    productTypeName: null,
    productTypeId: WIDOW_TYPE_ID,
    displayName: 'Project',
    runs: 1,
    meLevel: 10,
    teLevel: 20,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost: null,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: null,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-01T10:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    ...overrides,
  }
}

function mountTable(projects: IndustryProject[]) {
  useProjectsStore().projects = projects
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ProjectTable, { global: { plugins: [i18n] } })
}

function displayedOrder(wrapper: ReturnType<typeof mountTable>): string[] {
  return wrapper.findAll('tbody tr').map(row => row.get('span.font-medium').text())
}

async function clickHeader(wrapper: ReturnType<typeof mountTable>, label: string) {
  const header = wrapper.findAll('thead th').find(th => th.text() === label)
  if (!header) throw new Error(`No header labelled ${label}`)
  await header.trigger('click')
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProjectTable sort on profit with an unknown profit', () => {
  const widowWithKnownProfit = project({ id: 'a', displayName: 'A', productTypeId: WIDOW_TYPE_ID, profit: 1_000_000 })
  const rifterWithUnknownProfit = project({ id: 'b', displayName: 'B', productTypeId: RIFTER_TYPE_ID, profit: null })
  const ishtarWithLoss = project({ id: 'c', displayName: 'C', productTypeId: ISHTAR_TYPE_ID, profit: -200_000 })

  it('places the unknown profit last when sorting by profit descending', () => {
    const wrapper = mountTable([widowWithKnownProfit, rifterWithUnknownProfit, ishtarWithLoss])

    expect(displayedOrder(wrapper)).toEqual(['A', 'C', 'B'])
  })

  it('places the unknown profit last when sorting by profit ascending', async () => {
    const wrapper = mountTable([widowWithKnownProfit, rifterWithUnknownProfit, ishtarWithLoss])

    await clickHeader(wrapper, en.industry.table.profit)

    expect(displayedOrder(wrapper)).toEqual(['C', 'A', 'B'])
  })

  it('sorts a known zero profit among the known profits, before the unknown one', async () => {
    const vexorWithZeroProfit = project({ id: 'd', displayName: 'D', productTypeId: VEXOR_TYPE_ID, profit: 0 })
    const wrapper = mountTable([rifterWithUnknownProfit, widowWithKnownProfit, vexorWithZeroProfit, ishtarWithLoss])

    expect(displayedOrder(wrapper)).toEqual(['A', 'D', 'C', 'B'])

    await clickHeader(wrapper, en.industry.table.profit)

    expect(displayedOrder(wrapper)).toEqual(['C', 'D', 'A', 'B'])
  })

  it('keeps the original relative order among several unknown profits in both directions', async () => {
    const hulkWithUnknownProfit = project({ id: 'e', displayName: 'E', productTypeId: HULK_TYPE_ID, profit: null })
    const wrapper = mountTable([hulkWithUnknownProfit, widowWithKnownProfit, rifterWithUnknownProfit, ishtarWithLoss])

    expect(displayedOrder(wrapper)).toEqual(['A', 'C', 'E', 'B'])

    await clickHeader(wrapper, en.industry.table.profit)

    expect(displayedOrder(wrapper)).toEqual(['C', 'A', 'E', 'B'])
  })
})

describe('ProjectTable sort on sell price with actual and estimated values', () => {
  const widowWithEstimatedSellPriceOnly = project({ id: 'a', displayName: 'A', productTypeId: WIDOW_TYPE_ID, sellPrice: null, estimatedSellPrice: 5_000_000, profit: 400_000 })
  const rifterWithUnknownSellPrice = project({ id: 'b', displayName: 'B', productTypeId: RIFTER_TYPE_ID, sellPrice: null, estimatedSellPrice: null, profit: 900_000 })
  const ishtarWithActualSellPrice = project({ id: 'c', displayName: 'C', productTypeId: ISHTAR_TYPE_ID, sellPrice: 1_000_000, estimatedSellPrice: 9_000_000, profit: 150_000 })

  it('sorts by the actual sell price, else the estimated one, unknown last, descending', async () => {
    const wrapper = mountTable([widowWithEstimatedSellPriceOnly, rifterWithUnknownSellPrice, ishtarWithActualSellPrice])

    await clickHeader(wrapper, en.industry.table.sell)

    expect(displayedOrder(wrapper)).toEqual(['A', 'C', 'B'])
  })

  it('sorts by the actual sell price, else the estimated one, unknown last, ascending', async () => {
    const wrapper = mountTable([widowWithEstimatedSellPriceOnly, rifterWithUnknownSellPrice, ishtarWithActualSellPrice])

    await clickHeader(wrapper, en.industry.table.sell)
    await clickHeader(wrapper, en.industry.table.sell)

    expect(displayedOrder(wrapper)).toEqual(['C', 'A', 'B'])
  })
})

describe('ProjectTable sort on cost columns with an unknown value', () => {
  it.each([
    ['materials', en.industry.table.materials, 'materialCost', 'estimatedMaterialCost'],
    ['job cost', en.industry.table.jobCost, 'jobsCost', 'estimatedJobCost'],
    ['taxes', en.industry.table.taxes, 'taxAmount', 'estimatedTaxAmount'],
  ] as const)('places the unknown %s last in both directions', async (_column, label, actualField, estimatedField) => {
    const widowWithActualCost = project({ id: 'a', displayName: 'A', productTypeId: WIDOW_TYPE_ID, [actualField]: 3_200_000, [estimatedField]: 100_000 })
    const rifterWithUnknownCost = project({ id: 'b', displayName: 'B', productTypeId: RIFTER_TYPE_ID, [actualField]: null, [estimatedField]: null })
    const ishtarWithEstimatedCostOnly = project({ id: 'c', displayName: 'C', productTypeId: ISHTAR_TYPE_ID, [actualField]: null, [estimatedField]: 750_000 })
    const wrapper = mountTable([rifterWithUnknownCost, widowWithActualCost, ishtarWithEstimatedCostOnly])

    await clickHeader(wrapper, label)

    expect(displayedOrder(wrapper)).toEqual(['A', 'C', 'B'])

    await clickHeader(wrapper, label)

    expect(displayedOrder(wrapper)).toEqual(['C', 'A', 'B'])
  })
})
