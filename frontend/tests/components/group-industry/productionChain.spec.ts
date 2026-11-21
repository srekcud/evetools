import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { ChainNode, JobLocation, JobLocations } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'
import { messageOf, type TestLocale } from '../../localeTexts'
import {
  chainNode,
  HAMMERHEAD_I,
  HAMMERHEAD_I_COPY,
  HAMMERHEAD_I_COPY_ID,
  HAMMERHEAD_I_ID,
  HAMMERHEAD_II_ID,
  HAMMERHEAD_II_INVENTION,
  HAMMERHEAD_II_INVENTION_ID,
  PARTICLE_ACCELERATOR_UNIT,
  PARTICLE_ACCELERATOR_UNIT_ID,
  PRODUCTION_CHAIN,
  SALE,
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
  runs: 1,
  character: 2,
  status: 3,
  realCost: 4,
  inOut: 5,
  structure: 6,
  reservation: 7,
} as const

const MERGED_PART_TEST_ID = {
  activity: 'chain-activity',
  runs: 'chain-runs',
  meTe: 'chain-me-te',
  statusChip: 'chain-status',
  end: 'chain-end',
} as const

const MERGED_PART_COLUMN: Record<keyof typeof MERGED_PART_TEST_ID, keyof typeof COLUMN> = {
  activity: 'element',
  runs: 'runs',
  meTe: 'runs',
  statusChip: 'status',
  end: 'status',
}

function cellsOf(wrapper: VueWrapper, nodeId: string): DOMWrapper<Element>[] {
  return row(wrapper, nodeId).findAll('td')
}

function cell(wrapper: VueWrapper, nodeId: string, column: keyof typeof COLUMN): FoundElement {
  const found = cellsOf(wrapper, nodeId)[COLUMN[column]]
  if (found === undefined) throw new Error(`no ${column} cell on ${nodeId}`)
  return found
}

function part(wrapper: VueWrapper, nodeId: string, name: keyof typeof MERGED_PART_TEST_ID): FoundElement {
  return cell(wrapper, nodeId, MERGED_PART_COLUMN[name]).get(`[data-testid="${MERGED_PART_TEST_ID[name]}"]`)
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

const PROJECT_CONTAINER_TAG = 'Project container'
const INPUT_OUTSIDE_FLAG = 'Input outside the project container: the job probably belongs to another project'

function sideOf(status: JobLocation['status'], overrides: Partial<JobLocation> = {}): JobLocation {
  return { locationId: 1_049_000_000_001, kind: 'container', name: 'ISHTAR-319', divisionName: null, status, unknownReason: null, ...overrides }
}

function locationsOf(blueprint: JobLocation, output: JobLocation): JobLocations {
  return { blueprint, output }
}

function jobWith(esiJobId: number, locations?: JobLocations | null) {
  const job = { esiJobId, status: 'active', runs: 10, characterName: 'Wenzel Roth', endDate: null, cost: null, facilityName: null }
  return locations === undefined ? job : { ...job, locations }
}

function jobNode(id: string, jobs: ReturnType<typeof jobWith>[]): ChainNode {
  return chainNode({ id, stage: 'component', level: 1, typeName: id, activity: 'manufacturing', runs: 10, status: 'todo', jobs })
}

function ioEntries(wrapper: VueWrapper, nodeId: string): DOMWrapper<Element>[] {
  return cell(wrapper, nodeId, 'inOut').findAll('[data-testid="chain-job-io"]')
}

const scrollIntoView = vi.fn()

function scrolledRowIds(): (string | undefined)[] {
  return scrollIntoView.mock.contexts.map(scrolled => (scrolled as Element).getAttribute('data-testid') ?? undefined)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  scrollIntoView.mockReset()
  Element.prototype.scrollIntoView = scrollIntoView
})

