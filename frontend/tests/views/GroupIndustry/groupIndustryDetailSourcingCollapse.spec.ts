import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { BomItem } from '@/stores/group-industry/types'
import { writeSourcingOverride } from '@/views/GroupIndustry/sourcingCollapse'
import { bomLine, cockpitOf, mountCockpitDetail, nextAction, nyxProject, route, tabSelector } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SOURCING_SECTION = '[data-testid="material-sourcing"]'
const SOURCING_SUMMARY = '[data-testid="sourcing-summary"]'
const SOURCING_TOGGLE = '[data-testid="sourcing-toggle"]'
const SOURCING_OPEN_CHAIN = '[data-testid="sourcing-open-chain"]'

const COVERED_MATERIALS: BomItem[] = [
  bomLine({ id: 'tritanium', typeId: 34, typeName: 'Tritanium', fulfilledQuantity: 1000, remainingQuantity: 0, fulfillmentPercent: 100, isFulfilled: true }),
  bomLine({ id: 'pyerite', typeId: 35, typeName: 'Pyerite', fulfilledQuantity: 1000, remainingQuantity: 0, fulfillmentPercent: 100, isFulfilled: true }),
]
const OPEN_MATERIALS: BomItem[] = [
  COVERED_MATERIALS[0],
  bomLine({ id: 'mexallon', typeId: 36, typeName: 'Mexallon' }),
]

async function mountWith(bom: BomItem[]) {
  return mountCockpitDetail({
    project: nyxProject('in_progress'),
    bom,
    cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials' })),
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
})

describe('project materials tab sourcing blocks', () => {
  it('collapses the sourcing blocks behind a summary once every material is covered', async () => {
    const wrapper = await mountWith(COVERED_MATERIALS)

    expect({
      sourcing: wrapper.find(SOURCING_SECTION).exists(),
      summary: wrapper.get(SOURCING_SUMMARY).text(),
    }).toEqual({ sourcing: false, summary: expect.stringContaining('2 materials covered') })
  })

  it('keeps the sourcing blocks open while a material is still missing', async () => {
    const wrapper = await mountWith(OPEN_MATERIALS)

    expect({
      sourcing: wrapper.find(SOURCING_SECTION).exists(),
      summary: wrapper.find(SOURCING_SUMMARY).exists(),
    }).toEqual({ sourcing: true, summary: false })
  })

  it('reopens the sourcing blocks from the summary and remembers it for the project', async () => {
    const wrapper = await mountWith(COVERED_MATERIALS)

    await wrapper.get(SOURCING_TOGGLE).trigger('click')
    const reopenedHere = wrapper.find(SOURCING_SECTION).exists()
    wrapper.unmount()
    const remounted = await mountWith(COVERED_MATERIALS)

    expect({ reopenedHere, afterRemount: remounted.find(SOURCING_SECTION).exists() }).toEqual({ reopenedHere: true, afterRemount: true })
  })

  it('collapses again from the summary', async () => {
    const wrapper = await mountWith(COVERED_MATERIALS)
    await wrapper.get(SOURCING_TOGGLE).trigger('click')

    await wrapper.get(SOURCING_TOGGLE).trigger('click')

    expect(wrapper.find(SOURCING_SECTION).exists()).toBe(false)
  })

  it('does not apply the choice made on another project', async () => {
    writeSourcingOverride(localStorage, 'other-project', 'open')

    const wrapper = await mountWith(COVERED_MATERIALS)

    expect(wrapper.find(SOURCING_SECTION).exists()).toBe(false)
  })

  it('points to the production tab from the summary', async () => {
    const wrapper = await mountWith(COVERED_MATERIALS)

    await wrapper.get(SOURCING_OPEN_CHAIN).trigger('click')
    await flushPromises()

    expect({
      chainTabSelected: wrapper.get(tabSelector('chain')).attributes('aria-selected'),
      summaryGone: wrapper.find(SOURCING_SUMMARY).exists(),
    }).toEqual({ chainTabSelected: 'true', summaryGone: false })
  })

  it('names the tab where production is followed', async () => {
    const wrapper = await mountWith(COVERED_MATERIALS)

    expect({
      materials: wrapper.get(tabSelector('bom')).text(),
      chain: wrapper.get(tabSelector('chain')).text(),
    }).toEqual({
      materials: expect.stringContaining('Sourcing & jobs'),
      chain: 'Production',
    })
  })
})
