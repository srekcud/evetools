import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import type { BlueprintLibraryEntry } from '@/stores/industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type SearchedProduct = { typeId: number; typeName: string; isT2: boolean }

const DRAKE: SearchedProduct = { typeId: 24698, typeName: 'Drake', isT2: false }
const SABRE: SearchedProduct = { typeId: 22456, typeName: 'Sabre', isT2: false }
const DEFAULT_MATERIAL_EFFICIENCY = '2'
const DEFAULT_TIME_EFFICIENCY = '4'
const LIBRARY_ENDPOINT = '/industry/blueprints'
const CREATION_ENDPOINT = '/industry/projects'

function ownedBlueprint(overrides: Partial<BlueprintLibraryEntry>): BlueprintLibraryEntry {
  return {
    typeId: 24697,
    name: 'Drake Blueprint',
    kind: 'bpo',
    me: 10,
    te: 20,
    runs: null,
    scope: 'personal',
    ownerName: 'Pilot',
    locationId: 60003760,
    divisionNumber: null,
    divisionName: null,
    count: 1,
    usedByRunningJob: false,
    productTypeId: DRAKE.typeId,
    productName: 'Drake',
    ...overrides,
  }
}

function answerWithLibrary(blueprints: BlueprintLibraryEntry[]) {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === LIBRARY_ENDPOINT) return Promise.resolve({ corporationState: 'ready', blueprints })
    if (endpoint === '/industry/project-references') return Promise.resolve({ rows: [] })
    return Promise.resolve({ id: 'group-project-created' })
  })
}

function mountModal(): VueWrapper {
  setActivePinia(createPinia())
  return mount(NewProjectModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
    props: { show: true },
  })
}

async function select(wrapper: VueWrapper, product: SearchedProduct) {
  const vm = wrapper.vm as unknown as { selectSearchResult: (result: SearchedProduct) => void }
  vm.selectSearchResult(product)
  await flushPromises()
}

function levelInput(wrapper: VueWrapper, title: 'ME' | 'TE'): HTMLInputElement {
  return present(wrapper.find(`input[title="${title}"]`).element as HTMLInputElement | null)
}

async function clickButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  await present(button).trigger('click')
}

const sourceBadge = (typeId: number): string => `[data-testid="item-blueprint-source-${typeId}"]`

function createdItems(): Array<{ typeId: number; meLevel: number; teLevel: number }> {
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === CREATION_ENDPOINT)
  expect(creationCall, 'project creation request').toBeDefined()
  const body = JSON.parse(String(present(present(creationCall)[1]).body)) as { items: Array<{ typeId: number; meLevel: number; teLevel: number }> }
  return body.items
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  answerWithLibrary([])
})

