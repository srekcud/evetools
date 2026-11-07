import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { ChainNode } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'
import { messageOf, type TestLocale } from '../../localeTexts'
import {
  chainNode,
  HAMMERHEAD_I,
  HAMMERHEAD_I_COPY_ID,
  HAMMERHEAD_I_ID,
  HAMMERHEAD_II_ID,
  HAMMERHEAD_II_INVENTION_ID,
  PARTICLE_ACCELERATOR_UNIT,
  PARTICLE_ACCELERATOR_UNIT_ID,
  PRODUCTION_CHAIN,
  SALE_ID,
  TRITANIUM,
  TRITANIUM_ID,
} from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const LONG_TYPE_NAME = 'Capital Ship Assembly Array Blueprint Copy for the Ishtar Heavy Assault Cruiser Fleet Refit Programme'

function label(locale: TestLocale, key: string): string {
  return messageOf(locale, `groupIndustry.chain.${key}`)
}

function answerWith(nodes: ChainNode[]): void {
  vi.mocked(apiRequest).mockImplementation(async endpoint => (
    endpoint === `/industry/projects/${PROJECT_ID}/reactions` ? { id: PROJECT_ID, steps: [] } : { id: PROJECT_ID, nodes }
  ))
}

async function mountChain(nodes: ChainNode[], locale: TestLocale = 'en'): Promise<VueWrapper> {
  answerWith(nodes)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProductionChain, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

async function openTree(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')
}

type FoundElement = Omit<DOMWrapper<Element>, 'exists'>

function row(wrapper: VueWrapper, nodeId: string): FoundElement {
  return wrapper.get(`[data-testid="chain-row-${nodeId}"]`)
}

const COLUMN = {
  element: 0,
  activity: 1,
  runs: 2,
  meTe: 3,
  character: 4,
  status: 5,
  realCost: 6,
  end: 7,
  inOut: 8,
  structure: 9,
} as const

function cellsOf(wrapper: VueWrapper, nodeId: string): DOMWrapper<Element>[] {
  return row(wrapper, nodeId).findAll('td')
}

function cell(wrapper: VueWrapper, nodeId: string, column: keyof typeof COLUMN): FoundElement {
  const found = cellsOf(wrapper, nodeId)[COLUMN[column]]
  if (found === undefined) throw new Error(`no ${column} cell on ${nodeId}`)
  return found
}

function testIdOf(element: FoundElement, prefix: string): string {
  const testId = element.attributes('data-testid')
  if (testId === undefined) throw new Error('element without test id')
  return testId.replace(prefix, '')
}

function shownRowIds(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid^="chain-row-"]').map(shown => testIdOf(shown, 'chain-row-'))
}

function nodeIdsOf(column: FoundElement): string[] {
  return column.findAll('[data-testid^="chain-node-"]').map(node => testIdOf(node, 'chain-node-'))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProductionChain overview', () => {
  it('places each node in its column and shows the sold units and the gross revenue on the sale node', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)

    const columns = {
      inventionMaterials: nodeIdsOf(wrapper.get('[data-testid="chain-column-inventionMaterials"]')),
      components: nodeIdsOf(wrapper.get('[data-testid="chain-column-components"]')),
      product: nodeIdsOf(wrapper.get('[data-testid="chain-column-product"]')),
      sale: nodeIdsOf(wrapper.get('[data-testid="chain-column-sale"]')),
    }
    const sale = wrapper.get(`[data-testid="chain-node-${SALE_ID}"]`).text()

    expect(columns).toEqual({
      inventionMaterials: [HAMMERHEAD_II_INVENTION_ID, HAMMERHEAD_I_COPY_ID, TRITANIUM_ID, PARTICLE_ACCELERATOR_UNIT_ID],
      components: [HAMMERHEAD_I_ID],
      product: [HAMMERHEAD_II_ID],
      sale: [SALE_ID],
    })
    expect(wrapper.get('[data-testid="chain-column-product"]').text()).toContain(label('en', 'flow.product'))
    expect(sale).toContain('5')
    expect(sale).toContain('3.60 M')
  })

  it('opens the job tree on the clicked node', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)

    await wrapper.get(`[data-testid="chain-node-${HAMMERHEAD_I_ID}"]`).trigger('click')

    expect(wrapper.find('[data-testid="chain-flow"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="chain-tree"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid^="chain-row-"][aria-selected="true"]').map(selected => selected.attributes('data-testid'))).toEqual([
      `chain-row-${HAMMERHEAD_I_ID}`,
    ])
  })
})