describe('ProductionChain tree with the null fields omitted by the API', () => {
  const OMITTED_NULL_FIELDS = ['parentId', 'activity', 'runs', 'meLevel', 'teLevel', 'realCost', 'plannedStructure', 'plannedCopyDuration', 'soldQuantity', 'grossRevenue', 'mark'] as const

  function withoutNullFields(node: ChainNode): ChainNode {
    const sent: Record<string, unknown> = { ...node }
    for (const field of OMITTED_NULL_FIELDS) {
      if (sent[field] === null) delete sent[field]
    }
    return sent as ChainNode
  }

  it('lists a job whose nullable fields are omitted without any undefined text and leaves the omitted-null material out of the job list', async () => {
    const wrapper = await mountChain([withoutNullFields(TRITANIUM), withoutNullFields(HAMMERHEAD_I_COPY), withoutNullFields(SALE)])

    await openTree(wrapper)

    expect(shownRowIds(wrapper)).toEqual([HAMMERHEAD_I_COPY_ID])
    expect(row(wrapper, HAMMERHEAD_I_COPY_ID).text()).not.toContain('undefined')
  })

  it('shows no NaN progress on the sale node of the overview', async () => {
    const wrapper = await mountChain([withoutNullFields(TRITANIUM), withoutNullFields(SALE)])

    const sale = wrapper.get(`[data-testid="chain-node-${SALE_ID}"]`)

    expect(sale.html()).not.toContain('NaN')
  })
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
      inventionMaterials: [HAMMERHEAD_II_INVENTION_ID, HAMMERHEAD_I_COPY_ID],
      components: [HAMMERHEAD_I_ID],
      product: [HAMMERHEAD_II_ID],
      sale: [SALE_ID],
    })
    expect(wrapper.get('[data-testid="chain-column-inventionMaterials"]').get('[data-testid="chain-materials-summary"]').text()).toContain('1 / 2 material lines supplied')
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
    expect(scrollIntoView).toHaveBeenCalledTimes(1)
    expect(scrolledRowIds()).toEqual([`chain-row-${HAMMERHEAD_I_ID}`])
  })

  it('offers no node for a material in the overview, whose job list does not show it either', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)

    const materialNodes = [TRITANIUM_ID, PARTICLE_ACCELERATOR_UNIT_ID].filter(id => wrapper.find(`[data-testid="chain-node-${id}"]`).exists())
    await openTree(wrapper)

    expect({
      materialNodes,
      materialRows: [TRITANIUM_ID, PARTICLE_ACCELERATOR_UNIT_ID].filter(id => wrapper.find(`[data-testid="chain-row-${id}"]`).exists()),
      selected: wrapper.findAll('[aria-selected="true"]').length,
    }).toEqual({ materialNodes: [], materialRows: [], selected: 0 })
    expect(scrollIntoView).not.toHaveBeenCalled()
  })
})

describe('ProductionChain scroll to the opened node', () => {
  const scrollIntoView = vi.fn()

  beforeEach(() => {
    scrollIntoView.mockReset()
    Element.prototype.scrollIntoView = scrollIntoView
  })

  it('centers the row of the node clicked in the overview once the job tree is shown', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)

    await wrapper.get(`[data-testid="chain-node-${HAMMERHEAD_I_ID}"]`).trigger('click')
    await flushPromises()

    expect(scrollIntoView).toHaveBeenCalledTimes(1)
    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'center' })
    expect(scrollIntoView.mock.contexts[0]).toBe(row(wrapper, HAMMERHEAD_I_ID).element)
  })

  it('scrolls again to another node clicked later from the overview', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await wrapper.get(`[data-testid="chain-node-${HAMMERHEAD_I_ID}"]`).trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="chain-view-flow"]').trigger('click')
    scrollIntoView.mockClear()

    await wrapper.get(`[data-testid="chain-node-${HAMMERHEAD_II_ID}"]`).trigger('click')
    await flushPromises()

    expect(scrollIntoView).toHaveBeenCalledTimes(1)
    expect(scrollIntoView.mock.contexts[0]).toBe(row(wrapper, HAMMERHEAD_II_ID).element)
  })

  it('scrolls again when the same node is clicked a second time from the overview', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await wrapper.get(`[data-testid="chain-node-${HAMMERHEAD_I_ID}"]`).trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="chain-view-flow"]').trigger('click')
    scrollIntoView.mockClear()

    await wrapper.get(`[data-testid="chain-node-${HAMMERHEAD_I_ID}"]`).trigger('click')
    await flushPromises()

    expect(scrollIntoView).toHaveBeenCalledTimes(1)
    expect(scrollIntoView.mock.contexts[0]).toBe(row(wrapper, HAMMERHEAD_I_ID).element)
  })

  it('does not scroll when the job tree is opened from the view switch without a selected node', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)

    await openTree(wrapper)
    await flushPromises()

    expect(scrollIntoView).not.toHaveBeenCalled()
  })
})