describe('NewProjectModal ME/TE of an item added by hand (issue 1188)', () => {
  it('pre-fills ME and TE from the owned blueprint of the selected product', async () => {
    answerWithLibrary([ownedBlueprint({ me: 8, te: 16 })])
    const wrapper = mountModal()

    await select(wrapper, DRAKE)

    expect(levelInput(wrapper, 'ME').value).toBe('8')
    expect(levelInput(wrapper, 'TE').value).toBe('16')
  })

  it('marks the added line as coming from an owned blueprint and sends its ME and TE', async () => {
    answerWithLibrary([ownedBlueprint({ me: 8, te: 16 })])
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await clickButton(wrapper, 'Add to list')
    await clickButton(wrapper, 'Create Project')
    await flushPromises()

    expect(wrapper.find(sourceBadge(DRAKE.typeId)).attributes('data-blueprint-source')).toBe('owned')
    expect(createdItems()).toEqual([expect.objectContaining({ typeId: DRAKE.typeId, meLevel: 8, teLevel: 16 })])
  })

  it('takes the best owned blueprint: an original over a better copy', async () => {
    answerWithLibrary([
      ownedBlueprint({ kind: 'bpc', me: 10, te: 20, runs: 50 }),
      ownedBlueprint({ kind: 'bpo', me: 4, te: 8 }),
    ])
    const wrapper = mountModal()

    await select(wrapper, DRAKE)

    expect(levelInput(wrapper, 'ME').value).toBe('4')
    expect(levelInput(wrapper, 'TE').value).toBe('8')
  })

  it('keeps ME 2 and TE 4 with the assumed mark when no blueprint is owned for the product', async () => {
    answerWithLibrary([ownedBlueprint({ productTypeId: SABRE.typeId, me: 9, te: 18 })])
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await clickButton(wrapper, 'Add to list')

    expect(wrapper.find(sourceBadge(DRAKE.typeId)).attributes('data-blueprint-source')).toBe('assumed')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(levelInput(wrapper, 'ME').value).toBe(DEFAULT_MATERIAL_EFFICIENCY)
    expect(levelInput(wrapper, 'TE').value).toBe(DEFAULT_TIME_EFFICIENCY)
  })

  it('does not carry the levels of an owned blueprint over to the next product selected', async () => {
    answerWithLibrary([ownedBlueprint({ me: 8, te: 16 })])
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await select(wrapper, SABRE)

    expect(levelInput(wrapper, 'ME').value).toBe(DEFAULT_MATERIAL_EFFICIENCY)
    expect(levelInput(wrapper, 'TE').value).toBe(DEFAULT_TIME_EFFICIENCY)
  })

  it('keeps the defaults without any mark when the blueprint library cannot be read', async () => {
    vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
      if (endpoint === LIBRARY_ENDPOINT) return Promise.reject(new Error('library unavailable'))
      return Promise.resolve({ rows: [] })
    })
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await clickButton(wrapper, 'Add to list')

    expect(levelInput(wrapper, 'ME').value).toBe(DEFAULT_MATERIAL_EFFICIENCY)
    expect(wrapper.find(sourceBadge(DRAKE.typeId)).exists()).toBe(false)
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })

  it('keeps the level typed by the player and drops the owned mark', async () => {
    answerWithLibrary([ownedBlueprint({ me: 8, te: 16 })])
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await wrapper.find('input[title="ME"]').setValue('5')
    await clickButton(wrapper, 'Add to list')

    expect(wrapper.find(sourceBadge(DRAKE.typeId)).exists()).toBe(false)
    expect((wrapper.find('input[aria-label="Drake ME"]').element as HTMLInputElement).value).toBe('5')
  })

  it('does not overwrite a level typed while the library was still loading', async () => {
    let releaseLibrary: (library: unknown) => void = () => undefined
    vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
      if (endpoint === LIBRARY_ENDPOINT) return new Promise(resolve => { releaseLibrary = resolve })
      return Promise.resolve({ rows: [] })
    })
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await wrapper.find('input[title="ME"]').setValue('6')
    releaseLibrary({ corporationState: 'ready', blueprints: [ownedBlueprint({ me: 8, te: 16 })] })
    await flushPromises()

    expect(levelInput(wrapper, 'ME').value).toBe('6')
  })
})

describe('NewProjectModal creating with a selected product not yet added (issue 1188)', () => {
  it('adds the selected product to the request when Create is clicked without Add to list', async () => {
    answerWithLibrary([ownedBlueprint({ me: 8, te: 16 })])
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await clickButton(wrapper, 'Create Project')
    await flushPromises()

    expect(createdItems()).toEqual([expect.objectContaining({ typeId: DRAKE.typeId, meLevel: 8, teLevel: 16 })])
  })

  it('does not duplicate a product already added to the list', async () => {
    const wrapper = mountModal()

    await select(wrapper, DRAKE)
    await clickButton(wrapper, 'Add to list')
    await clickButton(wrapper, 'Create Project')
    await flushPromises()

    expect(createdItems()).toHaveLength(1)
  })

  it('enables Create for a selected product even before it is added', async () => {
    const wrapper = mountModal()

    await select(wrapper, DRAKE)

    const createButton = present(wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project'))
    expect(createButton.attributes('disabled')).toBeUndefined()
  })

  it('keeps Create disabled with no product selected and none in the list', async () => {
    const wrapper = mountModal()
    await flushPromises()

    const createButton = present(wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project'))
    expect(createButton.attributes('disabled')).toBeDefined()
  })
})