describe('ProductionChain job tree', () => {
  it('lists the columns of the job tree and fills them for a job with delivered ESI jobs, its real cost and its structure', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const headers = wrapper.findAll('[data-testid="chain-tree"] thead th').map(header => header.text())
    const cellsCount = cellsOf(wrapper, HAMMERHEAD_I_ID).length
    const hammerheadI = (column: keyof typeof COLUMN) => cell(wrapper, HAMMERHEAD_I_ID, column)

    expect(headers).toEqual(
      ['element', 'activity', 'runs', 'meTe', 'character', 'status', 'realCost', 'end', 'inOut', 'structure'].map(column => label('en', `columns.${column}`)),
    )
    expect(cellsCount).toBe(10)
    expect(hammerheadI('element').text()).toContain('Hammerhead I')
    expect(hammerheadI('activity').text()).toBe(label('en', 'activity.manufacturing'))
    expect(hammerheadI('runs').text()).toBe('50')
    expect(hammerheadI('meTe').text()).toBe(label('en', 'meTeValue').replace('{me}', '10').replace('{te}', '20'))
    expect(hammerheadI('character').text()).toBe('Wenzel Roth')
    expect(hammerheadI('status').text()).toBe(label('en', 'status.done'))
    expect(hammerheadI('realCost').text()).toBe('214.00 K')
    expect(hammerheadI('end').text()).toContain('03/11')
    expect(hammerheadI('structure').text()).toContain('Raitaru Alpha')
  })

  it('always shows the input and output locations as unknown with their cause, never a location', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const ioCells = [HAMMERHEAD_I_ID, HAMMERHEAD_II_ID, HAMMERHEAD_II_INVENTION_ID].map(nodeId => cell(wrapper, nodeId, 'inOut'))

    for (const ioCell of ioCells) {
      const unknown = ioCell.get('[data-testid="chain-io-unknown"]')
      expect(unknown.attributes('title')).toBe(messageOf('en', 'common.unknownValue'))
      expect(ioCell.text()).toContain(label('en', 'ioUnknownCause'))
    }
  })

  it('folds and unfolds the rows under a node', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)
    const everyRow = [
      HAMMERHEAD_II_INVENTION_ID,
      HAMMERHEAD_I_COPY_ID,
      TRITANIUM_ID,
      PARTICLE_ACCELERATOR_UNIT_ID,
      HAMMERHEAD_I_ID,
      HAMMERHEAD_II_ID,
    ]

    const unfolded = shownRowIds(wrapper)
    await wrapper.get(`[data-testid="chain-toggle-${HAMMERHEAD_II_ID}"]`).trigger('click')
    const folded = shownRowIds(wrapper)
    await wrapper.get(`[data-testid="chain-toggle-${HAMMERHEAD_II_ID}"]`).trigger('click')
    const unfoldedAgain = shownRowIds(wrapper)

    expect([...unfolded].sort()).toEqual([...everyRow].sort())
    expect([...folded].sort()).toEqual([TRITANIUM_ID, PARTICLE_ACCELERATOR_UNIT_ID, HAMMERHEAD_II_ID].sort())
    expect([...unfoldedAgain].sort()).toEqual([...everyRow].sort())
  })

  it('renders each status with its own label, an unknown job status never as to do', async () => {
    const statuses: ChainNode['status'][] = ['done', 'active', 'todo', 'blocked', 'unknown']
    const wrapper = await mountChain(statuses.map(status => chainNode({
      id: `node-${status}`,
      stage: 'component',
      typeName: `Component ${status}`,
      activity: 'manufacturing',
      runs: 10,
      status,
    })))
    await openTree(wrapper)

    const labels = statuses.map(status => cell(wrapper, `node-${status}`, 'status').text())

    expect(labels).toEqual(statuses.map(status => label('en', `status.${status}`)))
  })

  it('shows a known real cost, a dash when nothing is paid and an unknown cost with its cause and its suppliers, never 0', async () => {
    const wrapper = await mountChain([
      HAMMERHEAD_I,
      TRITANIUM,
      PARTICLE_ACCELERATOR_UNIT,
    ])
    await openTree(wrapper)

    const knownCost = cell(wrapper, HAMMERHEAD_I_ID, 'realCost')
    const noCost = cell(wrapper, TRITANIUM_ID, 'realCost')
    const unknownCost = cell(wrapper, PARTICLE_ACCELERATOR_UNIT_ID, 'realCost')
    const suppliers = cell(wrapper, PARTICLE_ACCELERATOR_UNIT_ID, 'character').text()

    expect(knownCost.text()).toBe('214.00 K')
    expect(noCost.text()).toBe('—')
    expect(noCost.find('[data-testid="chain-cost-unknown"]').exists()).toBe(false)
    expect(unknownCost.get('[data-testid="chain-cost-unknown"]').attributes('title')).toBe(messageOf('en', 'common.unknownValue'))
    expect(unknownCost.text()).toContain(label('en', 'unknownCostCause'))
    expect(unknownCost.text()).not.toMatch(/\b0\b/)
    expect(suppliers).toContain('Ulla Voss')
    expect(suppliers).toContain('60')
    expect(suppliers).toContain('Varek Dunn')
    expect(suppliers).toContain('40')
  })

  it('shows the real structures of a job, else the planned structure with its warning, else why it is missing', async () => {
    const jobWith = (id: string, overrides: Partial<ChainNode>): ChainNode => chainNode({
      id,
      stage: 'component',
      typeName: id,
      activity: 'manufacturing',
      runs: 10,
      status: 'todo',
      ...overrides,
    })
    const wrapper = await mountChain([
      jobWith('job-launched', { structures: ['Raitaru Alpha'], plannedStructure: { name: 'Azbel Beta', status: 'best_in_favorite_system' } }),
      jobWith('job-planned', { plannedStructure: { name: 'Azbel Beta', status: 'best_in_favorite_system' } }),
      jobWith('job-outside', { plannedStructure: { name: 'Sotiyo Gamma', status: 'best_outside_favorite_system' } }),
      jobWith('job-deleted', { plannedStructure: { name: null, status: 'assigned' } }),
      jobWith('job-unplanned', { plannedStructure: null }),
    ])
    await openTree(wrapper)

    const launched = cell(wrapper, 'job-launched', 'structure')
    const planned = cell(wrapper, 'job-planned', 'structure')
    const outside = cell(wrapper, 'job-outside', 'structure')
    const deleted = cell(wrapper, 'job-deleted', 'structure')
    const unplanned = cell(wrapper, 'job-unplanned', 'structure')

    expect(launched.text()).toContain('Raitaru Alpha')
    expect(planned.get('[data-testid="chain-planned-structure"]').text()).toContain('Azbel Beta')
    expect(planned.find('[data-testid="chain-structure-warning"]').exists()).toBe(false)
    expect(outside.get('[data-testid="chain-planned-structure"]').text()).toContain('Sotiyo Gamma')
    expect(outside.get('[data-testid="chain-structure-warning"]').text()).toContain(label('en', 'structure.outsideFavoriteSystem'))
    expect(deleted.text()).toContain(label('en', 'structure.deleted'))
    expect(unplanned.text()).toContain(label('en', 'structure.unknown'))
  })

  it.each<TestLocale>(['fr', 'en'])('truncates a very long type name in the overview and in the tree with its full name as title in %s', async (locale) => {
    const wrapper = await mountChain([chainNode({
      id: 'node-long-name',
      stage: 'component',
      typeName: LONG_TYPE_NAME,
      activity: 'manufacturing',
      runs: 3,
      status: 'todo',
    })], locale)

    const inOverview = wrapper.get('[data-testid="chain-node-node-long-name"]').get(`[title="${LONG_TYPE_NAME}"]`)
    const overviewTruncated = inOverview.classes().includes('truncate')
    const treeTab = wrapper.get('[data-testid="chain-view-tree"]').text()
    await openTree(wrapper)
    const inTree = row(wrapper, 'node-long-name').get(`[title="${LONG_TYPE_NAME}"]`)

    expect(LONG_TYPE_NAME.length).toBeGreaterThan(80)
    expect(overviewTruncated).toBe(true)
    expect(inTree.classes()).toContain('truncate')
    expect(treeTab).toBe(label(locale, 'views.tree'))
    expect(wrapper.get('[data-testid="chain-tree"] thead').text()).toContain(label(locale, 'columns.realCost'))
  })
})

describe('ProductionChain loading and failure', () => {
  it('shows the loading state while the chain is read, then the error and no chain when the read fails', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    setActivePinia(createPinia())
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
    const wrapper = mount(ProductionChain, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    const whileLoading = {
      loading: wrapper.find('[data-testid="chain-loading"]').text(),
      flow: wrapper.find('[data-testid="chain-flow"]').exists(),
    }
    pending.reject(new Error('Chain unavailable: ESI jobs could not be read'))
    await flushPromises()

    expect(whileLoading).toEqual({ loading: label('en', 'loading'), flow: false })
    expect(wrapper.find('[data-testid="chain-loading"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="chain-error"]').text()).toContain('Chain unavailable: ESI jobs could not be read')
    expect(wrapper.find('[data-testid="chain-flow"]').exists()).toBe(false)
  })
})
