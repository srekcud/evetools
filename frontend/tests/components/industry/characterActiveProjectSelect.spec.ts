import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import CharacterActiveProjectSelect from '@/components/industry/CharacterActiveProjectSelect.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { GroupProject } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECTS_ENDPOINT = '/industry/projects'
const SELECT = '[data-testid="character-active-project-select"]'
const ERROR = '[data-testid="character-active-project-error"]'

const ORCA = { id: 'project-orca', name: 'Orca batch', status: 'in_progress' }
const HECATE = { id: 'project-hecate', name: 'Hecate batch', status: 'selling' }
const RORQUAL = { id: 'project-rorqual', name: 'Rorqual batch', status: 'completed' }
const PROJECTS = [ORCA, HECATE, RORQUAL]

const ARIA = { id: 'uuid-aria', name: 'Aria Solberg', activeProjectId: ORCA.id, activeProjectName: ORCA.name }
const ARIA_ENDPOINT = `/me/characters/${ARIA.id}/active-project`

type Patch = (body: { projectId: string | null }) => Promise<unknown>

function mountSelect(patch: Patch) {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === PROJECTS_ENDPOINT) return PROJECTS
    if (endpoint === ARIA_ENDPOINT) return patch(JSON.parse(String(options?.body)))
    throw new Error(`Unexpected request ${endpoint}`)
  })
  const pinia = createPinia()
  useGroupProjectStore(pinia).myProjects = PROJECTS as unknown as GroupProject[]
  return mount(CharacterActiveProjectSelect, {
    global: { plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { character: ARIA },
  })
}

function patchCalls() {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === ARIA_ENDPOINT)
}

enableAutoUnmount(afterEach)

describe('CharacterActiveProjectSelect', () => {
  it('offers the none option and the open projects, shows the current one and patches the chosen one while disabled', async () => {
    const pending = deferred<unknown>()
    const wrapper = mountSelect(() => pending.promise)
    await flushPromises()
    expect(wrapper.find(SELECT).exists()).toBe(true)
    const select = wrapper.get(SELECT)

    expect(select.findAll('option').map(option => option.text())).toEqual(['No active project', 'Orca batch', 'Hecate batch'])
    expect((select.element as HTMLSelectElement).value).toBe(ORCA.id)

    await select.setValue(HECATE.id)

    expect(patchCalls()).toEqual([[ARIA_ENDPOINT, expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ projectId: HECATE.id }) })]])
    expect(select.attributes('disabled')).toBeDefined()

    const updated = { id: ARIA.id, activeProjectId: HECATE.id, activeProjectName: HECATE.name }
    pending.resolve(updated)
    await flushPromises()

    expect(select.attributes('disabled')).toBeUndefined()
    expect(wrapper.emitted('updated')).toEqual([[updated]])
  })

  it('patches a null project id when the none option is chosen', async () => {
    const cleared = { id: ARIA.id, activeProjectId: null, activeProjectName: null }
    const wrapper = mountSelect(async () => cleared)
    await flushPromises()
    expect(wrapper.find(SELECT).exists()).toBe(true)

    await wrapper.get(SELECT).setValue('')
    await flushPromises()

    expect(patchCalls()).toEqual([[ARIA_ENDPOINT, expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ projectId: null }) })]])
    expect(wrapper.emitted('updated')).toEqual([[cleared]])
  })

  it('shows an error, restores the current project and emits nothing when the patch fails', async () => {
    const wrapper = mountSelect(async () => { throw new Error('Not found') })
    await flushPromises()
    expect(wrapper.find(SELECT).exists()).toBe(true)
    expect(wrapper.find(ERROR).exists()).toBe(false)

    await wrapper.get(SELECT).setValue(HECATE.id)
    await flushPromises()

    expect(wrapper.get(ERROR).text()).not.toBe('')
    expect((wrapper.get(SELECT).element as HTMLSelectElement).value).toBe(ORCA.id)
    expect(wrapper.emitted('updated')).toBeUndefined()
  })
})
