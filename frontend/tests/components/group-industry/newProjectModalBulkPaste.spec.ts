import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECTS_ENDPOINT = '/industry/projects'
const SEARCH_ENDPOINT = '/industry/search'
const LINE_RESULT = '[data-testid="bulk-line-result"]'
const FEROX = { typeId: 16227, typeName: 'Ferox', isT2: false }
const SABRE = { typeId: 22456, typeName: 'Sabre', isT2: false }
const CATALOG = [FEROX, SABRE]

function searchedName(endpoint: string): string {
  return decodeURIComponent(new URL(endpoint, 'http://localhost').searchParams.get('q') ?? '')
}

function mountModal() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint.startsWith(SEARCH_ENDPOINT)) {
      const query = searchedName(endpoint).toLowerCase()
      return { results: CATALOG.filter(product => product.typeName.toLowerCase().startsWith(query.slice(0, 3))) }
    }
    return { id: 'group-project-created' }
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(NewProjectModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true },
  })
}

async function clickButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  await button!.trigger('click')
}

async function pasteAndParse(wrapper: VueWrapper, text: string) {
  await clickButton(wrapper, 'Bulk Paste')
  await wrapper.get('textarea').setValue(text)
  await clickButton(wrapper, 'Parse & Add')
  await flushPromises()
}

function lineResults(wrapper: VueWrapper) {
  return wrapper.findAll(LINE_RESULT).map(row => ({ status: row.attributes('data-status'), text: row.text() }))
}

function onlyLineResult(wrapper: VueWrapper) {
  const results = lineResults(wrapper)
  expect(results).toHaveLength(1)
  return results[0]
}

function sentCreationBody(): Record<string, unknown> {
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === PROJECTS_ENDPOINT)
  expect(creationCall, 'project creation request').toBeDefined()
  return JSON.parse(String(creationCall![1]!.body))
}

describe('NewProjectModal bulk paste results', () => {
  it('reports a recognized line with its item, ME, TE and runs and adds it with its resolved type id', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'Ferox 2 4 x100')

    const result = onlyLineResult(wrapper)
    expect(result.status).toBe('recognized')
    expect(result.text).toContain('Ferox')
    expect(result.text).toContain('ME 2')
    expect(result.text).toContain('TE 4')
    expect(result.text).toContain('100 runs')

    await clickButton(wrapper, 'Create Project')
    await flushPromises()
    expect((sentCreationBody().items as unknown[])).toEqual([
      { typeId: FEROX.typeId, typeName: 'Ferox', meLevel: 2, teLevel: 4, runs: 100 },
    ])
  })

  it('recognizes an item whatever its letter case', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'ferox 2 4 x100')

    expect(lineResults(wrapper).map(result => result.status)).toEqual(['recognized'])
  })

  it('reads lines pasted with Windows line endings', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'Ferox 2 4 x100\r\nSabre 4 8 x20\r\n')

    expect(lineResults(wrapper).map(result => result.status)).toEqual(['recognized', 'recognized'])
  })

  it('reports an unknown item with the suggestion of the manufacturable items and does not add it', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'Ferrox 2 4 x100')

    const result = onlyLineResult(wrapper)
    expect(result.status).toBe('unknown')
    expect(result.text).toContain('Ferrox')
    expect(result.text).toContain('Did you mean Ferox?')
    expect(wrapper.text()).toContain('No items added yet')
  })

  it('adds the suggested item with the efficiencies and runs of the line when the correction is accepted', async () => {
    const wrapper = mountModal()
    await pasteAndParse(wrapper, 'Ferrox 3 6 x40')

    await clickButton(wrapper, 'Correct')
    await flushPromises()

    expect(lineResults(wrapper).map(result => result.status)).toEqual(['recognized'])
    await clickButton(wrapper, 'Create Project')
    await flushPromises()
    expect((sentCreationBody().items as unknown[])).toEqual([
      { typeId: FEROX.typeId, typeName: 'Ferox', meLevel: 3, teLevel: 6, runs: 40 },
    ])
  })

  it('reports an unknown item without correction when nothing looks like it', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'Zzzzzz 2 4 x100')

    const result = onlyLineResult(wrapper)
    expect(result.status).toBe('unknown')
    expect(wrapper.findAll('button').some(button => button.text() === 'Correct')).toBe(false)
  })

  it('reports a line that does not follow the format with the expected format and does not look the item up', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'Ferox lots of runs')

    const result = onlyLineResult(wrapper)
    expect(result.status).toBe('invalid')
    expect(result.text).toContain('Ferox lots of runs')
    expect(result.text).toContain('Expected format: ItemName ME TE xRuns')
    expect(vi.mocked(apiRequest).mock.calls.some(([endpoint]) => endpoint.startsWith(SEARCH_ENDPOINT))).toBe(false)
  })

  it.each([
    ['Ferox 11 4 x100', 'ME must be between 0 and 10'],
    ['Ferox 2 21 x100', 'TE must be between 0 and 20'],
    ['Ferox 2 4 x0', 'Runs must be between 1 and 100000'],
  ])('reports the reason of the line %s', async (line, reason) => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, line)

    const result = onlyLineResult(wrapper)
    expect(result.status).toBe('invalid')
    expect(result.text).toContain(reason)
  })

  it('keeps the recognized lines and leaves only the lines to fix in the text area', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'Ferox 2 4 x100\nFerrox 2 4 x10\nbroken line\nSabre 4 8 x20')

    expect(lineResults(wrapper).map(result => result.status)).toEqual(['recognized', 'unknown', 'invalid', 'recognized'])
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Ferrox 2 4 x10\nbroken line')
  })

  it('empties the text area when every line is recognized', async () => {
    const wrapper = mountModal()

    await pasteAndParse(wrapper, 'Ferox 2 4 x100\nSabre 4 8 x20')

    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('')
  })

  it('shows no line result before anything is pasted', async () => {
    const wrapper = mountModal()

    expect(wrapper.findAll(LINE_RESULT)).toHaveLength(0)
  })
})