describe('ProductionChain job tree', () => {
  it('lists the columns of the job tree and fills them for a job with delivered ESI jobs, its real cost and its structure', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const headers = wrapper.findAll('[data-testid="chain-tree"] thead th').map(header => header.text())
    const cellsCount = cellsOf(wrapper, HAMMERHEAD_I_ID).length
    const hammerheadI = (column: keyof typeof COLUMN) => cell(wrapper, HAMMERHEAD_I_ID, column)

    expect(headers).toEqual([
      ...['element', 'runs', 'character', 'status', 'realCost', 'inOut', 'structure'].map(column => label('en', `columns.${column}`)),
      en.groupIndustry.reservation.reservedBy,
    ])
    expect(cellsCount).toBe(8)
    expect(hammerheadI('element').text()).toContain('Hammerhead I')
    expect(part(wrapper, HAMMERHEAD_I_ID, 'activity').text()).toBe(label('en', 'activity.manufacturing'))
    expect(part(wrapper, HAMMERHEAD_I_ID, 'runs').text()).toBe('50')
    expect(part(wrapper, HAMMERHEAD_I_ID, 'meTe').text()).toBe(label('en', 'meTeValue').replace('{me}', '10').replace('{te}', '20'))
    expect(hammerheadI('character').text()).toBe('Wenzel Roth')
    expect(part(wrapper, HAMMERHEAD_I_ID, 'statusChip').text()).toBe(label('en', 'status.done'))
    expect(hammerheadI('realCost').text()).toBe('214.00 K')
    expect(part(wrapper, HAMMERHEAD_I_ID, 'end').text()).toContain('03/11')
    expect(hammerheadI('structure').text()).toContain('Raitaru Alpha')
  })

  it('fills the input and output cell of a job with the names and statuses of its locations and tags the sides inside the project container', async () => {
    const wrapper = await mountChain([jobNode('job-ishtar', [jobWith(920_001, locationsOf(sideOf('inside'), sideOf('inside')))])])
    await openTree(wrapper)

    const entries = ioEntries(wrapper, 'job-ishtar')
    const input = entries[0]?.get('[data-testid="chain-job-input"]')
    const output = entries[0]?.get('[data-testid="chain-job-output"]')

    expect(entries).toHaveLength(1)
    expect(input?.attributes('data-status')).toBe('inside')
    expect(input?.text()).toContain('ISHTAR-319')
    expect(input?.text()).toContain(PROJECT_CONTAINER_TAG)
    expect(output?.attributes('data-status')).toBe('inside')
    expect(output?.text()).toContain('ISHTAR-319')
    expect(output?.text()).toContain(PROJECT_CONTAINER_TAG)
    expect(entries[0]?.get('[data-testid="chain-job-check"]').attributes('data-check')).toBe('ok')
    expect(entries[0]?.find('[data-testid="chain-job-input-outside"]').exists()).toBe(false)
  })

  it('flags, on each job of a node and in the order of the jobs, an input outside the project container whether it sits in another container or in a hangar', async () => {
    const wrapper = await mountChain([jobNode('job-ishtar', [
      jobWith(920_001, locationsOf(sideOf('outside', { name: 'Perso' }), sideOf('outside', { name: 'Perso' }))),
      jobWith(920_002, locationsOf(sideOf('outside', { kind: 'hangar', name: 'Jita 4-4', divisionName: 'Hangar 3' }), sideOf('inside'))),
      jobWith(920_003, locationsOf(sideOf('inside'), sideOf('inside'))),
    ])])
    await openTree(wrapper)

    const entries = ioEntries(wrapper, 'job-ishtar')
    const flagsOf = (index: number) => entries[index]?.findAll('[data-testid="chain-job-input-outside"]') ?? []

    expect(entries.map(entry => entry.get('[data-testid="chain-job-input"]').text())).toEqual([
      expect.stringContaining('Perso'),
      expect.stringContaining('Jita 4-4'),
      expect.stringContaining('ISHTAR-319'),
    ])
    expect(flagsOf(0)).toHaveLength(1)
    expect(flagsOf(0)[0]?.text()).toContain(INPUT_OUTSIDE_FLAG)
    expect(flagsOf(1)).toHaveLength(1)
    expect(flagsOf(1)[0]?.text()).toContain(INPUT_OUTSIDE_FLAG)
    expect(flagsOf(2)).toHaveLength(0)
  })

  it('keeps the output check on a job whose output is outside and flags no input when the input is unknown', async () => {
    const wrapper = await mountChain([jobNode('job-ishtar', [
      jobWith(920_001, locationsOf(sideOf('unknown', { name: null, unknownReason: 'assets_not_synced' }), sideOf('outside', { name: 'Perso' }))),
    ])])
    await openTree(wrapper)

    const entries = ioEntries(wrapper, 'job-ishtar')
    const entry = entries[0]

    expect(entries).toHaveLength(1)
    expect(entry?.get('[data-testid="chain-job-check"]').attributes('data-check')).toBe('output-outside')
    expect(entry?.find('[data-testid="chain-job-input-outside"]').exists()).toBe(false)
  })

  it('names an unknown location as unknown and shows the cause of the unknown sides once', async () => {
    const unknownSide = sideOf('unknown', { name: null, unknownReason: 'assets_not_synced' })
    const wrapper = await mountChain([jobNode('job-ishtar', [jobWith(920_001, locationsOf(unknownSide, unknownSide))])])
    await openTree(wrapper)

    const entries = ioEntries(wrapper, 'job-ishtar')
    const entry = entries[0]
    const reasons = cell(wrapper, 'job-ishtar', 'inOut').findAll('[data-testid="chain-job-io-reason"]')

    expect(entries).toHaveLength(1)
    expect(entry?.get('[data-testid="chain-job-input"]').text()).toContain(messageOf('en', 'groupIndustry.jobLocations.unknownLocation'))
    expect(entry?.get('[data-testid="chain-job-input"]').attributes('data-status')).toBe('unknown')
    expect(entry?.get('[data-testid="chain-job-output"]').text()).toContain(messageOf('en', 'groupIndustry.jobLocations.unknownLocation'))
    expect(entry?.get('[data-testid="chain-job-output"]').attributes('data-status')).toBe('unknown')
    expect(reasons.map(reason => reason.text())).toEqual([messageOf('en', 'groupIndustry.jobLocations.reasons.assets_not_synced')])
  })

  it('shows nothing in the input and output cell of a job whose locations are null or absent and of a node without job', async () => {
    const wrapper = await mountChain([
      jobNode('job-locations-null', [jobWith(920_001, null)]),
      jobNode('job-locations-absent', [jobWith(920_002)]),
      jobNode('job-not-launched', []),
    ])
    await openTree(wrapper)

    for (const nodeId of ['job-locations-null', 'job-locations-absent', 'job-not-launched']) {
      expect(cell(wrapper, nodeId, 'inOut').text(), nodeId).toBe('')
      expect(cell(wrapper, nodeId, 'inOut').find('[data-testid="chain-io-unknown"]').exists(), nodeId).toBe(false)
    }
  })

  it('no longer shows the static cause note in the tree nor keeps its translation', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    expect(wrapper.find('[data-testid="chain-io-unknown-cause"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="chain-io-unknown"]').exists()).toBe(false)
    expect((en.groupIndustry.chain as Record<string, unknown>).ioUnknownCause).toBeUndefined()
    expect((fr.groupIndustry.chain as Record<string, unknown>).ioUnknownCause).toBeUndefined()
  })

  it('keeps the structure names in a wrapper bounded by the column, each name truncated on one line with its full name as title', async () => {
    const longStructureName = '1DQ1-A - 1-st Thetastar of Dickbutt Engineering Complex'
    const plannedStructureName = '4-HWWF - Warpgate Assembly Sotiyo of the Northern Fleet Reserve'
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
      jobWith('job-launched', { structures: [longStructureName] }),
      jobWith('job-planned', { plannedStructure: { name: plannedStructureName, status: 'best_outside_favorite_system' } }),
    ])
    await openTree(wrapper)

    const launched = cell(wrapper, 'job-launched', 'structure')
    const planned = cell(wrapper, 'job-planned', 'structure')
    const launchedName = launched.get(`[title="${longStructureName}"]`)
    const plannedName = planned.get(`[title="${plannedStructureName}"]`)

    expect(launched.get('div').classes()).toContain('min-w-0')
    expect(launched.get('div').element.contains(launchedName.element)).toBe(true)
    expect(launchedName.classes()).toContain('truncate')
    expect(launchedName.text()).toBe(longStructureName)
    expect(planned.get('div').classes()).toContain('min-w-0')
    expect(plannedName.classes()).toContain('truncate')
    expect(plannedName.text()).toBe(plannedStructureName)
    expect(planned.get('[data-testid="chain-structure-warning"]').classes()).toContain('whitespace-normal')
  })

  it('lists only the jobs, ordered by level from the lowest, with the blueprint of a type just before it and no material nor sale row', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    expect(shownRowIds(wrapper)).toEqual([
      HAMMERHEAD_I_COPY_ID,
      HAMMERHEAD_I_ID,
      HAMMERHEAD_II_INVENTION_ID,
      HAMMERHEAD_II_ID,
    ])
  })

  it('puts a header before each level with its number and its job count, in the order of the levels', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const headers = wrapper.findAll('[data-testid^="chain-level-"]')
    const order = wrapper.findAll('[data-testid^="chain-level-"], [data-testid^="chain-row-"]').map(shown => shown.attributes('data-testid'))

    expect(headers.map(header => header.attributes('data-testid'))).toEqual(['chain-level-1', 'chain-level-2'])
    expect(headers[0]?.text()).toContain(label('en', 'level.title').replace('{level}', '1'))
    expect(headers[0]?.text()).toContain('2')
    expect(headers[1]?.text()).toContain(label('en', 'level.title').replace('{level}', '2'))
    expect(order).toEqual([
      'chain-level-1',
      `chain-row-${HAMMERHEAD_I_COPY_ID}`,
      `chain-row-${HAMMERHEAD_I_ID}`,
      'chain-level-2',
      `chain-row-${HAMMERHEAD_II_INVENTION_ID}`,
      `chain-row-${HAMMERHEAD_II_ID}`,
    ])
  })

  it('shows a type once, at the level the API gives it, whatever the order of the nodes and the number of branches that use it', async () => {
    const job = (id: string, level: number | null, parentId: string | null): ChainNode => chainNode({
      id,
      stage: 'component',
      typeName: id,
      activity: 'manufacturing',
      runs: 1,
      level,
      parentId,
    })
    const wrapper = await mountChain([
      job('product', 3, null),
      job('branch', 2, 'product'),
      job('shared', 1, 'branch'),
      job('other-shared', 1, 'product'),
    ])
    await openTree(wrapper)

    expect(shownRowIds(wrapper)).toEqual(['other-shared', 'shared', 'branch', 'product'])
  })

  it('puts the jobs without a known level last, under their own header with the cause, never at a default level', async () => {
    const wrapper = await mountChain([
      chainNode({ ...HAMMERHEAD_I, id: 'node-unleveled', typeName: 'Unleveled job', level: null }),
      HAMMERHEAD_I,
    ])
    await openTree(wrapper)

    const order = wrapper.findAll('[data-testid^="chain-level-"], [data-testid^="chain-row-"]').map(shown => shown.attributes('data-testid'))

    expect(order).toEqual(['chain-level-1', `chain-row-${HAMMERHEAD_I_ID}`, 'chain-level-unknown', 'chain-row-node-unleveled'])
    expect(wrapper.get('[data-testid="chain-level-unknown"]').text()).toContain(label('en', 'level.unknown'))
    expect(wrapper.get('[data-testid="chain-level-unknown"]').text()).toContain(label('en', 'level.unknownCause'))
  })

  it('draws a flat list: no fold button and no indentation on any row', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    expect(wrapper.findAll('[data-testid^="chain-toggle-"]')).toEqual([])
    for (const rowId of shownRowIds(wrapper)) {
      expect(cell(wrapper, rowId, 'element').html()).not.toContain('padding-left')
    }
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

    const labels = statuses.map(status => part(wrapper, `node-${status}`, 'statusChip').text())

    expect(labels).toEqual(statuses.map(status => label('en', `status.${status}`)))
  })

  it('shows a known real cost, a dash when nothing is paid and an unknown cost with its cause and its suppliers, never 0', async () => {
    const wrapper = await mountChain([
      HAMMERHEAD_I,
      chainNode({ ...TRITANIUM, stage: 'component', activity: 'manufacturing', runs: 1, level: 1 }),
      chainNode({ ...PARTICLE_ACCELERATOR_UNIT, stage: 'component', activity: 'manufacturing', runs: 1, level: 1 }),
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

describe('ProductionChain job tree fits the page width', () => {
  const FORCED_WIDTH_CLASSES = ['w-48', 'max-w-48', 'max-w-56', 'max-w-64']
  const MINIMUM_RESERVATION_SHARE = 12

  function widthShareOf(column: DOMWrapper<Element>): number {
    return Number.parseFloat(column.attributes('style')?.match(/width:\s*([\d.]+)%/)?.[1] ?? 'NaN')
  }

  it('lays the table out with fixed columns that add up to the page width, without a horizontal scroll wrapper', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const tree = wrapper.get('[data-testid="chain-tree"]')
    const columns = tree.findAll('table colgroup col')
    const shares = columns.map(widthShareOf)

    expect(tree.classes()).not.toContain('overflow-x-auto')
    expect(tree.get('table').classes()).toContain('table-fixed')
    expect(columns).toHaveLength(tree.findAll('thead th').length)
    expect(shares.every(share => Number.isFinite(share))).toBe(true)
    expect(shares.reduce((total, share) => total + share, 0)).toBeCloseTo(100, 5)
  })

  it('gives the reservation column enough width for the reserve and contribute controls', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const shares = wrapper.findAll('[data-testid="chain-tree"] table colgroup col').map(widthShareOf)

    expect(shares).not.toHaveLength(0)
    expect(shares[shares.length - 1]).toBeGreaterThanOrEqual(MINIMUM_RESERVATION_SHARE)
  })

  it('lets cells wrap instead of forcing a width or a single line, long product names included', async () => {
    const wrapper = await mountChain([...PRODUCTION_CHAIN, chainNode({
      id: 'node-long-name',
      stage: 'product',
      level: 1,
      typeName: LONG_TYPE_NAME,
      activity: 'manufacturing',
      runs: 3,
      status: 'todo',
    })])
    await openTree(wrapper)

    const tree = wrapper.get('[data-testid="chain-tree"]')
    const classesOfCells = tree.findAll('td, th').flatMap(element => element.classes())
    const classesInTree = tree.findAll('*').flatMap(element => element.classes())

    expect(classesOfCells).not.toContain('whitespace-nowrap')
    expect(FORCED_WIDTH_CLASSES.filter(forced => classesInTree.includes(forced))).toEqual([])
    expect(row(wrapper, 'node-long-name').get(`[title="${LONG_TYPE_NAME}"]`).classes()).toContain('truncate')
  })
})

describe('ProductionChain job tree keeps every column inside the page width', () => {
  const MAXIMUM_COLUMNS = 8

  it('merges the activity into the element cell, the ME and TE into the runs cell and the end into the status cell', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const tree = wrapper.get('[data-testid="chain-tree"]')

    expect(tree.findAll('thead th').length).toBeLessThanOrEqual(MAXIMUM_COLUMNS)
    expect(cell(wrapper, HAMMERHEAD_I_ID, 'element').find('[data-testid="chain-activity"]').exists()).toBe(true)
    expect(cell(wrapper, HAMMERHEAD_I_ID, 'runs').find('[data-testid="chain-me-te"]').exists()).toBe(true)
    expect(cell(wrapper, HAMMERHEAD_I_ID, 'status').find('[data-testid="chain-end"]').exists()).toBe(true)
    expect(tree.findAll('thead th').map(header => header.text())).not.toContain(label('en', 'columns.activity'))
    expect(tree.findAll('thead th').map(header => header.text())).not.toContain(label('en', 'columns.end'))
  })

  it('keeps the activity of a job without runs, the ME and TE of a copy and the end of a job still to do in their own cells, never blank labels', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    expect(part(wrapper, HAMMERHEAD_I_COPY_ID, 'activity').text()).toBe(label('en', 'activity.copy'))
    expect(part(wrapper, HAMMERHEAD_I_COPY_ID, 'meTe').text()).toBe('')
    expect(part(wrapper, HAMMERHEAD_II_INVENTION_ID, 'end').text()).toBe(label('en', 'jobNotSeenHint'))
  })

  it('uses a compact cell padding so that eight columns hold their content at a 1366 px screen', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN)
    await openTree(wrapper)

    const tree = wrapper.get('[data-testid="chain-tree"]')
    const classesOfCells = tree.findAll('td, th').filter(element => element.attributes('colspan') === undefined).flatMap(element => element.classes())

    expect(classesOfCells).toContain('px-2')
    expect(classesOfCells).not.toContain('px-3')
  })
})

describe('ProductionChain end of a copy job', () => {
  const COPY_WITHOUT_JOB_ID = 'node-ishtar-copy'
  const copyWith = (id: string, overrides: Partial<ChainNode>): ChainNode => chainNode({
    id,
    stage: 'blueprint',
    level: 1,
    typeName: `Ishtar Blueprint ${id}`,
    activity: 'copy',
    runs: 600,
    requiredQuantity: 600,
    status: 'todo',
    ...overrides,
  })

  it('shows the planned duration of a copy without linked job with its copier as title, and only the not-seen-yet hint on an invention without job', async () => {
    const wrapper = await mountChain([
      copyWith(COPY_WITHOUT_JOB_ID, { plannedCopyDuration: { seconds: 31_873, missingData: [], characterName: 'Aurelie Vanth' } }),
      chainNode({ ...HAMMERHEAD_II_INVENTION, jobs: [] }),
    ])
    await openTree(wrapper)

    const planned = part(wrapper, COPY_WITHOUT_JOB_ID, 'end').find('[data-testid="chain-planned-copy-duration"]')

    expect(planned.exists()).toBe(true)
    expect(planned.text()).toContain('8h 51m')
    expect(planned.attributes('title')).toContain('Aurelie Vanth')
    expect(planned.text()).toBe(label('en', 'plannedCopyDuration.planned').replace('{duration}', '8h 51m'))
    expect(part(wrapper, HAMMERHEAD_II_INVENTION_ID, 'end').text()).toBe(label('en', 'jobNotSeenHint'))
  })

  it('shows an unknown planned duration with its own cause on each copy, never a zero duration', async () => {
    const reasons = ['no_copy_structure', 'planned_structure_deleted', 'copy_skills', 'copy_structure_not_planned', 'copy_base_time']
    const wrapper = await mountChain(reasons.map(reason => copyWith(`copy-${reason}`, {
      plannedCopyDuration: { seconds: null, missingData: [{ reason, typeId: reason === 'copy_base_time' ? 12_005 : null }], characterName: null },
    })))
    await openTree(wrapper)

    const unknownMarkers = reasons.map(reason => part(wrapper, `copy-${reason}`, 'end').find('[data-testid="chain-planned-copy-duration-unknown"]'))

    expect(unknownMarkers.map(marker => marker.exists())).toEqual(reasons.map(() => true))
    for (const reason of reasons) {
      const shown = part(wrapper, `copy-${reason}`, 'end').text()
      expect(shown).toContain(label('en', 'plannedCopyDuration.unknown'))
      expect(shown).toContain(label('en', `plannedCopyDuration.reason.${reason}`))
      expect(shown, reason).not.toMatch(/\b0s\b/)
    }
  })

  it('shows the ESI end date of a copy with a linked job and not its planned duration', async () => {
    const wrapper = await mountChain([copyWith('copy-launched', {
      status: 'active',
      jobs: [{ esiJobId: 920_001, status: 'active', runs: 600, characterName: 'Aurelie Vanth', endDate: '2026-11-12T18:00:00+00:00', cost: 1_250_000, facilityName: 'Sotiyo Gamma' }],
      plannedCopyDuration: { seconds: 31_873, missingData: [], characterName: 'Aurelie Vanth' },
    })])
    await openTree(wrapper)

    const end = part(wrapper, 'copy-launched', 'end')

    expect(end.text()).toContain('12/11')
    expect(end.find('[data-testid="chain-planned-copy-duration"]').exists()).toBe(false)
    expect(end.text()).not.toContain('8h 51m')
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
